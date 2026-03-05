<?php
// =============================================================================
// presence.php — Présences temps réel via MQTT
// =============================================================================
// Architecture identique à gestion_empreintes.php :
//   - Client MQTT WebSocket JS se connecte au broker (port WS)
//   - Écoute bioaccess/esp32/pointage (empreintes vérifiées par l'ESP32)
//   - Stocke en DB via presence_save.php (endpoint JSON sécurisé)
//   - Règle ENTRÉE/SORTIE : N-ième pointage impair → ENTRÉE, pair → SORTIE
//   - Tableau des entrées/sorties par tranche horaire
// =============================================================================
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = Database::getInstance();
$db       = $database->getConnection();

// Statut broker MQTT
$esp32Online = mqttIsOnline();

// Paramètres & validation
$currentDate      = date('Y-m-d');
$selectedDate     = $_GET['date']     ?? $currentDate;
$selectedEmployee = (int)($_GET['employee'] ?? 0);
$selectedType     = strtoupper(trim($_GET['type'] ?? ''));

if (!DateTime::createFromFormat('Y-m-d', $selectedDate)) $selectedDate = $currentDate;
if (!in_array($selectedType, ['ENTREE', 'SORTIE', ''], true)) $selectedType = '';

// Actions POST
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_in = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf_in)) {
        $message      = "Erreur de sécurité. Veuillez recharger la page.";
        $message_type = "error";
        logError("Token CSRF invalide dans presence.php");
    } else {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'ping_esp32':
                $res = esp32SendCommand("PING");
                if ($res['success'] && str_contains($res['response'], 'PONG')) {
                    $message      = "ESP32 joignable — PONG reçu (" . MQTT_HOST . ":" . MQTT_PORT . ")";
                    $message_type = "success";
                } else {
                    $message      = "ESP32 ne répond pas. " . ($res['error'] ?: $res['response']);
                    $message_type = "error";
                }
                logActivity("Ping ESP32 depuis présence");
                break;
            case 'sync_data':
                $res = esp32SendCommand("SYNC");
                if (!$res['success']) {
                    $message      = "Impossible de joindre l'ESP32 : " . $res['error'];
                    $message_type = "error";
                } else {
                    $stmt = $db->prepare("INSERT INTO sync_log (sync_date, status, details) VALUES (datetime('now'), 'success', ?)");
                    $stmt->bindValue(1, $res['response'], SQLITE3_TEXT);
                    $stmt->execute();
                    $message      = "Synchronisation demandée. Réponse : " . $res['response'];
                    $message_type = "success";
                    logActivity("Sync ESP32 depuis présence");
                }
                break;
        }
        $esp32Online = mqttIsOnline();
    }
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $cWhere = ["DATE(p.datetime) = ?"];
    $cP = [$selectedDate]; $cT = [SQLITE3_TEXT];
    if ($selectedEmployee > 0) { $cWhere[] = "p.employee_id = ?";  $cP[] = $selectedEmployee; $cT[] = SQLITE3_INTEGER; }
    if ($selectedType !== '')  { $cWhere[] = "p.type_pointage = ?"; $cP[] = $selectedType;     $cT[] = SQLITE3_TEXT; }
    $stmtC = $db->prepare("SELECT p.*, e.nom, e.prenom, e.poste FROM pointages p LEFT JOIN employees e ON p.employee_id = e.id WHERE " . implode(' AND ', $cWhere) . " ORDER BY p.datetime ASC");
    foreach ($cP as $i => $v) $stmtC->bindValue($i + 1, $v, $cT[$i]);
    $resC = $stmtC->execute();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=presences_' . $selectedDate . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Nom', 'Prénom', 'Poste', 'Type', 'Date', 'Heure'], ';');
    while ($row = $resC->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($out, [$row['id'], $row['nom'] ?? 'Inconnu', $row['prenom'] ?? '', $row['poste'] ?? '', $row['type_pointage'], date('d/m/Y', strtotime($row['datetime'])), date('H:i:s', strtotime($row['datetime']))], ';');
    }
    fclose($out); exit;
}

// Requête principale
$whereParts = ["DATE(p.datetime) = ?"];
$params     = [$selectedDate];
$types      = [SQLITE3_TEXT];
if ($selectedEmployee > 0) { $whereParts[] = "p.employee_id = ?";  $params[] = $selectedEmployee; $types[] = SQLITE3_INTEGER; }
if ($selectedType !== '')  { $whereParts[] = "p.type_pointage = ?"; $params[] = $selectedType;     $types[] = SQLITE3_TEXT; }
$whereSQL = implode(' AND ', $whereParts);

$stmt = $db->prepare("SELECT p.id, p.employee_id, p.type_pointage, p.datetime, p.created_at, e.nom, e.prenom, e.poste FROM pointages p LEFT JOIN employees e ON p.employee_id = e.id WHERE $whereSQL ORDER BY p.datetime ASC");
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v, $types[$i]);
$pointagesData = [];
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $pointagesData[] = $row;

// Statistiques globales
$stmtS = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN p.type_pointage='ENTREE' THEN 1 ELSE 0 END) as entries, SUM(CASE WHEN p.type_pointage='SORTIE' THEN 1 ELSE 0 END) as exits, COUNT(DISTINCT p.employee_id) as unique_employees FROM pointages p WHERE $whereSQL");
foreach ($params as $i => $v) $stmtS->bindValue($i + 1, $v, $types[$i]);
$stats = $stmtS->execute()->fetchArray(SQLITE3_ASSOC) ?: ['total' => 0, 'entries' => 0, 'exits' => 0, 'unique_employees' => 0];

// ─────────────────────────────────────────────────────────────────────────────
// Tableau Entrées/Sorties par tranche horaire
// Règle : N-ième pointage de l'employé dans la journée
//   N impair → ENTRÉE  |  N pair → SORTIE
// ─────────────────────────────────────────────────────────────────────────────
$stmtAll = $db->prepare("SELECT p.employee_id, p.datetime, e.nom, e.prenom, e.poste FROM pointages p LEFT JOIN employees e ON p.employee_id = e.id WHERE DATE(p.datetime) = ? ORDER BY p.employee_id, p.datetime ASC");
$stmtAll->bindValue(1, $selectedDate, SQLITE3_TEXT);
$allRows = [];
$r = $stmtAll->execute();
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $allRows[] = $row;

$horaire = [];
for ($h = 5; $h <= 22; $h++) $horaire[$h] = ['entrees' => [], 'sorties' => [], 'label' => sprintf('%02dh', $h)];

$empCounters = [];
foreach ($allRows as $row) {
    $empId = (int)$row['employee_id'];
    $heure = (int)date('G', strtotime($row['datetime']));
    $nom   = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? 'Inconnu'));
    $time  = date('H:i', strtotime($row['datetime']));
    if (!isset($empCounters[$empId])) $empCounters[$empId] = 0;
    $empCounters[$empId]++;
    $n = $empCounters[$empId];
    $typeCalcule = ($n % 2 !== 0) ? 'ENTREE' : 'SORTIE';
    $heure = max(5, min(22, $heure));
    $entry = ['nom' => $nom, 'poste' => $row['poste'] ?? '', 'heure' => $time, 'n' => $n, 'id' => $empId];
    if ($typeCalcule === 'ENTREE') $horaire[$heure]['entrees'][] = $entry;
    else                           $horaire[$heure]['sorties'][] = $entry;
}

$horaireActif = array_filter($horaire, fn($h) => !empty($h['entrees']) || !empty($h['sorties']));

// Activité horaire pour le graphique (toutes les heures 5–22)
$hourlyActivity = [];
for ($h = 5; $h <= 22; $h++) {
    $hourlyActivity[] = ['hour' => $h, 'entries' => count($horaire[$h]['entrees']), 'exits' => count($horaire[$h]['sorties'])];
}

// Compteurs par employé pour JS (initialisés à partir des données PHP)
$jsEmpCounters = [];
foreach ($empCounters as $empId => $cnt) $jsEmpCounters[$empId] = $cnt;

// Liste des employés (filtre)
$allEmployees = [];
$rEmp = $db->query("SELECT id, nom, prenom FROM employees ORDER BY nom, prenom");
while ($row = $rEmp->fetchArray(SQLITE3_ASSOC)) $allEmployees[] = $row;

// Compteurs d'affichage pour le tableau détail
$displayCounters = [];

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Présences — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root {
            --primary:          #4361ee;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
            --gradient-danger:  linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --gradient-warning: linear-gradient(135deg, #fca311 0%, #f77f00 100%);
        }
        body { background: linear-gradient(135deg, #f5f7fa, #e4e7ec); min-height: 100vh; font-family: 'Poppins','Segoe UI',sans-serif; color: #2d3748; }
        .navbar-custom  { background: var(--gradient-primary); box-shadow: 0 4px 15px rgba(67,97,238,.3); }
        .navbar-brand   { font-weight: 700; font-size: 1.4rem; }
        .wifi-status    { padding: 6px 14px; border-radius: 50px; font-weight: 500; display: inline-flex; align-items: center; gap: 7px; color: white; font-size: .83rem; }
        .connected      { background: linear-gradient(135deg, #4caf50, #2e7d32); }
        .disconnected   { background: linear-gradient(135deg, #f44336, #c62828); }
        .esp32-ip       { font-size: .72rem; opacity: .8; }
        .main-card      { background: white; border-radius: 18px; box-shadow: 0 8px 25px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 1.5rem; }
        .card-hd        { background: var(--gradient-primary); color: white; padding: 1.1rem 1.6rem; }
        .card-bd        { padding: 1.6rem; }
        .stat-card      { color: white; border-radius: 15px; padding: 18px; text-align: center; background: var(--gradient-primary); box-shadow: 0 5px 15px rgba(67,97,238,.22); transition: transform .2s; }
        .stat-card:hover{ transform: translateY(-4px); }
        .stat-number    { font-size: 2.3rem; font-weight: 700; margin: 7px 0; }
        .filter-section { background: white; border-radius: 15px; padding: 1.3rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,.07); }
        .card-presence  { background: white; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,.08); }
        .table-presence th { background: var(--gradient-primary); color: white; border: none; padding: 12px 14px; }
        .table-presence td { vertical-align: middle; border-bottom: 1px solid #f0f0f0; padding: 10px 14px; }
        .table-presence tr:last-child td { border-bottom: none; }
        .table-presence tr:hover td { background: #f8f9ff; }
        .badge-entree   { background: var(--gradient-success); color: white; padding: 4px 12px; border-radius: 20px; font-size: .82rem; font-weight: 500; }
        .badge-sortie   { background: var(--gradient-danger);  color: white; padding: 4px 12px; border-radius: 20px; font-size: .82rem; font-weight: 500; }
        .employee-avatar{ width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: .95rem; margin-right: 10px; }
        /* Tableau horaire */
        .horaire-table           { width: 100%; border-collapse: collapse; }
        .horaire-table th        { background: var(--gradient-primary); color: white; padding: 11px 14px; text-align: left; font-weight: 600; }
        .horaire-table td        { padding: 8px 14px; vertical-align: top; border-bottom: 1px solid #f0f4ff; }
        .horaire-table tr:last-child td { border-bottom: none; }
        .horaire-table tr:hover td { background: #f8f9ff; }
        .heure-badge    { background: var(--gradient-primary); color: white; padding: 4px 11px; border-radius: 20px; font-size: .82rem; font-weight: 600; white-space: nowrap; }
        .person-chip    { display: inline-flex; align-items: center; gap: 5px; border-radius: 20px; padding: 3px 10px; margin: 2px; font-size: .8rem; }
        .person-chip.entree { background: #e8f5e9; color: #2e7d32; }
        .person-chip.sortie { background: #fce4ec; color: #c62828; }
        .n-badge        { font-size: .7rem; opacity: .7; margin-left: 2px; }
        .col-entrees    { border-right: 2px solid #e8f5e9; }
        .total-cell     { font-weight: 700; font-size: .9rem; text-align: center; width: 70px; }
        /* Journal */
        .log-container  { max-height: 270px; overflow-y: auto; background: #f8fafc; padding: 1.1rem; border-radius: 13px; border: 2px dashed #e2e8f0; font-family: 'Courier New',monospace; font-size: .87rem; }
        /* Boutons */
        .btn-pc { background: var(--gradient-primary); border: none; border-radius: 50px; padding: 10px 20px; font-weight: 500; color: white; transition: all .3s; }
        .btn-pc:hover { transform: translateY(-2px); color: white; }
        .btn-wc { background: var(--gradient-warning); border: none; border-radius: 50px; padding: 10px 20px; font-weight: 500; color: white; transition: all .3s; }
        .btn-wc:hover { transform: translateY(-2px); color: white; }
        .status-badge   { padding: 5px 13px; border-radius: 50px; font-weight: 500; font-size: .82rem; color: white; }
        .summary-item   { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #f0f0f0; }
        .summary-item:last-child { border-bottom: none; }
        .chart-card     { background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,.07); padding: 1.2rem; }
        .loading-overlay{ position: fixed; inset: 0; background: rgba(255,255,255,.88); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .spinner        { width: 46px; height: 46px; border: 5px solid #e9ecef; border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes flashRow { 0%{ background: rgba(67,97,238,.15); } 100%{ background: transparent; } }
        .row-new { animation: flashRow 3s ease-out forwards; }
        @media (max-width: 768px) { .stat-number { font-size: 1.9rem; } .horaire-table th,.horaire-table td { font-size: .8rem; padding: 6px 8px; } }
    </style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom px-3">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php"><i class="fas fa-fingerprint me-2"></i><?= APP_NAME ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home me-1"></i>Accueil</a></li>
                <li class="nav-item"><a class="nav-link active" href="presence.php"><i class="fas fa-list-check me-1"></i>Présence</a></li>
                <li class="nav-item"><a class="nav-link" href="gestion_empreintes.php"><i class="fas fa-fingerprint me-1"></i>Empreintes</a></li>
                <li class="nav-item"><a class="nav-link" href="reports.php"><i class="fas fa-chart-bar me-1"></i>Rapports</a></li>
            </ul>
            <div class="wifi-status <?= $esp32Online ? 'connected' : 'disconnected' ?>" id="esp32StatusBadge">
                <i class="fas fa-wifi"></i>
                <span id="esp32StatusText"><?= $esp32Online ? 'ESP32 Connecté' : 'ESP32 Hors-ligne' ?></span>
                <span class="esp32-ip">(<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4" style="max-width:1500px;">

    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="mb-1" style="font-weight:700;"><i class="fas fa-list-check text-primary me-2"></i>Liste des Présences</h1>
            <p class="text-muted mb-0">
                Journée du <?= date('d/m/Y', strtotime($selectedDate)) ?> —
                <span id="mqttStatusText" class="<?= $esp32Online ? 'text-success' : 'text-warning' ?>">
                    <?= $esp32Online ? 'réception MQTT active' : 'broker non joignable' ?>
                </span>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-primary btn-sm" onclick="refreshData()"><i class="fas fa-sync me-1"></i>Actualiser</button>
            <a href="?export=csv&date=<?= urlencode($selectedDate) ?>&employee=<?= $selectedEmployee ?>&type=<?= urlencode($selectedType) ?>" class="btn btn-success btn-sm"><i class="fas fa-download me-1"></i>CSV</a>
            <a href="reports.php" class="btn btn-info btn-sm text-white"><i class="fas fa-chart-bar me-1"></i>Rapports</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show mb-4">
        <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="stat-card"><i class="fas fa-clock fa-lg mb-1"></i><div class="stat-number" id="statTotal"><?= $stats['total'] ?></div><small>Total Pointages</small></div></div>
        <div class="col-6 col-md-3"><div class="stat-card" style="background:var(--gradient-success);"><i class="fas fa-sign-in-alt fa-lg mb-1"></i><div class="stat-number" id="statEntrees"><?= $stats['entries'] ?></div><small>Entrées</small></div></div>
        <div class="col-6 col-md-3"><div class="stat-card" style="background:var(--gradient-danger);"><i class="fas fa-sign-out-alt fa-lg mb-1"></i><div class="stat-number" id="statSorties"><?= $stats['exits'] ?></div><small>Sorties</small></div></div>
        <div class="col-6 col-md-3"><div class="stat-card" style="background:var(--gradient-warning);"><i class="fas fa-user-check fa-lg mb-1"></i><div class="stat-number" id="statEmployes"><?= $stats['unique_employees'] ?></div><small>Employés Uniques</small></div></div>
    </div>

    <div class="row g-3">

        <!-- Colonne gauche -->
        <div class="col-lg-8">

            <!-- Filtres -->
            <div class="filter-section mb-3">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold"><i class="fas fa-calendar me-1 text-primary"></i>Date</label>
                        <input type="date" class="form-control" name="date" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold"><i class="fas fa-user me-1 text-primary"></i>Employé</label>
                        <select class="form-select" name="employee">
                            <option value="">Tous</option>
                            <?php foreach ($allEmployees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $selectedEmployee == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['prenom'] . ' ' . $emp['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold"><i class="fas fa-exchange-alt me-1 text-primary"></i>Type</label>
                        <select class="form-select" name="type">
                            <option value="">Tous</option>
                            <option value="ENTREE" <?= $selectedType === 'ENTREE' ? 'selected' : '' ?>>Entrée</option>
                            <option value="SORTIE" <?= $selectedType === 'SORTIE' ? 'selected' : '' ?>>Sortie</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100" onclick="showLoading()"><i class="fas fa-filter me-1"></i>Filtrer</button>
                        <a href="presence.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <!-- Résumé -->
            <div class="bg-white rounded-3 p-3 mb-3 shadow-sm">
                <div class="row text-center">
                    <div class="col-3 border-end"><div class="fw-bold text-primary fs-5" id="summTotal"><?= $stats['total'] ?></div><small class="text-muted">Pointages</small></div>
                    <div class="col-3 border-end"><div class="fw-bold text-success fs-5" id="summE"><?= $stats['entries'] ?></div><small class="text-muted">Entrées</small></div>
                    <div class="col-3 border-end"><div class="fw-bold text-danger fs-5" id="summS"><?= $stats['exits'] ?></div><small class="text-muted">Sorties</small></div>
                    <div class="col-3"><div class="fw-bold text-warning fs-5" id="summEmp"><?= $stats['unique_employees'] ?></div><small class="text-muted">Employés</small></div>
                </div>
            </div>

            <!-- Tableau Entrées/Sorties par heure -->
            <div class="main-card mb-4">
                <div class="card-hd d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Entrées &amp; Sorties par Heure</h5>
                    <small class="opacity-75"><i class="fas fa-info-circle me-1"></i>Impair = Entrée &nbsp;|&nbsp; Pair = Sortie</small>
                </div>
                <div class="p-0">
                    <?php if (!empty($horaireActif)): ?>
                    <div class="table-responsive">
                        <table class="horaire-table" id="horaireTable">
                            <thead>
                                <tr>
                                    <th style="width:90px;">Heure</th>
                                    <th class="col-entrees"><i class="fas fa-sign-in-alt me-1" style="color:#a5d6a7;"></i>Entrées</th>
                                    <th><i class="fas fa-sign-out-alt me-1" style="color:#f48fb1;"></i>Sorties</th>
                                    <th class="total-cell">Total</th>
                                </tr>
                            </thead>
                            <tbody id="horaireBody">
                                <?php foreach ($horaireActif as $h => $data): ?>
                                <tr data-hour="<?= $h ?>">
                                    <td><span class="heure-badge"><?= $data['label'] ?></span></td>
                                    <td class="col-entrees" id="entrees-<?= $h ?>">
                                        <?php if (!empty($data['entrees'])): ?>
                                            <?php foreach ($data['entrees'] as $p): ?>
                                            <span class="person-chip entree">
                                                <i class="fas fa-sign-in-alt" style="font-size:.7rem;"></i>
                                                <?= htmlspecialchars($p['nom']) ?>
                                                <span class="n-badge" title="<?= $p['n'] % 2 !== 0 ? 'Pointage impair → Entrée' : '' ?>">#<?= $p['n'] ?></span>
                                                <span class="text-muted" style="font-size:.72rem;"><?= $p['heure'] ?></span>
                                            </span>
                                            <?php endforeach; ?>
                                        <?php else: ?><span class="text-muted" style="font-size:.82rem;">—</span><?php endif; ?>
                                    </td>
                                    <td id="sorties-<?= $h ?>">
                                        <?php if (!empty($data['sorties'])): ?>
                                            <?php foreach ($data['sorties'] as $p): ?>
                                            <span class="person-chip sortie">
                                                <i class="fas fa-sign-out-alt" style="font-size:.7rem;"></i>
                                                <?= htmlspecialchars($p['nom']) ?>
                                                <span class="n-badge" title="<?= $p['n'] % 2 === 0 ? 'Pointage pair → Sortie' : '' ?>">#<?= $p['n'] ?></span>
                                                <span class="text-muted" style="font-size:.72rem;"><?= $p['heure'] ?></span>
                                            </span>
                                            <?php endforeach; ?>
                                        <?php else: ?><span class="text-muted" style="font-size:.82rem;">—</span><?php endif; ?>
                                    </td>
                                    <td class="total-cell">
                                        <span class="badge bg-primary" id="total-<?= $h ?>"><?= count($data['entrees']) + count($data['sorties']) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5" id="horaireEmpty">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Aucun pointage pour cette journée</h5>
                        <p class="text-muted small">En attente de données MQTT...</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tableau détail des pointages -->
            <div class="card-presence mb-4">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-list text-primary me-2"></i>Détail des Pointages</h5>
                    <span class="badge bg-primary" id="countBadge"><?= count($pointagesData) ?> enregistrement(s)</span>
                </div>
                <div class="p-0">
                    <?php if (!empty($pointagesData)): ?>
                    <div class="table-responsive">
                        <table class="table table-presence mb-0">
                            <thead>
                                <tr>
                                    <th>Employé</th><th>Type</th><th>Date</th><th>Heure</th>
                                    <th class="text-center" title="N° du pointage dans la journée pour cet employé">N°</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pointagesTableBody">
                                <?php foreach ($pointagesData as $p):
                                    $empId = (int)$p['employee_id'];
                                    if (!isset($displayCounters[$empId])) $displayCounters[$empId] = 0;
                                    $displayCounters[$empId]++;
                                    $nRow = $displayCounters[$empId];
                                    $typeAff = ($nRow % 2 !== 0) ? 'ENTREE' : 'SORTIE';
                                ?>
                                <tr id="row-<?= $p['id'] ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="employee-avatar"><?= strtoupper(mb_substr($p['prenom'] ?? '?', 0, 1)) ?></div>
                                            <div>
                                                <strong><?= htmlspecialchars(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? 'Inconnu')) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($p['poste'] ?? '') ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge-<?= strtolower($typeAff) ?>"><?= $typeAff ?></span></td>
                                    <td><i class="fas fa-calendar-alt text-primary me-1"></i><?= date('d/m/Y', strtotime($p['datetime'])) ?></td>
                                    <td><i class="fas fa-clock text-primary me-1"></i><?= date('H:i', strtotime($p['datetime'])) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $nRow % 2 !== 0 ? 'bg-success' : 'bg-danger' ?>"
                                              title="<?= $nRow % 2 !== 0 ? 'Pointage impair → Entrée' : 'Pointage pair → Sortie' ?>">
                                            #<?= $nRow ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-info me-1"
                                                onclick='showDetails(<?= htmlspecialchars(json_encode(["id" => $p["id"], "nom" => ($p["prenom"] ?? '') . ' ' . ($p["nom"] ?? 'Inconnu'), "poste" => $p["poste"] ?? '', "type" => $typeAff, "n" => $nRow, "datetime" => $p["datetime"], "created" => $p["created_at"] ?? '']), ENT_QUOTES) ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="confirmDelete(<?= $p['id'] ?>, '<?= addslashes(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? '')) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5" id="emptyState">
                        <i class="fas fa-satellite-dish fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Aucun pointage pour ce filtre</h5>
                        <p class="text-muted small">En attente de pointages via MQTT...</p>
                        <a href="presence.php" class="btn btn-primary mt-2"><i class="fas fa-redo me-1"></i>Réinitialiser</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Graphiques -->
            <div class="row g-3 mb-4">
                <div class="col-md-5"><div class="chart-card"><h6 class="mb-3"><i class="fas fa-chart-pie text-primary me-1"></i>Répartition Entrées / Sorties</h6><canvas id="typeChart" height="210"></canvas></div></div>
                <div class="col-md-7"><div class="chart-card"><h6 class="mb-3"><i class="fas fa-chart-bar text-primary me-1"></i>Activité Horaire</h6><canvas id="hourlyChart" height="210"></canvas></div></div>
            </div>
        </div>

        <!-- Colonne droite -->
        <div class="col-lg-4">

            <div class="main-card mb-4">
                <div class="card-hd"><h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Actions Rapides</h5></div>
                <div class="card-bd">
                    <div class="d-grid gap-2">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="ping_esp32">
                            <button type="submit" class="btn btn-pc w-100"><i class="fas fa-wifi me-2"></i>Tester connexion ESP32</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="sync_data">
                            <button type="submit" class="btn btn-wc w-100" <?= !$esp32Online ? 'disabled' : '' ?>>
                                <i class="fas fa-sync-alt me-2"></i>Synchroniser EEPROM
                            </button>
                        </form>
                    </div>
                    <?php if (!$esp32Online): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Broker MQTT non joignable (<?= MQTT_HOST ?>:<?= MQTT_PORT ?>).<br>
                        <small>Vérifiez que Mosquitto est démarré.</small>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="fas fa-circle-check me-2"></i>
                        Broker actif — écoute <code>bioaccess/esp32/pointage</code>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="main-card mb-4">
                <div class="card-hd"><h5 class="mb-0"><i class="fas fa-terminal me-2"></i>Journal MQTT</h5></div>
                <div class="card-bd">
                    <div class="log-container" id="logContainer">
                        <div><span class="text-muted">[<?= date('H:i:s') ?>]</span> <span class="text-success">Système initialisé</span></div>
                        <?php if ($esp32Online): ?>
                        <div><span class="text-muted">[<?= date('H:i:s') ?>]</span> <span class="text-primary">Broker MQTT joignable — <?= MQTT_HOST ?>:<?= MQTT_PORT ?></span></div>
                        <?php else: ?>
                        <div><span class="text-muted">[<?= date('H:i:s') ?>]</span> <span class="text-warning">Broker MQTT non joignable</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted small">Écoute directe ESP32</span>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-info btn-sm" onclick="pingESP32()"><i class="fas fa-satellite-dish"></i> PING</button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="clearLogs()"><i class="fas fa-broom"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-card">
                <div class="card-hd"><h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Statut du Système</h5></div>
                <div class="card-bd">
                    <div class="summary-item"><span>Capteur ESP32 :</span><span class="status-badge <?= $esp32Online ? 'bg-success' : 'bg-danger' ?>" id="esp32StatusSidebar"><?= $esp32Online ? 'Connecté' : 'Déconnecté' ?></span></div>
                    <div class="summary-item"><span>Broker MQTT :</span><code style="font-size:.82rem;"><?= MQTT_HOST ?>:<?= MQTT_PORT ?></code></div>
                    <div class="summary-item"><span>Topic pointage :</span><code style="font-size:.78rem;">bioaccess/esp32/pointage</code></div>
                    <div class="summary-item"><span>Base de données :</span><span class="status-badge bg-success">Opérationnelle</span></div>
                    <div class="summary-item"><span>Pointages :</span><span class="fw-bold text-primary" id="sidebarCount"><?= $stats['total'] ?></span></div>
                    <div class="summary-item"><span>Règle entrée :</span><span class="text-muted small"><i class="fas fa-sign-in-alt text-success me-1"></i>N° impair</span></div>
                    <div class="summary-item"><span>Règle sortie :</span><span class="text-muted small"><i class="fas fa-sign-out-alt text-danger me-1"></i>N° pair</span></div>
                    <div class="summary-item"><span>Heure serveur :</span><span id="serverClock" class="text-muted"><?= date('H:i:s') ?></span></div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal Détails -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header" style="background:var(--gradient-primary);color:white;">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Détails du Pointage</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button></div>
        </div>
    </div>
</div>

<!-- Modal Suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Supprimer le pointage</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer le pointage de <strong id="deleteInfo"></strong> ?</p>
                <p class="text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>Action irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="fas fa-trash me-1"></i>Supprimer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/mqtt@5.3.4/dist/mqtt.min.js"></script>
<script>
// =============================================================================
// CONFIGURATION — identique à gestion_empreintes.php (corrigé)
// =============================================================================
const MQTT_WS_URL         = 'ws://<?= MQTT_HOST ?>:<?= MQTT_WS_PORT ?>';
const MQTT_TOPIC_STATUS   = '<?= MQTT_TOPIC_STATUS ?>';
const MQTT_TOPIC_CMD      = '<?= MQTT_TOPIC_CMD ?>';
const MQTT_TOPIC_POINTAGE = 'bioaccess/esp32/pointage';
const CSRF_TOKEN          = <?= json_encode($csrf_token) ?>;
const IS_ADMIN            = <?= (($_SESSION['user_role'] ?? '') === 'admin') ? 'true' : 'false' ?>;
const SELECTED_DATE       = '<?= $selectedDate ?>';

let mqttClient    = null;
let mqttConnected = false;

// Compteurs impair/pair par employé (initialisés depuis PHP)
let empCounters = <?= json_encode((object)$jsEmpCounters) ?>;
let localStats  = { total: <?= $stats['total'] ?>, entries: <?= $stats['entries'] ?>, exits: <?= $stats['exits'] ?> };

// =============================================================================
// JOURNAL — identique à gestion_empreintes.php
// =============================================================================
function addLog(msg, type = 'info') {
    const lc = document.getElementById('logContainer');
    const ts = new Date().toLocaleTimeString('fr-FR');
    const el = document.createElement('div');
    const col = { success:'text-success', warning:'text-warning', error:'text-danger', info:'text-info' };
    el.innerHTML = `<span class="text-muted">[${ts}]</span> <span class="${col[type]||'text-info'}">${msg}</span>`;
    lc.appendChild(el);
    lc.scrollTop = lc.scrollHeight;
}
function clearLogs() {
    document.getElementById('logContainer').innerHTML = '<div class="text-center text-muted">Logs effacés</div>';
    setTimeout(() => { document.getElementById('logContainer').innerHTML = ''; addLog('Journal effacé','info'); }, 1000);
}

// =============================================================================
// BADGE ESP32 — identique à gestion_empreintes.php
// =============================================================================
function updateESP32Status(online) {
    const badge   = document.getElementById('esp32StatusBadge');
    const sidebar = document.getElementById('esp32StatusSidebar');
    const txt     = document.getElementById('mqttStatusText');
    if (badge) {
        badge.className = 'wifi-status ' + (online ? 'connected' : 'disconnected');
        document.getElementById('esp32StatusText').textContent = online ? 'ESP32 Connecté' : 'ESP32 Hors-ligne';
    }
    if (sidebar) { sidebar.className = 'status-badge '+(online?'bg-success':'bg-danger'); sidebar.textContent = online?'Connecté':'Déconnecté'; }
    if (txt) { txt.textContent = online?'réception MQTT active':'broker non joignable'; txt.className = online?'text-success':'text-warning'; }
}

// =============================================================================
// PING — texte brut "PING" (corrigé, cohérent avec PHP et gestion_empreintes.php)
// =============================================================================
function pingESP32() {
    if (!mqttConnected) { addLog('⚠️ MQTT non connecté', 'error'); return; }
    addLog('📡 Envoi PING via MQTT (<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)...', 'info');
    mqttClient.publish(MQTT_TOPIC_CMD, 'PING', { qos: 1 }); // texte brut, pas JSON
    addLog('📤 PING publié — attente du PONG...', 'info');
}

// =============================================================================
// RÈGLE IMPAIR/PAIR — détermine le type du prochain pointage
// =============================================================================
function getNextType(employeeId) {
    if (!empCounters[employeeId]) empCounters[employeeId] = 0;
    empCounters[employeeId]++;
    return empCounters[employeeId] % 2 !== 0 ? 'ENTREE' : 'SORTIE';
}

// =============================================================================
// SAUVEGARDE EN DB via presence_save.php
// =============================================================================
async function savePointage(fingerprintId, datetime) {
    try {
        const res = await fetch('presence_save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, fingerprint_id: fingerprintId, datetime }),
        });
        if (!res.ok) { addLog('HTTP ' + res.status + ' lors de la sauvegarde', 'error'); return null; }
        const data = await res.json();
        if (data.duplicate) { addLog('⏭ Doublon ignoré — ' + (data.nom || ''), 'warning'); return null; }
        if (!data.success)  { addLog('❌ Erreur : ' + (data.error || ''), 'error'); return null; }
        return data;
    } catch (err) { addLog('❌ Réseau : ' + err.message, 'error'); return null; }
}

// =============================================================================
// INSERTION DANS LE TABLEAU DÉTAIL
// =============================================================================
function insertPointageRow(data) {
    if ((data.datetime || '').substring(0, 10) !== SELECTED_DATE) return;
    const tbody = document.getElementById('pointagesTableBody');
    if (!tbody) return;
    const empty = document.getElementById('emptyState');
    if (empty) empty.style.display = 'none';

    const heure   = (data.datetime || '').substring(11, 16);
    const dateAff = (data.datetime || '').substring(0, 10).split('-').reverse().join('/');
    const initial = (data.prenom || '?').charAt(0).toUpperCase();
    const name    = ((data.prenom || '') + ' ' + (data.nom || '')).trim() || 'Inconnu';
    const type    = data.type || 'ENTREE';
    const n       = parseInt(data.n) || empCounters[data.employee_id] || 1;

    const tr  = document.createElement('tr');
    tr.id     = 'row-' + data.pointage_id;
    tr.classList.add('row-new');
    tr.innerHTML = `
        <td><div class="d-flex align-items-center">
            <div class="employee-avatar">${initial}</div>
            <div><strong>${name}</strong><br><small class="text-muted">${data.poste || ''}</small></div>
        </div></td>
        <td><span class="badge-${type.toLowerCase()}">${type}</span></td>
        <td><i class="fas fa-calendar-alt text-primary me-1"></i>${dateAff}</td>
        <td><i class="fas fa-clock text-primary me-1"></i>${heure}</td>
        <td class="text-center">
            <span class="badge ${type==='ENTREE'?'bg-success':'bg-danger'}"
                  title="${n%2!==0?'Pointage impair → Entrée':'Pointage pair → Sortie'}">#${n}</span>
        </td>
        <td class="text-center">
            <button class="btn btn-sm btn-outline-info me-1"
                    onclick='showDetails(${JSON.stringify({id:data.pointage_id,nom:name,poste:data.poste||'',type,n,datetime:data.datetime,created:""})})'>
                <i class="fas fa-eye"></i></button>
            ${IS_ADMIN?`<button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${data.pointage_id},'${name.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>`:''}
        </td>`;
    tbody.insertBefore(tr, tbody.firstChild);
    updateCounters(type);
    updateHoraireTable(data);
}

// =============================================================================
// MISE À JOUR DU TABLEAU HORAIRE EN TEMPS RÉEL
// =============================================================================
function updateHoraireTable(data) {
    const h    = parseInt((data.datetime || '').substring(11, 13));
    const type = data.type || 'ENTREE';
    const name = ((data.prenom || '') + ' ' + (data.nom || '')).trim();
    const time = (data.datetime || '').substring(11, 16);
    const n    = parseInt(data.n) || empCounters[data.employee_id] || 1;

    const tbody = document.getElementById('horaireBody');
    const empty = document.getElementById('horaireEmpty');
    if (empty) empty.style.display = 'none';

    let row = tbody ? tbody.querySelector(`[data-hour="${h}"]`) : null;
    if (!row && tbody) {
        row = document.createElement('tr');
        row.dataset.hour = h;
        row.classList.add('row-new');
        row.innerHTML = `
            <td><span class="heure-badge">${String(h).padStart(2,'0')}h</span></td>
            <td class="col-entrees" id="entrees-${h}"></td>
            <td id="sorties-${h}"></td>
            <td class="total-cell"><span class="badge bg-primary" id="total-${h}">0</span></td>`;
        let inserted = false;
        for (const r of tbody.querySelectorAll('[data-hour]')) {
            if (parseInt(r.dataset.hour) > h) { tbody.insertBefore(row, r); inserted = true; break; }
        }
        if (!inserted) tbody.appendChild(row);
    }
    if (!row) return;

    const col     = document.getElementById((type === 'ENTREE' ? 'entrees-' : 'sorties-') + h);
    const totalEl = document.getElementById('total-' + h);

    if (col) {
        // Vider le tiret si présent
        if (col.textContent.trim() === '—') col.innerHTML = '';
        const chip = document.createElement('span');
        chip.className = `person-chip ${type.toLowerCase()}`;
        chip.innerHTML = `<i class="fas fa-sign-${type==='ENTREE'?'in':'out'}-alt" style="font-size:.7rem;"></i>${name}<span class="n-badge">#${n}</span><span class="text-muted" style="font-size:.72rem;">${time}</span>`;
        col.appendChild(chip);
    }
    if (totalEl) totalEl.textContent = parseInt(totalEl.textContent || '0') + 1;
}

// =============================================================================
// COMPTEURS
// =============================================================================
function updateCounters(type) {
    localStats.total++;
    if (type === 'ENTREE') localStats.entries++; else localStats.exits++;
    const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    set('statTotal', localStats.total); set('statEntrees', localStats.entries); set('statSorties', localStats.exits);
    set('summTotal', localStats.total); set('summE', localStats.entries); set('summS', localStats.exits);
    set('sidebarCount', localStats.total);
    const b = document.getElementById('countBadge'); if (b) b.textContent = localStats.total + ' enregistrement(s)';
}

// =============================================================================
// CLIENT MQTT — identique à gestion_empreintes.php (corrigé)
// =============================================================================
function mqttInit() {
    mqttClient = mqtt.connect(MQTT_WS_URL, {
        clientId: 'bioaccess_presence_' + Math.random().toString(36).slice(2, 8),
        clean: true, reconnectPeriod: 3000,
    });

    mqttClient.on('connect', () => {
        mqttConnected = true;
        updateESP32Status(true);
        addLog('📡 MQTT connecté au broker', 'success');
        mqttClient.subscribe(MQTT_TOPIC_STATUS,   { qos: 1 }); // heartbeat ESP32
        mqttClient.subscribe(MQTT_TOPIC_POINTAGE, { qos: 1 }); // empreintes vérifiées
    });
    mqttClient.on('reconnect',  () => addLog('🔄 Reconnexion MQTT...', 'warning'));
    mqttClient.on('disconnect', () => { mqttConnected = false; updateESP32Status(false); addLog('⚠️ MQTT déconnecté', 'warning'); });
    mqttClient.on('error', err => addLog('❌ MQTT : ' + (err.message || err), 'error'));

    mqttClient.on('message', async (topic, raw) => {
        let data = {};
        try { data = JSON.parse(raw.toString()); } catch {}

        // ── Heartbeat / statut ESP32 ─────────────────────────────────────
        if (topic === MQTT_TOPIC_STATUS) {
            if (data.online !== undefined) updateESP32Status(data.online);
            if (data.pong) addLog('✅ PONG reçu de l\'ESP32', 'success');
            const ip = data.ip ? ' | IP: '+data.ip : '';
            const rs = data.rssi ? ' | RSSI: '+data.rssi+' dBm' : '';
            addLog('💓 Heartbeat ESP32'+ip+rs, 'info');
            return;
        }

        // ── Empreinte vérifiée — payload: {fingerprint_id: N, datetime: "..."} ──
        if (topic === MQTT_TOPIC_POINTAGE) {
            const fpId = data.fingerprint_id;
            const dt   = data.datetime;
            if (!fpId || !dt) { addLog('⚠️ Payload invalide sur '+topic, 'warning'); return; }

            addLog(`🔍 Empreinte vérifiée — fp_id=${fpId} @ ${dt}`, 'info');

            const saved = await savePointage(fpId, dt);
            if (saved) {
                // Synchroniser le compteur local avec la valeur retournée par le serveur
                if (saved.employee_id) empCounters[saved.employee_id] = parseInt(saved.n) || (empCounters[saved.employee_id] || 0) + 1;

                const emoji = saved.type === 'ENTREE' ? '🟢' : '🔴';
                addLog(`${emoji} Inséré : ${saved.prenom} ${saved.nom} → ${saved.type} (#${saved.n||''})`, 'success');
                insertPointageRow(saved);
                showNotification(`${saved.prenom} ${saved.nom} — ${saved.type}`, saved.type==='ENTREE'?'success':'warning');
            }
        }
    });
}

// =============================================================================
// DÉTAILS / SUPPRESSION
// =============================================================================
function showDetails(d) {
    document.getElementById('detailsContent').innerHTML = `
        <table class="table table-sm mb-0">
            <tr><th style="width:42%">ID pointage</th><td>${d.id}</td></tr>
            <tr><th>Employé</th><td>${d.nom}</td></tr>
            <tr><th>Poste</th><td>${d.poste||'&mdash;'}</td></tr>
            <tr><th>Type</th><td><span class="badge-${(d.type||'').toLowerCase()}">${d.type}</span></td></tr>
            <tr><th>N° du jour</th><td><span class="badge ${d.n%2!==0?'bg-success':'bg-danger'}">#${d.n}</span>
                <small class="text-muted ms-2">${d.n%2!==0?'(impair → Entrée)':'(pair → Sortie)'}</small></td></tr>
            <tr><th>Date / Heure</th><td>${new Date((d.datetime||'').replace(' ','T')).toLocaleString('fr-FR')}</td></tr>
            <tr><th>Enregistré le</th><td>${d.created?new Date(d.created.replace(' ','T')).toLocaleString('fr-FR'):'&mdash;'}</td></tr>
            <tr><th>Source</th><td><i class="fas fa-fingerprint text-primary me-1"></i>Capteur biométrique via MQTT</td></tr>
        </table>`;
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
}
function confirmDelete(id, name) {
    document.getElementById('deleteInfo').textContent = name + ' (pointage #' + id + ')';
    document.getElementById('confirmDeleteBtn').onclick = () => deletePointage(id);
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
function deletePointage(id) {
    showLoading();
    bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
    fetch('api_controller.php?action=delete_pointage', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id, csrf_token: CSRF_TOKEN }),
    }).then(r=>r.json()).then(d=>{
        hideLoading();
        if (d.success) { const row=document.getElementById('row-'+id); if(row) row.remove(); showNotification('Pointage supprimé','success'); }
        else showNotification('Erreur : '+(d.message||'impossible de supprimer'),'danger');
    }).catch(()=>{ hideLoading(); showNotification('Erreur réseau','danger'); });
}
function showNotification(msg, type) {
    const div = document.createElement('div');
    div.className = `alert alert-${type} alert-dismissible fade show position-fixed bottom-0 end-0 m-3`;
    div.style.cssText = 'z-index:9999;min-width:260px;box-shadow:0 4px 15px rgba(0,0,0,.15);';
    div.innerHTML = `<i class="fas fa-fingerprint me-2"></i>${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(div);
    setTimeout(()=>{ try{div.remove();}catch{} }, 5000);
}
function showLoading() { document.getElementById('loadingOverlay').style.display='flex'; }
function hideLoading() { document.getElementById('loadingOverlay').style.display='none'; }
function refreshData()  { showLoading(); location.reload(); }
setInterval(()=>{ const e=document.getElementById('serverClock'); if(e) e.textContent=new Date().toLocaleTimeString('fr-FR'); }, 1000);

// =============================================================================
// GRAPHIQUES
// =============================================================================
function initCharts() {
    const entries = <?= $stats['entries'] ?>;
    const exits   = <?= $stats['exits'] ?>;
    if (entries + exits > 0) {
        new Chart(document.getElementById('typeChart'), {
            type:'doughnut',
            data:{ labels:['Entrées','Sorties'], datasets:[{data:[entries,exits],backgroundColor:['#4ecdc4','#f72585'],borderWidth:2}] },
            options:{ responsive:true, plugins:{legend:{position:'bottom'}} }
        });
    } else {
        document.getElementById('typeChart').parentElement.innerHTML = '<p class="text-center text-muted py-4"><i class="fas fa-chart-pie fa-2x mb-2 d-block"></i>Aucune donnée</p>';
    }
    new Chart(document.getElementById('hourlyChart'), {
        type:'bar',
        data:{
            labels:  <?= json_encode(array_map(fn($h) => $h['hour'] . 'h', $hourlyActivity)) ?>,
            datasets:[
                {label:'Entrées',data:<?= json_encode(array_column($hourlyActivity,'entries')) ?>,backgroundColor:'rgba(78,205,196,.75)',borderRadius:4},
                {label:'Sorties',data:<?= json_encode(array_column($hourlyActivity,'exits'))   ?>,backgroundColor:'rgba(247,37,133,.75)',borderRadius:4}
            ]
        },
        options:{ responsive:true, plugins:{legend:{position:'bottom'}}, scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{stepSize:1}}} }
    });
}

// =============================================================================
// BOOT — même séquence que gestion_empreintes.php
// =============================================================================
document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    setTimeout(() => addLog('Système prêt', 'info'), 400);
    setTimeout(() => addLog('Topic écouté : bioaccess/esp32/pointage', 'info'), 700);
    setTimeout(() => addLog('Broker MQTT <?= MQTT_HOST ?>:<?= MQTT_PORT ?>', 'info'), 1000);
    setTimeout(() => mqttInit(), 1200);
    <?php if ($esp32Online): ?>
    setTimeout(() => addLog('✅ Broker MQTT joignable au chargement de la page', 'success'), 1400);
    <?php else: ?>
    setTimeout(() => addLog('⚠️ Broker MQTT non joignable — vérifiez Mosquitto', 'warning'), 1400);
    <?php endif; ?>
    <?php if (!empty($message)): ?>
    setTimeout(() => addLog('<?= $message_type==="success"?"✅":"❌" ?> <?= addslashes($message) ?>', '<?= $message_type==="success"?"success":"error" ?>'), 1600);
    <?php endif; ?>
});
</script>
</body>
</html>
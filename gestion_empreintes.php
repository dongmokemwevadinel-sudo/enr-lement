<?php
// gestion_empreintes.php - Communication MQTT avec ESP32
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Employee.php';
require_once __DIR__ . '/Pointage.php';

// Vérification authentification
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Permissions admin uniquement
$isAdmin = ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'superadmin');
if (!$isAdmin) {
    header('Location: index.php');
    exit();
}

// Initialisation
$message      = '';
$message_type = '';

// Vérification connexion ESP32 (remplace $bluetoothConnected)
$esp32Online = mqttIsOnline();

// Modèles
$database      = Database::getInstance();
$db            = $database->getConnection();
$employeeModel = new Employee();
$pointageModel = new Pointage();

// =====================================================================
// Helpers
// =====================================================================

function setMessage(string $text, string $type): void {
    global $message, $message_type;
    $message      = $text;
    $message_type = $type;
}

function findNextAvailableFingerprintId(): int {
    global $db;
    $used_ids = [];
    $result   = $db->query("SELECT fingerprint_id FROM employees WHERE fingerprint_id IS NOT NULL");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $used_ids[] = (int)$row['fingerprint_id'];
    }
    for ($i = 1; $i <= 127; $i++) {
        if (!in_array($i, $used_ids)) return $i;
    }
    return 0;
}

function getFingerprintData(): array {
    $employees   = Employee::getAll();
    $fingerprints = [];
    foreach ($employees as $employee) {
        if ($employee->getFingerprintId()) {
            $fingerprints[] = [
                'id'             => $employee->getFingerprintId(),
                'employee_id'    => $employee->getId(),
                'employee_name'  => $employee->getFullName(),
                'employee_poste' => $employee->getPoste(),
                'date_creation'  => $employee->getDateCreation()
            ];
        }
    }
    return $fingerprints;
}

function getSystemStats(): array {
    global $db;
    return [
        'total_employees'            => $db->querySingle("SELECT COUNT(*) FROM employees"),
        'employees_with_fingerprint' => $db->querySingle("SELECT COUNT(*) FROM employees WHERE fingerprint_id IS NOT NULL"),
        'total_pointages'            => $db->querySingle("SELECT COUNT(*) FROM pointages"),
        'pointages_today'            => $db->querySingle("SELECT COUNT(*) FROM pointages WHERE DATE(datetime) = DATE('now')"),
        'storage_used'               => getStorageUsage(),
        'last_sync'                  => $db->querySingle("SELECT MAX(sync_date) FROM sync_log")
    ];
}

function getStorageUsage(): string {
    $size  = filesize(DB_PATH);
    $units = ['B', 'KB', 'MB', 'GB'];
    $i     = 0;
    while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
    return round($size, 2) . ' ' . $units[$i];
}

// =====================================================================
// Handlers des actions POST
// =====================================================================

function handleEnrollAction(): void {
    global $db;

    $nom       = trim($_POST['nom']       ?? '');
    $prenom    = trim($_POST['prenom']    ?? '');
    $poste     = trim($_POST['poste']     ?? '');
    $email     = trim($_POST['email']     ?? '');
    $telephone = trim($_POST['telephone'] ?? '');

    if (empty($nom) || empty($prenom)) {
        setMessage("Nom et prénom obligatoires", "error");
        return;
    }

    $next_id = findNextAvailableFingerprintId();
    if (!$next_id) {
        setMessage("Aucun ID d'empreinte disponible (1-127 utilisés)", "error");
        return;
    }

    // Envoi de la commande ENROLL à l'ESP32 via WiFi TCP
    $res = esp32SendCommand("ENROLL $next_id");

    if (!$res['success']) {
        setMessage("Impossible de joindre l'ESP32 : " . $res['error'], "error");
        return;
    }

    // Vérifier que l'ESP32 a bien accepté
    if (str_contains($res['response'], 'FAIL')) {
        setMessage("Le capteur a refusé l'enrôlement (ID $next_id). Réponse : " . $res['response'], "error");
        return;
    }

    // Sauvegarde en base
    $stmt = $db->prepare("
        INSERT INTO employees (nom, prenom, poste, email, telephone, fingerprint_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bindValue(1, $nom,       SQLITE3_TEXT);
    $stmt->bindValue(2, $prenom,    SQLITE3_TEXT);
    $stmt->bindValue(3, $poste,     SQLITE3_TEXT);
    $stmt->bindValue(4, $email,     SQLITE3_TEXT);
    $stmt->bindValue(5, $telephone, SQLITE3_TEXT);
    $stmt->bindValue(6, $next_id,   SQLITE3_INTEGER);

    if ($stmt->execute()) {
        setMessage("Employé $prenom $nom enrôlé avec l'empreinte #$next_id. Réponse ESP32 : " . $res['response'], "success");
        logActivity("Enrôlement: $prenom $nom, ID empreinte $next_id");
    } else {
        setMessage("Empreinte envoyée au capteur mais erreur d'insertion en base.", "error");
    }
}

function handleDeleteAction(): void {
    $employee_id  = intval($_POST['employee_id'] ?? 0);
    $confirmation = $_POST['confirmation'] ?? '';

    if (!$employee_id) {
        setMessage("ID employé manquant", "error"); return;
    }
    if ($confirmation !== 'CONFIRM') {
        setMessage("Veuillez taper CONFIRM pour valider", "error"); return;
    }

    $employee = Employee::getById($employee_id);
    if (!$employee) {
        setMessage("Employé introuvable", "error"); return;
    }

    $fingerprint_id = $employee->getFingerprintId();
    if (!$fingerprint_id) {
        setMessage("Cet employé n'a pas d'empreinte enregistrée", "error"); return;
    }

    // Envoi de la commande DEL à l'ESP32 via WiFi TCP
    $res = esp32SendCommand("DEL $fingerprint_id");

    if (!$res['success']) {
        setMessage("Impossible de joindre l'ESP32 : " . $res['error'], "error"); return;
    }

    if (str_contains($res['response'], 'FAIL')) {
        setMessage("Le capteur a refusé la suppression. Réponse : " . $res['response'], "error"); return;
    }

    // Suppression en base
    if ($employee->delete()) {
        setMessage("Empreinte #$fingerprint_id et données de l'employé supprimées. Réponse ESP32 : " . $res['response'], "success");
        logActivity("Suppression empreinte #$fingerprint_id et employé ID $employee_id");
    } else {
        setMessage("Supprimé du capteur mais erreur de suppression en base.", "error");
    }
}

function handleSyncAction(): void {
    // Déclenche la synchronisation EEPROM → base via la commande SYNC de l'ESP32
    $res = esp32SendCommand("SYNC");

    if (!$res['success']) {
        setMessage("Impossible de joindre l'ESP32 : " . $res['error'], "error"); return;
    }

    // Enregistrement dans sync_log
    global $db;
    $stmt = $db->prepare("INSERT INTO sync_log (sync_date, status, details) VALUES (datetime('now'), 'success', ?)");
    $stmt->bindValue(1, $res['response'], SQLITE3_TEXT);
    $stmt->execute();

    setMessage("Synchronisation demandée. Réponse ESP32 : " . $res['response'], "success");
    logActivity("Synchronisation ESP32 demandée");
}

function handlePingAction(): void {
    $res = esp32SendCommand("PING");
    if ($res['success'] && str_contains($res['response'], 'PONG')) {
        setMessage("ESP32 joignable — PONG reçu (" . MQTT_HOST . ":" . MQTT_PORT . ")", "success");
    } else {
        setMessage("ESP32 ne répond pas. " . ($res['error'] ?: $res['response']), "error");
    }
}

function handleClearAllAction(): void {
    // Envoie CLEAR à l'ESP32 (efface l'EEPROM du firmware) et supprime tout en base
    $res = esp32SendCommand("CLEAR");

    global $db;
    $db->exec("UPDATE employees SET fingerprint_id = NULL");
    logActivity("Effacement total des empreintes");

    if ($res['success']) {
        setMessage("Toutes les empreintes effacées. Réponse ESP32 : " . $res['response'], "success");
    } else {
        setMessage("Base nettoyée, mais ESP32 injoignable : " . $res['error'], "error");
    }
}

// =====================================================================
// Traitement POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrf_token)) {
        $message      = "Erreur de sécurité. Veuillez recharger la page.";
        $message_type = "error";
        logError("Token CSRF invalide dans gestion_empreintes.php");
    } else {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'enroll':    handleEnrollAction();   break;
            case 'delete':    handleDeleteAction();   break;
            case 'sync_data': handleSyncAction();     break;
            case 'ping_esp32':handlePingAction();     break;
            case 'clear_all': handleClearAllAction(); break;
        }
        // Rafraîchir le statut après action
        $esp32Online = mqttIsOnline();
    }
}

// =====================================================================
// Données pour la vue
// =====================================================================
$employees    = $employeeModel->getAll();
$fingerprints = getFingerprintData();
$stats        = getSystemStats();
$csrf_token   = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Empreintes - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #fca311;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            --gradient-danger:  linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --gradient-warning: linear-gradient(135deg, #fca311 0%, #f77f00 100%);
        }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e7ec 100%);
            min-height: 100vh;
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            color: #2d3748;
        }
        .navbar-custom { background: var(--gradient-primary); box-shadow: 0 4px 15px rgba(67,97,238,.3); padding: .8rem 1rem; }
        .navbar-brand  { font-weight: 700; font-size: 1.5rem; }
        .container-main { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
        .main-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 2rem; }
        .card-header-custom { background: var(--gradient-primary); color: white; padding: 1.5rem 2rem; }
        .card-body-custom   { padding: 2rem; }
        .btn-primary-custom { background: var(--gradient-primary); border: none; border-radius: 50px; padding: 12px 25px; font-weight: 500; transition: all .3s; box-shadow: 0 4px 15px rgba(67,97,238,.3); color: white; }
        .btn-primary-custom:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(67,97,238,.4); color: white; }
        .btn-success-custom { background: var(--gradient-success); border: none; border-radius: 50px; padding: 12px 25px; font-weight: 500; transition: all .3s; color: white; }
        .btn-danger-custom  { background: var(--gradient-danger);  border: none; border-radius: 50px; padding: 12px 25px; font-weight: 500; transition: all .3s; color: white; }
        .btn-warning-custom { background: var(--gradient-warning); border: none; border-radius: 50px; padding: 12px 25px; font-weight: 500; transition: all .3s; color: white; }
        h1,h2,h3,h4,h5,h6 { font-weight: 700; color: #2d3748; }
        .form-control-custom { border-radius: 12px; padding: 12px 18px; border: 2px solid #e2e8f0; transition: all .3s; }
        .form-control-custom:focus { border-color: #4361ee; box-shadow: 0 0 0 .25rem rgba(67,97,238,.25); }
        .status-badge { padding: 8px 16px; border-radius: 50px; font-weight: 500; font-size: .85rem; color: white; }
        .wifi-status { padding: 10px 20px; border-radius: 50px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; color: white; }
        .connected    { background: linear-gradient(135deg,#4caf50 0%,#2e7d32 100%); }
        .disconnected { background: linear-gradient(135deg,#f44336 0%,#c62828 100%); }
        .fingerprint-visual { display: flex; justify-content: center; align-items: center; margin: 1.5rem 0; }
        .fingerprint-icon { font-size: 5rem; color: var(--primary); animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.05)} }
        .log-container { max-height: 300px; overflow-y: auto; background: #f8fafc; padding: 1.5rem; border-radius: 15px; border: 2px dashed #e2e8f0; font-family: 'Courier New', monospace; font-size: .9rem; }
        .stat-card { background: white; border-radius: 15px; padding: 1.5rem; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,.05); transition: all .3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,.1); }
        .stat-number { font-size: 2.5rem; font-weight: 700; margin: .5rem 0; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .progress-container { background: #e2e8f0; border-radius: 50px; height: 10px; overflow: hidden; margin: 1rem 0; }
        .progress-bar-custom { height: 100%; border-radius: 50px; background: var(--gradient-primary); transition: width .5s; }
        .feature-icon { font-size: 2.5rem; margin-bottom: 1rem; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .table-responsive { border-radius: 15px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,.1); }
        .table th { background: var(--gradient-primary); color: white; border: none; padding: 1rem; }
        .table td { padding: 1rem; vertical-align: middle; }
        .esp32-ip { font-size: .75rem; opacity: .8; margin-left: 5px; }
        @media(max-width:768px){ .container-main{margin:1rem auto} .card-body-custom{padding:1.5rem} .fingerprint-icon{font-size:3.5rem} }
    </style>
</head>
<body>
<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-fingerprint"></i> <?= APP_NAME ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Accueil</a></li>
                <li class="nav-item"><a class="nav-link" href="presence.php"><i class="fas fa-list-check"></i> Présence</a></li>
                <li class="nav-item"><a class="nav-link" href="reports.php"><i class="fas fa-chart-bar"></i> Rapports</a></li>
                <li class="nav-item"><a class="nav-link active" href="gestion_empreintes.php"><i class="fas fa-fingerprint"></i> Empreintes</a></li>
            </ul>
            <!-- Statut ESP32 WiFi -->
            <div class="wifi-status <?= $esp32Online ? 'connected' : 'disconnected' ?>" id="esp32StatusBadge">
                <i class="fas fa-wifi"></i>
                <?= $esp32Online ? 'ESP32 Connecté' : 'ESP32 Hors-ligne' ?>
                <span class="esp32-ip">(<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>
            </div>
        </div>
    </div>
</nav>

<div class="container container-main">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Gestion des Empreintes Digitales</h1>
            <p class="text-muted">Administration complète des empreintes biométriques via WiFi (ESP32)</p>
        </div>
        <div class="wifi-status <?= $esp32Online ? 'connected' : 'disconnected' ?>">
            <i class="fas fa-wifi"></i>
            <?= $esp32Online ? 'Capteur connecté' : 'Capteur déconnecté' ?>
        </div>
    </div>

    <!-- Messages d'alerte -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Cartes de statistiques -->
    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card">
                <div class="feature-icon"><i class="fas fa-fingerprint"></i></div>
                <h3 class="stat-number"><?= $stats['employees_with_fingerprint'] ?></h3>
                <p class="text-muted">Empreintes enregistrées</p>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <h3 class="stat-number"><?= $stats['total_employees'] ?></h3>
                <p class="text-muted">Employés enregistrés</p>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card">
                <div class="feature-icon"><i class="fas fa-database"></i></div>
                <h3 class="stat-number"><?= $stats['storage_used'] ?></h3>
                <p class="text-muted">Espace de stockage</p>
                <div class="progress-container">
                    <div class="progress-bar-custom" style="width: 70%"></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card">
                <div class="feature-icon"><i class="fas fa-clock"></i></div>
                <h3 class="stat-number"><?= $stats['pointages_today'] ?></h3>
                <p class="text-muted">Pointages aujourd'hui</p>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Colonne gauche -->
        <div class="col-lg-8">

            <!-- Carte Enrôlement -->
            <div class="main-card mb-4">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-plus-circle"></i> Enrôlement d'empreinte</h4>
                </div>
                <div class="card-body-custom">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="enroll">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">ID de l'empreinte (automatique)</label>
                                    <input type="text" class="form-control form-control-custom"
                                           value="ID #<?= findNextAvailableFingerprintId() ?> sera attribué" disabled>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nom <span class="text-danger">*</span></label>
                                    <input type="text" name="nom" class="form-control form-control-custom" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Prénom <span class="text-danger">*</span></label>
                                    <input type="text" name="prenom" class="form-control form-control-custom" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Poste</label>
                                    <input type="text" name="poste" class="form-control form-control-custom">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control form-control-custom">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Téléphone</label>
                                    <input type="text" name="telephone" class="form-control form-control-custom">
                                </div>
                                <button type="submit" class="btn btn-success-custom w-100"
                                        <?= !$esp32Online ? 'disabled' : '' ?>>
                                    <i class="fas fa-fingerprint"></i> Démarrer l'enrôlement
                                </button>
                            </div>
                            <div class="col-md-6">
                                <div class="fingerprint-visual">
                                    <i class="fas fa-fingerprint fingerprint-icon"></i>
                                </div>
                                <div class="text-center">
                                    <p class="text-muted">Placez votre doigt sur le capteur lors de l'enrôlement</p>
                                    <?php if (!$esp32Online): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        ESP32 non joignable (<?= MQTT_HOST ?>:<?= MQTT_PORT ?>).
                                        Vérifiez la connexion WiFi.
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-circle-check"></i>
                                        ESP32 en ligne — prêt pour l'enrôlement.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Carte Liste des empreintes -->
            <div class="main-card mb-4">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-list"></i> Empreintes Enregistrées</h4>
                </div>
                <div class="card-body-custom">
                    <?php if (!empty($fingerprints)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID Empreinte</th>
                                    <th>Employé</th>
                                    <th>Poste</th>
                                    <th>Date Enregistrement</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fingerprints as $fp): ?>
                                <tr>
                                    <td><strong>#<?= $fp['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($fp['employee_name']) ?></td>
                                    <td><?= htmlspecialchars($fp['employee_poste']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($fp['date_creation'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="showDeleteModal(<?= $fp['employee_id'] ?>, '<?= addslashes($fp['employee_name']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Aucune empreinte enregistrée</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Carte Journal d'activité -->
            <div class="main-card">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-terminal"></i> Journal d'Activité</h4>
                </div>
                <div class="card-body-custom">
                    <div class="log-container" id="logContainer">
                        <div class="log-entry">
                            <span class="text-muted">[<?= date('H:i:s') ?>]</span>
                            <span class="text-success">Système initialisé</span>
                        </div>
                        <?php if ($esp32Online): ?>
                        <div class="log-entry">
                            <span class="text-muted">[<?= date('H:i:s') ?>]</span>
                            <span class="text-primary">ESP32 joignable (<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>
                        </div>
                        <?php else: ?>
                        <div class="log-entry">
                            <span class="text-muted">[<?= date('H:i:s') ?>]</span>
                            <span class="text-warning">ESP32 non joignable — mode hors-ligne</span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($fingerprints)): ?>
                        <div class="log-entry">
                            <span class="text-muted">[<?= date('H:i:s') ?>]</span>
                            <span class="text-info"><?= count($fingerprints) ?> empreinte(s) chargée(s)</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted"><i class="fas fa-info-circle"></i> Logs système en temps réel</span>
                        <div>
                            <button class="btn btn-outline-info btn-sm me-2" onclick="pingESP32()">
                                <i class="fas fa-satellite-dish"></i> PING ESP32
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="clearLogs()">
                                <i class="fas fa-broom"></i> Effacer les logs
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Colonne droite - Actions secondaires -->
        <div class="col-lg-4">

            <!-- Actions rapides -->
            <div class="main-card mb-4">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-bolt"></i> Actions Rapides</h4>
                </div>
                <div class="card-body-custom">
                    <div class="d-grid gap-2">

                        <!-- Ping ESP32 -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="ping_esp32">
                            <button type="submit" class="btn btn-primary-custom w-100">
                                <i class="fas fa-wifi"></i> Tester connexion ESP32
                            </button>
                        </form>

                        <!-- Synchronisation -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="sync_data">
                            <button type="submit" class="btn btn-warning-custom w-100"
                                    <?= !$esp32Online ? 'disabled' : '' ?>>
                                <i class="fas fa-sync-alt"></i> Synchroniser données
                            </button>
                        </form>

                        <!-- Tout effacer -->
                        <button type="button" class="btn btn-danger-custom w-100" onclick="confirmClearAll()">
                            <i class="fas fa-broom"></i> Tout effacer
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statut du système -->
            <div class="main-card">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-info-circle"></i> Statut du Système</h4>
                </div>
                <div class="card-body-custom">
                    <div class="mb-3 d-flex justify-content-between">
                        <span>Capteur ESP32 :</span>
                        <span class="status-badge <?= $esp32Online ? 'bg-success' : 'bg-danger' ?>">
                            <?= $esp32Online ? 'Connecté' : 'Déconnecté' ?>
                        </span>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span>Adresse IP :</span>
                        <span class="text-muted font-monospace"><?= MQTT_HOST ?>:<?= MQTT_PORT ?></span>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span>Base de données :</span>
                        <span class="status-badge bg-success">Opérationnelle</span>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span>Dernière sync :</span>
                        <span class="text-muted">
                            <?= $stats['last_sync'] ? date('d/m/Y H:i', strtotime($stats['last_sync'])) : 'Jamais' ?>
                        </span>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span>Slots libres :</span>
                        <span class="text-muted"><?= 127 - $stats['employees_with_fingerprint'] ?> / 127</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Suppression employé -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Supprimer l'employé et son empreinte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="employee_id" id="deleteEmployeeId">
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer <strong id="deleteEmployee"></strong> et son empreinte ?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Cette action est irréversible et supprime l'empreinte du capteur ET les données de l'employé.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmez en tapant <strong>CONFIRM</strong></label>
                        <input type="text" name="confirmation" class="form-control"
                               placeholder="CONFIRM" required pattern="CONFIRM">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Supprimer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tout effacer (avec décompte) -->
<div class="modal fade" id="clearAllModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Suppression totale</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="countdownText" class="fw-bold fs-4 text-danger">Décompte : 5</p>
                <p class="text-muted">Toutes les empreintes seront effacées du capteur ET de la base de données.</p>
                <div id="confirmButtons" class="d-none">
                    <p class="fw-bold text-danger">⚠️ Voulez-vous vraiment tout effacer ?</p>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="clear_all">
                        <button type="submit" class="btn btn-danger me-2">Oui, supprimer</button>
                    </form>
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/mqtt@5.3.4/dist/mqtt.min.js"></script>
<script>
// =====================================================================
// Logs
// =====================================================================
function addLog(message, type = 'info') {
    const logContainer = document.getElementById('logContainer');
    const timestamp    = new Date().toLocaleTimeString();
    const logEntry     = document.createElement('div');
    logEntry.className = 'log-entry';
    const colors = { success: 'text-success', warning: 'text-warning', error: 'text-danger', info: 'text-info' };
    logEntry.innerHTML = `<span class="text-muted">[${timestamp}]</span> <span class="${colors[type] || 'text-info'}">${message}</span>`;
    logContainer.appendChild(logEntry);
    logContainer.scrollTop = logContainer.scrollHeight;
}

function clearLogs() {
    const logContainer = document.getElementById('logContainer');
    logContainer.innerHTML = '<div class="text-center text-muted"><i class="fas fa-check-circle fa-2x mb-2"></i><p>Logs effacés</p></div>';
    setTimeout(() => { logContainer.innerHTML = ''; addLog('Journal effacé', 'info'); }, 1500);
}

// =====================================================================
// MQTT WebSocket — connexion temps réel
// =====================================================================
const MQTT_WS_URL       = 'ws://<?= MQTT_HOST ?>:<?= MQTT_WS_PORT ?>';
const MQTT_TOPIC_STATUS = '<?= MQTT_TOPIC_STATUS ?>';
const MQTT_TOPIC_CMD    = '<?= MQTT_TOPIC_CMD ?>';
let mqttClient    = null;
let mqttConnected = false;

function mqttInit() {
    mqttClient = mqtt.connect(MQTT_WS_URL, {
        clientId: 'bioaccess_gestion_' + Math.random().toString(36).slice(2, 8),
        clean: true,
        reconnectPeriod: 3000,
    });
    mqttClient.on('connect', () => {
        mqttConnected = true;
        updateESP32Status(true);
        addLog('📡 MQTT connecté au broker', 'success');
        mqttClient.subscribe(MQTT_TOPIC_STATUS);
    });
    mqttClient.on('reconnect', () => addLog('🔄 Reconnexion MQTT…', 'warning'));
    mqttClient.on('disconnect', () => { mqttConnected = false; updateESP32Status(false); });
    mqttClient.on('error', err => addLog('❌ MQTT : ' + err.message, 'error'));
    mqttClient.on('message', (topic, raw) => {
        let data = {};
        try { data = JSON.parse(raw.toString()); } catch {}
        if (data.pong) addLog('✅ PONG reçu de l\'ESP32', 'success');
        if (data.online !== undefined) updateESP32Status(data.online);
    });
}

async function pingESP32() {
    if (!mqttConnected) { addLog('⚠️ MQTT non connecté', 'error'); return; }
    addLog('📡 Envoi PING via MQTT (' + '<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)...', 'info');
    // Texte brut — cohérent avec esp32SendCommand("PING") côté PHP
    mqttClient.publish(MQTT_TOPIC_CMD, 'PING', { qos: 1 });
    addLog('📤 PING publié — attente du PONG...', 'info');
}

// =====================================================================
// Mise à jour du badge de statut
// =====================================================================
function updateESP32Status(online) {
    const badge = document.getElementById('esp32StatusBadge');
    if (!badge) return;
    if (online) {
        badge.className = 'wifi-status connected';
        badge.innerHTML = '<i class="fas fa-wifi"></i> MQTT Connecté <span class="esp32-ip">(<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>';
    } else {
        badge.className = 'wifi-status disconnected';
        badge.innerHTML = '<i class="fas fa-wifi"></i> MQTT Hors-ligne <span class="esp32-ip">(<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>';
    }
}

// =====================================================================
// Modal suppression
// =====================================================================
function showDeleteModal(employeeId, employeeName) {
    document.getElementById('deleteEmployeeId').value = employeeId;
    document.getElementById('deleteEmployee').textContent = employeeName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// =====================================================================
// Modal tout effacer (décompte 5 secondes)
// =====================================================================
function confirmClearAll() {
    const modal = new bootstrap.Modal(document.getElementById('clearAllModal'));
    document.getElementById('countdownText').classList.remove('d-none');
    document.getElementById('confirmButtons').classList.add('d-none');
    modal.show();

    let countdown = 5;
    document.getElementById('countdownText').textContent = 'Décompte : ' + countdown;
    const interval = setInterval(() => {
        countdown--;
        document.getElementById('countdownText').textContent = 'Décompte : ' + countdown;
        if (countdown <= 0) {
            clearInterval(interval);
            document.getElementById('countdownText').classList.add('d-none');
            document.getElementById('confirmButtons').classList.remove('d-none');
        }
    }, 1000);
}

// =====================================================================
// Initialisation
// =====================================================================
document.addEventListener('DOMContentLoaded', function () {
    // Animations hover sur les cartes
    document.querySelectorAll('.stat-card, .main-card').forEach(card => {
        card.addEventListener('mouseenter', () => card.style.transform = 'translateY(-5px)');
        card.addEventListener('mouseleave', () => card.style.transform = 'translateY(0)');
    });

    setTimeout(() => addLog('Système prêt', 'info'), 800);
    setTimeout(() => addLog('Broker MQTT <?= MQTT_HOST ?>:<?= MQTT_PORT ?>', 'info'), 1200);
    setTimeout(() => mqttInit(), 1400); // Connexion MQTT WebSocket

    <?php if ($esp32Online): ?>
    setTimeout(() => addLog('✅ Broker MQTT joignable au chargement de la page', 'success'), 1600);
    <?php else: ?>
    setTimeout(() => addLog('⚠️ Broker MQTT non joignable — vérifiez Mosquitto', 'warning'), 1600);
    <?php endif; ?>

    // Désactiver les boutons de soumission pendant le traitement
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...'; }
        });
    });

    <?php if (!empty($message)): ?>
    addLog('<?= $message_type === "success" ? "✅" : "❌" ?> <?= addslashes($message) ?>', '<?= $message_type === "success" ? "success" : "error" ?>');
    <?php endif; ?>
});
</script>
</body>
</html>
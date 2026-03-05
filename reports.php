<?php
// reports.php — Rapports analytiques
require_once __DIR__ . '/config.php';
// mqtt_config.php non nécessaire ici — statut ESP32 géré par MQTT WebSocket (JS)

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = Database::getInstance();
$db       = $database->getConnection();

// ── Statut initial : broker joignable ? (l'état ESP32 précis vient du MQTT WebSocket JS) ──
$esp32Online = mqttIsOnline(); // true = broker OK (ESP32 détecté en push via JS)
$esp32Status = 'unknown';     // sera mis à jour immédiatement par MQTT WebSocket

// ── Paramètres & validation ───────────────────────────────────────────────────
$currentYear  = (int)date('Y');
$currentMonth = (int)date('m');
$currentWeek  = date('Y-\WW');

$reportType       = in_array($_GET['type'] ?? '', ['weekly', 'monthly']) ? $_GET['type'] : 'weekly';
$selectedWeek     = $_GET['week']     ?? $currentWeek;
$selectedYear     = (int)($_GET['year']     ?? $currentYear);
$selectedMonth    = (int)($_GET['month']    ?? $currentMonth);
$selectedEmployee = (int)($_GET['employee'] ?? 0);

if (!preg_match('/^\d{4}-W\d{2}$/', $selectedWeek)) $selectedWeek = $currentWeek;
if ($selectedYear  < 2020 || $selectedYear  > $currentYear + 1) $selectedYear  = $currentYear;
if ($selectedMonth < 1    || $selectedMonth > 12)                $selectedMonth = $currentMonth;

// ── Dates de la période ───────────────────────────────────────────────────────
[$yearW, $weekN] = explode('-W', $selectedWeek);
$weekStart  = date('Y-m-d', strtotime($yearW . 'W' . $weekN));
$weekEnd    = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$monthStart = date('Y-m-01', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
$monthEnd   = date('Y-m-t',  strtotime($monthStart));

// ── Fonctions de requête ──────────────────────────────────────────────────────
function getGeneralStats(SQLite3 $db, string $start, string $end, int $empId = 0): array
{
    $empCond = $empId > 0 ? " AND employee_id = $empId" : '';
    $stmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN type_pointage='ENTREE' THEN 1 ELSE 0 END) as entries,
               SUM(CASE WHEN type_pointage='SORTIE' THEN 1 ELSE 0 END) as exits,
               COUNT(DISTINCT employee_id) as unique_employees
        FROM pointages
        WHERE datetime BETWEEN ? AND ?$empCond
    ");
    $stmt->bindValue(1, $start . ' 00:00:00', SQLITE3_TEXT);
    $stmt->bindValue(2, $end   . ' 23:59:59', SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC)
        ?: ['total' => 0, 'entries' => 0, 'exits' => 0, 'unique_employees' => 0];
}

function getDailyStats(SQLite3 $db, string $start, string $end, int $empId = 0): array
{
    $empCond = $empId > 0 ? " AND employee_id = $empId" : '';
    $stmt = $db->prepare("
        SELECT DATE(datetime) as date,
               COUNT(*) as total,
               SUM(CASE WHEN type_pointage='ENTREE' THEN 1 ELSE 0 END) as entries,
               SUM(CASE WHEN type_pointage='SORTIE' THEN 1 ELSE 0 END) as exits
        FROM pointages
        WHERE datetime BETWEEN ? AND ?$empCond
        GROUP BY DATE(datetime)
        ORDER BY date
    ");
    $stmt->bindValue(1, $start . ' 00:00:00', SQLITE3_TEXT);
    $stmt->bindValue(2, $end   . ' 23:59:59', SQLITE3_TEXT);
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}

function getMonthlyEmployeeStats(SQLite3 $db, string $start, string $end): array
{
    $stmt = $db->prepare("
        SELECT e.id, e.nom, e.prenom, e.poste,
               COUNT(p.id) as total_pointages,
               SUM(CASE WHEN p.type_pointage='ENTREE' THEN 1 ELSE 0 END) as entries,
               SUM(CASE WHEN p.type_pointage='SORTIE' THEN 1 ELSE 0 END) as exits
        FROM employees e
        LEFT JOIN pointages p ON e.id = p.employee_id AND p.datetime BETWEEN ? AND ?
        GROUP BY e.id
        ORDER BY total_pointages DESC, e.nom
    ");
    $stmt->bindValue(1, $start . ' 00:00:00', SQLITE3_TEXT);
    $stmt->bindValue(2, $end   . ' 23:59:59', SQLITE3_TEXT);
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}

function getHourlyStats(SQLite3 $db, string $start, string $end): array
{
    $stmt = $db->prepare("
        SELECT CAST(strftime('%H', datetime) AS INTEGER) as hour,
               COUNT(*) as total
        FROM pointages
        WHERE datetime BETWEEN ? AND ?
        GROUP BY hour ORDER BY hour
    ");
    $stmt->bindValue(1, $start . ' 00:00:00', SQLITE3_TEXT);
    $stmt->bindValue(2, $end   . ' 23:59:59', SQLITE3_TEXT);
    $res = $stmt->execute();
    $byH = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $byH[(int)$r['hour']] = (int)$r['total'];
    $out = [];
    for ($h = 6; $h <= 20; $h++) $out[] = $byH[$h] ?? 0;
    return $out;
}

// ── Récupération des données ──────────────────────────────────────────────────
if ($reportType === 'weekly') {
    $stats      = getGeneralStats($db, $weekStart, $weekEnd, $selectedEmployee);
    $dailyStats = getDailyStats($db, $weekStart, $weekEnd, $selectedEmployee);
    $employees  = [];
    $hourlyData = [];
} else {
    $stats      = getGeneralStats($db, $monthStart, $monthEnd, $selectedEmployee);
    $employees  = getMonthlyEmployeeStats($db, $monthStart, $monthEnd);
    $hourlyData = getHourlyStats($db, $monthStart, $monthEnd);
    $dailyStats = [];
}

$allEmployeesRows = [];
$res = $db->query("SELECT id, nom, prenom FROM employees ORDER BY nom, prenom");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $allEmployeesRows[] = $r;

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rapport_' . $reportType . '_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if ($reportType === 'weekly') {
        fputcsv($out, ['Jour', 'Total', 'Entrées', 'Sorties'], ';');
        foreach ($dailyStats as $d) {
            fputcsv($out, [date('d/m/Y', strtotime($d['date'])), $d['total'], $d['entries'], $d['exits']], ';');
        }
    } else {
        fputcsv($out, ['Employé', 'Poste', 'Total Pointages', 'Entrées', 'Sorties'], ';');
        foreach ($employees as $e) {
            fputcsv($out, [$e['prenom'] . ' ' . $e['nom'], $e['poste'] ?? '', $e['total_pointages'], $e['entries'], $e['exits']], ';');
        }
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #4361ee; --secondary: #3a0ca3; }
        body { background: linear-gradient(135deg,#f8f9fa,#e9ecef); min-height: 100vh; font-family: 'Segoe UI', sans-serif; }

        .card-report { border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(0,0,0,.1); background: white; margin-bottom: 25px; }
        .card-body   { padding: 24px; }

        .stat-card { color: white; border-radius: 18px; padding: 22px; text-align: center;
                     background: linear-gradient(135deg,#667eea,#764ba2);
                     box-shadow: 0 6px 18px rgba(0,0,0,.12); transition: transform .2s; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-number { font-size: 2.5rem; font-weight: 700; margin: 10px 0; }

        .filter-section { background: white; border-radius: 18px; padding: 22px; margin-bottom: 22px;
                          box-shadow: 0 6px 18px rgba(0,0,0,.08); }

        .nav-pills .nav-link { border-radius: 12px; padding: 10px 22px; margin: 4px; font-weight: 500; }
        .nav-pills .nav-link.active { background: linear-gradient(135deg,var(--primary),var(--secondary)); }

        .timeline-day { background: #f8f9ff; border-radius: 12px; padding: 16px; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 16px; }
        .summary-item { background: #f8f9ff; border-radius: 14px; padding: 18px; text-align: center; }

        .badge-presence { padding: 8px 14px; border-radius: 20px; font-weight: 600; font-size: .85rem; }

        /* ── MQTT status bar ─────────────────────────────────────────────── */
        .mqtt-statusbar {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            border-radius: 14px;
            padding: 10px 18px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: .88rem;
            box-shadow: 0 3px 12px rgba(0,0,0,.1);
        }
        .mqtt-dot {
            width: 10px; height: 10px; border-radius: 50%;
            display: inline-block; margin-right: 4px;
            animation: mqttpulse 2s ease-in-out infinite;
        }
        .mqtt-dot.online  { background: #06d6a0; box-shadow: 0 0 0 0 rgba(6,214,160,.6); }
        .mqtt-dot.offline { background: #ef476f; animation: none; }
        @keyframes mqttpulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(6,214,160,.6); }
            50%      { box-shadow: 0 0 0 6px rgba(6,214,160,0); }
        }
        .mqtt-toast {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            min-width: 300px; border-radius: 14px;
            padding: 14px 18px; color: white; font-weight: 500;
            box-shadow: 0 10px 30px rgba(0,0,0,.2);
            animation: toastIn .35s ease;
            display: none;
        }
        @keyframes toastIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .mqtt-toast.show   { display: block; }
        .mqtt-toast.success { background: linear-gradient(135deg,#06d6a0,#059669); }
        .mqtt-toast.danger  { background: linear-gradient(135deg,#ef476f,#dc2626); }
        .mqtt-toast.warning { background: linear-gradient(135deg,#fca311,#e07b00); }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .card-report { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body>
<div class="container py-4">

    <!-- ── Titre + barre statut ESP32 ───────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
        <div>
            <h2 class="mb-1 text-primary"><i class="fas fa-chart-bar me-2"></i>Rapports Analytiques</h2>
            <p class="text-muted mb-0">Analyse des données de présence</p>
        </div>

        <div class="d-flex flex-column align-items-end gap-2">
            <!-- Widget statut ESP32 — mis à jour par esp32_status.php (DB, non bloquant) -->
            <div class="mqtt-statusbar no-print">
                <span class="mqtt-dot <?= $esp32Status ?>" id="mqttDot"></span>
                <span id="mqttLabel"><?= $esp32Online ? 'ESP32 connecté' : 'ESP32 hors-ligne' ?></span>
                <span class="text-muted" style="font-size:.78rem;" id="mqttLastCheck">
                    Vérifié à <?= date('H:i:s') ?>
                </span>
                <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                        onclick="checkESP32Now()" title="Vérifier maintenant">
                    <i class="fas fa-sync-alt fa-xs" id="refreshIcon"></i>
                </button>
            </div>

            <!-- Boutons d'action -->
            <div class="no-print d-flex gap-2 flex-wrap">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Imprimer
                </button>
                <a href="?export=csv&type=<?= $reportType ?>&week=<?= urlencode($selectedWeek) ?>&year=<?= $selectedYear ?>&month=<?= $selectedMonth ?>&employee=<?= $selectedEmployee ?>"
                   class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv me-1"></i>CSV
                </a>
                <a href="presence.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list-check me-1"></i>Présences
                </a>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-home me-1"></i>Accueil
                </a>
            </div>
        </div>
    </div>

    <!-- Alerte ESP32 hors-ligne -->
    <div id="mqttAlert" class="<?= $esp32Online ? 'd-none' : '' ?> alert alert-warning no-print d-flex align-items-center gap-2 mb-3">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="mqttAlertMsg">
            <?php if (!$esp32Online): ?>
            L'ESP32 est hors-ligne. Vérifiez que <code>mqtt_listener.php</code> est lancé,
            puis que l'ESP32 est connecté au WiFi.
            <?php endif; ?>
        </span>
        <button type="button" class="btn-close ms-auto" onclick="this.closest('.alert').classList.add('d-none')"></button>
    </div>

    <!-- Onglets -->
    <ul class="nav nav-pills mb-4 no-print">
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'weekly' ? 'active' : '' ?>"
               href="?type=weekly&week=<?= urlencode($selectedWeek) ?>&employee=<?= $selectedEmployee ?>">
                <i class="fas fa-calendar-week me-1"></i>Hebdomadaire
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'monthly' ? 'active' : '' ?>"
               href="?type=monthly&year=<?= $selectedYear ?>&month=<?= $selectedMonth ?>&employee=<?= $selectedEmployee ?>">
                <i class="fas fa-calendar-alt me-1"></i>Mensuel
            </a>
        </li>
    </ul>

    <!-- Filtres -->
    <div class="filter-section no-print">
        <?php if ($reportType === 'weekly'): ?>
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="type" value="weekly">
            <div class="col-md-4">
                <label class="form-label">Semaine</label>
                <input type="week" class="form-control" name="week"
                       value="<?= htmlspecialchars($selectedWeek) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-5">
                <label class="form-label">Employé</label>
                <select class="form-select" name="employee" onchange="this.form.submit()">
                    <option value="">Tous les employés</option>
                    <?php foreach ($allEmployeesRows as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $selectedEmployee == $e['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i>Filtrer
                </button>
            </div>
        </form>
        <?php else: ?>
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="type" value="monthly">
            <div class="col-md-3">
                <label class="form-label">Année</label>
                <select class="form-select" name="year" onchange="this.form.submit()">
                    <?php for ($y = 2023; $y <= $currentYear; $y++): ?>
                    <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mois</label>
                <select class="form-select" name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Employé</label>
                <select class="form-select" name="employee" onchange="this.form.submit()">
                    <option value="">Tous</option>
                    <?php foreach ($allEmployeesRows as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $selectedEmployee == $e['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Alerte période -->
    <div class="alert alert-info no-print">
        <i class="fas fa-calendar-alt me-2"></i>Période :
        <strong>
            <?php if ($reportType === 'weekly'): ?>
                <?= date('d/m/Y', strtotime($weekStart)) ?> au <?= date('d/m/Y', strtotime($weekEnd)) ?>
            <?php else: ?>
                <?= date('F Y', strtotime($monthStart)) ?>
            <?php endif; ?>
        </strong>
        <span class="ms-3 small <?= $esp32Online ? 'text-success' : 'text-warning' ?>" id="realtimeLabel">
            <i class="fas fa-circle me-1"></i>
            <?= $esp32Online ? 'Données temps réel actives' : 'Mode hors-ligne — données EEPROM' ?>
        </span>
    </div>

    <!-- Cartes stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <i class="fas fa-clock fa-lg mb-1"></i>
                <div class="stat-number"><?= (int)$stats['total'] ?></div>
                <small>Total Pointages</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg,#4ecdc4,#44a08d);">
                <i class="fas fa-sign-in-alt fa-lg mb-1"></i>
                <div class="stat-number"><?= (int)$stats['entries'] ?></div>
                <small>Entrées</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg,#ff6b6b,#ee5a52);">
                <i class="fas fa-sign-out-alt fa-lg mb-1"></i>
                <div class="stat-number"><?= (int)$stats['exits'] ?></div>
                <small>Sorties</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg,#fca311,#f77f00);">
                <i class="fas fa-users fa-lg mb-1"></i>
                <div class="stat-number"><?= (int)$stats['unique_employees'] ?></div>
                <small>Employés Actifs</small>
            </div>
        </div>
    </div>

    <!-- ══ HEBDOMADAIRE ══════════════════════════════════════════════════════ -->
    <?php if ($reportType === 'weekly'): ?>

    <div class="row g-4">
        <div class="col-md-8">
            <div class="card-report">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-chart-bar text-primary me-1"></i>Activité par Jour</h5>
                    <canvas id="weeklyChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-report">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-chart-pie text-primary me-1"></i>Répartition</h5>
                    <canvas id="distributionChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($dailyStats)): ?>
    <div class="card-report mt-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="fas fa-calendar-day text-primary me-1"></i>Détails par Jour</h5>
            <div class="row g-3">
                <?php foreach ($dailyStats as $day): ?>
                <div class="col-md-4">
                    <div class="timeline-day">
                        <h6><?= date('l d/m', strtotime($day['date'])) ?></h6>
                        <span class="badge-presence me-1" style="background:#4ecdc4;color:white;">
                            <i class="fas fa-sign-in-alt me-1"></i><?= $day['entries'] ?> entrées
                        </span>
                        <span class="badge-presence" style="background:#ff6b6b;color:white;">
                            <i class="fas fa-sign-out-alt me-1"></i><?= $day['exits'] ?> sorties
                        </span>
                        <div class="mt-2">
                            <small class="text-muted">Total : <?= $day['total'] ?> pointages</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-light text-center">Aucune donnée pour cette semaine.</div>
    <?php endif; ?>

    <!-- ══ MENSUEL ═══════════════════════════════════════════════════════════ -->
    <?php else: ?>

    <div class="card-report">
        <div class="card-body">
            <h5 class="mb-3"><i class="fas fa-users text-primary me-1"></i>Performance des Employés</h5>
            <?php if (!empty($employees)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>Employé</th><th>Poste</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Entrées</th>
                            <th class="text-center">Sorties</th>
                            <th class="text-center">Présence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['prenom'] . ' ' . $emp['nom']) ?></strong></td>
                            <td><small class="text-muted"><?= htmlspecialchars($emp['poste'] ?? '') ?></small></td>
                            <td class="text-center"><?= $emp['total_pointages'] ?></td>
                            <td class="text-center text-success"><?= $emp['entries'] ?></td>
                            <td class="text-center text-danger"><?= $emp['exits'] ?></td>
                            <td class="text-center">
                                <?php $tx = $emp['total_pointages'] > 0 ? round(($emp['entries'] / $emp['total_pointages']) * 100, 1) : 0; ?>
                                <div class="progress" style="height:8px;min-width:80px;">
                                    <div class="progress-bar bg-success" style="width:<?= $tx ?>%"></div>
                                </div>
                                <small><?= $tx ?>%</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted text-center py-4">Aucune donnée pour ce mois.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card-report">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-chart-bar text-primary me-1"></i>Top 5 Employés</h5>
                    <canvas id="topEmployeesChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-report">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-clock text-primary me-1"></i>Activité par Heure</h5>
                    <canvas id="hoursChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- Résumé -->
    <div class="card-report mt-2">
        <div class="card-body">
            <h5 class="mb-3"><i class="fas fa-info-circle text-primary me-1"></i>Résumé</h5>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="text-muted small mb-1">Total Pointages</div>
                    <h3 class="text-primary"><?= (int)$stats['total'] ?></h3>
                </div>
                <div class="summary-item">
                    <div class="text-muted small mb-1">Entrées</div>
                    <h3 class="text-success"><?= (int)$stats['entries'] ?></h3>
                    <small class="text-muted">
                        <?= $stats['total'] > 0 ? round(($stats['entries'] / $stats['total']) * 100, 1) : 0 ?>%
                    </small>
                </div>
                <div class="summary-item">
                    <div class="text-muted small mb-1">Sorties</div>
                    <h3 class="text-danger"><?= (int)$stats['exits'] ?></h3>
                    <small class="text-muted">
                        <?= $stats['total'] > 0 ? round(($stats['exits'] / $stats['total']) * 100, 1) : 0 ?>%
                    </small>
                </div>
                <div class="summary-item">
                    <div class="text-muted small mb-1">Employés Actifs</div>
                    <h3 class="text-warning"><?= (int)$stats['unique_employees'] ?></h3>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container -->

<!-- Toast -->
<div class="mqtt-toast" id="mqttToast"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const mqttDot       = document.getElementById('mqttDot');
    const mqttLabel     = document.getElementById('mqttLabel');
    const mqttLastCheck = document.getElementById('mqttLastCheck');
    const mqttAlert     = document.getElementById('mqttAlert');
    const mqttAlertMsg  = document.getElementById('mqttAlertMsg');
    const realtimeLabel = document.getElementById('realtimeLabel');
    const mqttToast     = document.getElementById('mqttToast');
    const refreshIcon   = document.getElementById('refreshIcon');

    let lastStatus = '<?= $esp32Status ?>';
    let toastTimer = null;
    let checking   = false;

    function showToast(message, type) {
        mqttToast.textContent = message;
        mqttToast.className   = `mqtt-toast show ${type}`;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => mqttToast.classList.remove('show'), 5000);
    }

    function updateBadge(online, data) {
        const status = online ? 'online' : 'offline';
        mqttDot.className     = `mqtt-dot ${status}`;
        mqttLabel.textContent = online ? 'ESP32 connecté' : 'ESP32 hors-ligne';
        mqttLastCheck.textContent = 'Vérifié à ' + new Date().toLocaleTimeString('fr-FR');

        // Alerte
        if (online) {
            mqttAlert.classList.add('d-none');
        } else {
            let msg = "L'ESP32 est hors-ligne.";
            if (data && !data.broker_ok) {
                msg += ' Le broker Mosquitto ne répond pas sur le port ' +
                       (data.mqtt_port || 1883) + '.';
            } else if (data && !data.listener_ok) {
                msg += ' <code>mqtt_listener.php</code> ne semble pas lancé — aucune donnée en DB.';
            } else if (data && data.last_seen_s > 90) {
                msg += ` Dernier signal reçu il y a ${data.last_seen_s}s (> 90s).`;
            }
            mqttAlertMsg.innerHTML = msg;
            mqttAlert.classList.remove('d-none');
        }

        // Label "temps réel" dans la barre de période
        if (realtimeLabel) {
            realtimeLabel.className = `ms-3 small ${online ? 'text-success' : 'text-warning'}`;
            realtimeLabel.innerHTML = `<i class="fas fa-circle me-1"></i>` +
                (online ? 'Données temps réel actives' : 'Mode hors-ligne — données EEPROM');
        }

        // Toast si changement d'état
        if (status !== lastStatus) {
            showToast(
                online ? '✅ ESP32 reconnecté !' : '⚠️ ESP32 hors-ligne',
                online ? 'success' : 'danger'
            );
            lastStatus = status;
        }
    }

    // ── MQTT WebSocket : écoute directe bioaccess/esp32/status ───────────────
    // Plus de polling HTTP esp32_status.php — le badge se met à jour en push
    // dès que l'ESP32 envoie son heartbeat toutes les 30 s.

    const TOPIC_STATUS = '<?= MQTT_TOPIC_STATUS ?>'; // bioaccess/esp32/status
    const WS_URL       = 'ws://<?= MQTT_HOST ?>:<?= MQTT_WS_PORT ?>';

    let mqttClient  = null;
    let lastStatus  = 'unknown';
    let statusTimer = null; // timer de timeout si plus de heartbeat

    // Réutilise le client global de header.php s'il existe déjà
    function getMqttClient() {
        return window.siteMqtt || null;
    }

    function onEsp32Status(data) {
        // Réinitialiser le timer de timeout (90 s = 3× l'intervalle de 30 s)
        clearTimeout(statusTimer);
        statusTimer = setTimeout(() => updateBadge(false, null), 90_000);

        const online = true; // message reçu = ESP32 en ligne
        updateBadge(online, data);
    }

    // Écouter les messages MQTT via l'event global dispatché par header.php
    window.addEventListener('mqtt:message', (e) => {
        const { topic, data } = e.detail;
        if (topic === TOPIC_STATUS) {
            onEsp32Status(data);
        }
    });

    // Si header.php n'est pas inclus, créer notre propre client MQTT
    function startOwnMqttClient() {
        if (typeof mqtt === 'undefined') return;
        if (mqttClient) return;

        mqttClient = mqtt.connect(WS_URL, {
            clientId: 'reports_' + Math.random().toString(36).slice(2, 8),
            clean: true,
            reconnectPeriod: 5000,
        });

        mqttClient.on('connect', () => {
            mqttClient.subscribe(TOPIC_STATUS);
        });

        mqttClient.on('message', (topic, raw) => {
            let data = {};
            try { data = JSON.parse(raw.toString()); } catch {}
            if (topic === TOPIC_STATUS) onEsp32Status(data);
        });
    }

    // Vérifier si siteMqtt (header.php) est déjà abonné à TOPIC_STATUS
    // Sinon créer notre propre connexion après 1 s
    setTimeout(() => {
        if (!window.siteMqtt) startOwnMqttClient();
        // Timeout initial : si aucun message dans les 10 s → hors-ligne
        statusTimer = setTimeout(() => {
            if (lastStatus === 'unknown') updateBadge(false, null);
        }, 10_000);
    }, 800);

    // Bouton "Vérifier maintenant" : envoyer un PING via le client MQTT
    async function checkESP32Now() {
        refreshIcon.classList.add('fa-spin');
        const client = getMqttClient() || mqttClient;
        if (client && client.connected) {
            client.publish('<?= MQTT_TOPIC_CMD ?>', 'PING');
            // La réponse PONG arrivera sur bioaccess/esp32/response,
            // le heartbeat suivant sur bioaccess/esp32/status mettra à jour le badge.
        } else {
            // Fallback : appel esp_statut.php (fonctionne même sans mqtt_listener)
            try {
                const resp = await fetch('esp_statut.php', { cache: 'no-store' });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                // Si broker OK mais pas de listener → badge "en attente"
                if (data.broker_ok && !data.listener_ok) {
                    mqttDot.className = 'mqtt-dot connecting';
                    mqttLabel.textContent = 'Broker OK — attente ESP32…';
                    mqttLastCheck.textContent = 'Vérifié à ' + new Date().toLocaleTimeString('fr-FR');
                } else {
                    updateBadge(data.online === true, data);
                }
            } catch (e) {
                updateBadge(false, null);
            }
        }
        setTimeout(() => refreshIcon.classList.remove('fa-spin'), 1500);
    }
});
</script>
</body>
</html>
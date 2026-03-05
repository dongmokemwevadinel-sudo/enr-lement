<?php
/**
 * sync_esp32.php — Synchronisation ESP32 ↔ Base de données via MQTT
 * ===================================================================
 *
 * FLUX MQTT (remplace le protocole TCP conversationnel) :
 *
 *   PHP publie   → bioaccess/esp32/command   { cmd:"SYNC" }
 *   ESP32 publie → bioaccess/sync/begin      { count: N }
 *   ESP32 publie → bioaccess/sync/entry      { seq, fp_id, datetime }
 *   PHP publie   → bioaccess/sync/ack        { seq }   (insertion OK)
 *   PHP publie   → bioaccess/sync/nack       { seq }   (erreur, ESP32 conserve)
 *   ESP32 publie → bioaccess/sync/end        { synced: N }
 *
 * MODE PUSH MQTT :
 *   L'ESP32 publie directement un pointage sur bioaccess/sync/push
 *   { fp_id, datetime } sans passer par le cycle SYNC complet.
 *
 * APPELS :
 *   CLI  : php sync_esp32.php
 *   HTTP : sync_esp32.php?token=VOTRE_TOKEN
 *   AJAX : sync_esp32.php?token=...&format=json
 *   CRON : voir cron_sync.sh
 *
 * PRÉ-REQUIS :
 *   - Mosquitto installé + mosquitto_sub/mosquitto_pub en CLI
 *   - Broker MQTT en écoute sur MQTT_HOST:MQTT_PORT
 * ===================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// ── Topics MQTT spécifiques à la synchronisation ─────────────
define('TOPIC_SYNC_BEGIN', 'bioaccess/sync/begin');  // ESP32 → PHP
define('TOPIC_SYNC_ENTRY', 'bioaccess/sync/entry');  // ESP32 → PHP
define('TOPIC_SYNC_END',   'bioaccess/sync/end');    // ESP32 → PHP
define('TOPIC_SYNC_ACK',   'bioaccess/sync/ack');    // PHP → ESP32
define('TOPIC_SYNC_NACK',  'bioaccess/sync/nack');   // PHP → ESP32
define('TOPIC_SYNC_PUSH',  'bioaccess/sync/push');   // ESP32 → PHP (push direct)
define('SYNC_TIMEOUT',     60);                       // secondes max

// ── Sécurité ─────────────────────────────────────────────────
define('SYNC_CLI',   PHP_SAPI === 'cli');
define('SYNC_TOKEN', getenv('SYNC_TOKEN') ?: 'sync_secret_token_change_me');

if (!SYNC_CLI) {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
    if (!isset($_SESSION['user_id']) && !hash_equals(SYNC_TOKEN, $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Non autorisé']));
    }
}

$jsonOut = SYNC_CLI || (isset($_GET['format']) && $_GET['format'] === 'json');
if ($jsonOut) header('Content-Type: application/json');

// ── Mode PUSH HTTP (simulé depuis un POST) ───────────────────
if (!SYNC_CLI && ($_GET['action'] ?? '') === 'push') {
    header('Content-Type: application/json');
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $fp_id    = (int)($body['fp_id'] ?? $_POST['fingerprint_id'] ?? 0);
    $datetime = trim($body['datetime'] ?? $_POST['datetime'] ?? '');

    if ($fp_id < 1 || $fp_id > 127) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'fingerprint_id invalide']));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'datetime invalide (YYYY-MM-DD HH:MM:SS)']));
    }
    $db = Database::getInstance()->getConnection();
    die(json_encode(insertPointage($db, $fp_id, $datetime)));
}

// ── Résultat global ──────────────────────────────────────────
$result = [
    'success'     => false,
    'synced'      => 0,
    'skipped'     => 0,
    'errors'      => [],
    'log'         => [],
    'started_at'  => date('Y-m-d H:i:s'),
    'finished_at' => null,
];

function syncLog(string $msg, string $level = 'info'): void {
    global $result;
    $result['log'][] = ['t' => date('H:i:s'), 'l' => $level, 'm' => $msg];
    if (SYNC_CLI) {
        $p = ['info' => '[INFO]', 'ok' => '[ OK ]', 'warn' => '[WARN]', 'error' => '[ERR ]'][$level] ?? '[    ]';
        fwrite(STDOUT, "$p $msg\n");
    }
}

// ── Helpers DB ───────────────────────────────────────────────

function getEmployeeIdByFingerprint(SQLite3 $db, int $fp_id): ?int {
    $stmt = $db->prepare("SELECT id FROM employees WHERE fingerprint_id = ?");
    $stmt->bindValue(1, $fp_id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function determineTypePointage(SQLite3 $db, int $employee_id, string $sqlDate): string {
    $stmt = $db->prepare("
        SELECT type_pointage FROM pointages
        WHERE  employee_id = ? AND DATE(datetime) = ?
        ORDER  BY datetime DESC LIMIT 1
    ");
    $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $sqlDate,     SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return 'ENTREE';
    return $row['type_pointage'] === 'ENTREE' ? 'SORTIE' : 'ENTREE';
}

function pointageExists(SQLite3 $db, int $employee_id, string $datetime): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM pointages WHERE employee_id = ? AND datetime = ?
    ");
    $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $datetime,    SQLITE3_TEXT);
    return (bool)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];
}

function insertPointage(SQLite3 $db, int $fp_id, string $datetime): array {
    $sqlDate = substr($datetime, 0, 10);
    $empId   = getEmployeeIdByFingerprint($db, $fp_id);

    if ($empId === null) {
        return ['success' => false, 'error' => "Empreinte #$fp_id non assignée"];
    }
    if (pointageExists($db, $empId, $datetime)) {
        return ['success' => true, 'message' => 'Doublon ignoré', 'employee_id' => $empId];
    }

    $type = determineTypePointage($db, $empId, $sqlDate);

    try {
        $db->exec('BEGIN');

        $ins = $db->prepare("INSERT INTO pointages (employee_id, type_pointage, datetime) VALUES (?, ?, ?)");
        $ins->bindValue(1, $empId,    SQLITE3_INTEGER);
        $ins->bindValue(2, $type,     SQLITE3_TEXT);
        $ins->bindValue(3, $datetime, SQLITE3_TEXT);
        $ins->execute();

        $log = $db->prepare("
            INSERT INTO sync_log (sync_date, entries_synced, status, details)
            VALUES (datetime('now'), 1, 'success', ?)
        ");
        $log->bindValue(1, "fp=$fp_id emp=$empId $type $datetime", SQLITE3_TEXT);
        $log->execute();

        $db->exec('COMMIT');
        logActivity("MQTT pointage : fp=$fp_id emp=$empId $type $datetime");

        // Notifier le front-end en temps réel
        mqttPublish('bioaccess/events/pointage', json_encode([
            'employee_id' => $empId,
            'fp_id'       => $fp_id,
            'type'        => $type,
            'datetime'    => $datetime,
        ], JSON_UNESCAPED_UNICODE));

        return ['success' => true, 'employee_id' => $empId, 'type' => $type, 'datetime' => $datetime];

    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        logError("insertPointage erreur DB : " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ── Helpers MQTT CLI ─────────────────────────────────────────

function mqttSend(string $topic, array $payload): bool {
    return mqttPublish($topic, $payload)['success'];
}

/**
 * Souscrit à un topic et retourne les N premiers messages reçus
 * dans le délai imparti (via mosquitto_sub CLI).
 */
function mqttReceive(string $topic, int $timeout = 5, int $count = 1): array {
    $cmd = 'mosquitto_sub'
         . ' -h ' . escapeshellarg(MQTT_HOST)
         . ' -p ' . MQTT_PORT
         . ' -t ' . escapeshellarg($topic)
         . ' -W ' . $timeout
         . ' -C ' . $count
         . ' -q 1';

    if (MQTT_USER !== '') {
        $cmd .= ' -u ' . escapeshellarg(MQTT_USER)
              . ' -P ' . escapeshellarg(MQTT_PASS);
    }

    $output = [];
    exec($cmd . ' 2>/dev/null', $output);

    return array_filter(array_map(fn($l) => json_decode(trim($l), true), $output));
}

/**
 * Souscrit à plusieurs topics en même temps et lit tous les messages
 * jusqu'au timeout. Retourne [['topic'=>..., 'payload'=>...]].
 */
function mqttReceiveMulti(array $topics, int $timeout = 60): array {
    $topicArgs = implode(' ', array_map(fn($t) => '-t ' . escapeshellarg($t), $topics));

    $cmd = 'mosquitto_sub'
         . ' -h ' . escapeshellarg(MQTT_HOST)
         . ' -p ' . MQTT_PORT
         . ' ' . $topicArgs
         . ' -W ' . $timeout
         . ' -F "%t|||%p"'
         . ' -q 1';

    if (MQTT_USER !== '') {
        $cmd .= ' -u ' . escapeshellarg(MQTT_USER)
              . ' -P ' . escapeshellarg(MQTT_PASS);
    }

    $output = [];
    exec($cmd . ' 2>/dev/null', $output);

    $messages = [];
    foreach ($output as $line) {
        $parts = explode('|||', $line, 2);
        if (count($parts) === 2) {
            $data = json_decode(trim($parts[1]), true);
            if ($data !== null) {
                $messages[] = ['topic' => trim($parts[0]), 'payload' => $data];
            }
        }
    }
    return $messages;
}

// ── Vérification broker MQTT ─────────────────────────────────
syncLog('Vérification broker MQTT → ' . MQTT_HOST . ':' . MQTT_PORT);

if (!mqttIsOnline()) {
    $result['errors'][]    = "Broker MQTT injoignable : " . MQTT_HOST . ":" . MQTT_PORT;
    $result['finished_at'] = date('Y-m-d H:i:s');
    syncLog("Broker MQTT injoignable", 'error');
    if ($jsonOut) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}
syncLog('Broker MQTT joignable', 'ok');

// ── Publication commande SYNC ─────────────────────────────────
syncLog('→ SYNC publié sur ' . MQTT_TOPIC_CMD);
if (!mqttSend(MQTT_TOPIC_CMD, ['cmd' => 'SYNC'])) {
    $result['errors'][]    = "Impossible de publier la commande SYNC";
    $result['finished_at'] = date('Y-m-d H:i:s');
    syncLog("Échec publication SYNC", 'error');
    if ($jsonOut) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

// ── Attente BEGIN_SYNC (5 s) ──────────────────────────────────
syncLog("Attente BEGIN_SYNC de l'ESP32 (5 s max)...");
$beginMsgs = mqttReceive(TOPIC_SYNC_BEGIN, timeout: 5, count: 1);

if (empty($beginMsgs)) {
    syncLog("Pas de réponse BEGIN_SYNC — ESP32 absent ou aucune entrée en attente", 'warn');
    $result['success']     = true;
    $result['finished_at'] = date('Y-m-d H:i:s');
    if ($jsonOut) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$count = (int)($beginMsgs[0]['count'] ?? 0);
if ($count === 0) {
    syncLog("Aucune entrée en attente sur l'ESP32");
    $result['success']     = true;
    $result['finished_at'] = date('Y-m-d H:i:s');
    if ($jsonOut) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}
syncLog("$count entrée(s) à synchroniser", 'ok');

// ── Réception des ENTRY + END ─────────────────────────────────
$db       = Database::getInstance()->getConnection();
$messages = mqttReceiveMulti([TOPIC_SYNC_ENTRY, TOPIC_SYNC_END], timeout: SYNC_TIMEOUT);

foreach ($messages as $msg) {
    $topic   = $msg['topic'];
    $payload = $msg['payload'];

    // ── END_SYNC ──────────────────────────────────────────────
    if ($topic === TOPIC_SYNC_END) {
        syncLog("Synchronisation terminée par l'ESP32", 'ok');
        break;
    }

    // ── ENTRY ─────────────────────────────────────────────────
    if ($topic === TOPIC_SYNC_ENTRY) {
        $seq      = (int)($payload['seq']     ?? 0);
        $fp_id    = (int)($payload['fp_id']   ?? 0);
        $datetime = str_replace('/', '-', trim($payload['datetime'] ?? ''));

        syncLog("← ENTRY seq=$seq fp_id=$fp_id datetime=$datetime");

        if ($fp_id < 1 || $fp_id > 127 ||
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime)) {
            syncLog("  Format invalide → NACK $seq", 'warn');
            mqttSend(TOPIC_SYNC_NACK, ['seq' => $seq]);
            $result['skipped']++;
            $result['errors'][] = "Entrée invalide (seq $seq)";
            continue;
        }

        $res = insertPointage($db, $fp_id, $datetime);

        if ($res['success']) {
            mqttSend(TOPIC_SYNC_ACK, ['seq' => $seq]);
            $label = $res['message'] ?? ($res['type'] ?? 'OK');
            syncLog("  ✓ ACK $seq → $label", 'ok');
            isset($res['message']) ? $result['skipped']++ : $result['synced']++;
        } else {
            mqttSend(TOPIC_SYNC_NACK, ['seq' => $seq]);
            syncLog("  ✗ NACK $seq : " . $res['error'], 'error');
            $result['errors'][] = "Erreur (seq $seq) : " . $res['error'];
            $result['skipped']++;
        }
    }
}

// ── Résumé ───────────────────────────────────────────────────
$result['success']     = empty($result['errors']) || $result['synced'] > 0;
$result['finished_at'] = date('Y-m-d H:i:s');

syncLog(sprintf(
    'Résumé : %d synchronisé(s), %d ignoré(s), %d erreur(s)',
    $result['synced'], $result['skipped'], count($result['errors'])
), $result['success'] ? 'ok' : 'warn');

logActivity("Sync MQTT ESP32 : {$result['synced']} insérés, {$result['skipped']} ignorés");

// ── Sortie ───────────────────────────────────────────────────
if ($jsonOut) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    $c = $result['success'] ? '#2e7d32' : '#c62828';
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'>
          <title>Sync ESP32 MQTT</title></head><body style='font-family:monospace;padding:2rem'>";
    echo "<h2 style='color:$c'>Synchronisation ESP32 — MQTT</h2>";
    echo "<p>Broker : <strong>" . MQTT_HOST . ":" . MQTT_PORT . "</strong></p>";
    echo "<p>Démarré : {$result['started_at']} &nbsp;|&nbsp; Terminé : {$result['finished_at']}</p>";
    echo "<p>✅ Synchronisés : <strong>{$result['synced']}</strong> &nbsp; ⏭ Ignorés : {$result['skipped']}</p>";
    if (!empty($result['errors'])) {
        echo "<h3>Erreurs</h3><ul>";
        foreach ($result['errors'] as $e) echo "<li style='color:red'>" . htmlspecialchars($e) . "</li>";
        echo "</ul>";
    }
    echo "<h3>Journal</h3><pre style='background:#f5f5f5;padding:1rem;border-radius:6px'>";
    $colors = ['ok' => 'green', 'error' => 'red', 'warn' => 'darkorange', 'info' => 'gray'];
    foreach ($result['log'] as $entry) {
        $clr = $colors[$entry['l']] ?? 'black';
        echo "<span style='color:{$clr}'>[{$entry['t']}] " . htmlspecialchars($entry['m']) . "</span>\n";
    }
    echo "</pre></body></html>";
}
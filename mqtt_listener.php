<?php
/**
 * mqtt_listener.php — Daemon MQTT (pièce manquante du système)
 * =============================================================
 *
 * Ce script DOIT tourner en permanence sur le serveur.
 * Il fait le pont entre l'ESP32 (MQTT) et la base de données (SQLite).
 *
 * RÔLES :
 *   1. bioaccess/esp32/pointage      → insère le pointage en DB + publie sur bioaccess/events/pointage
 *   2. bioaccess/esp32/status        → met à jour esp32_online / esp32_last_seen en DB
 *   3. bioaccess/esp32/enroll/confirm→ confirme l'enrôlement dans enroll_queue + employees
 *
 * LANCEMENT SUR WINDOWS (dans un terminal PowerShell ou CMD) :
 *   php mqtt_listener.php
 *
 * LANCEMENT EN ARRIÈRE-PLAN (Windows, PowerShell) :
 *   Start-Process php -ArgumentList "C:\xampp\htdocs\pointage\mqtt_listener.php" -WindowStyle Hidden
 *
 * LANCEMENT AUTO AU DÉMARRAGE (Windows) :
 *   Créer une tâche dans le Planificateur de tâches Windows :
 *     - Programme : C:\xampp\php\php.exe
 *     - Arguments : C:\xampp\htdocs\pointage\mqtt_listener.php
 *     - Déclencheur : Au démarrage de l'ordinateur
 *
 * PRÉ-REQUIS :
 *   - Mosquitto installé sur Windows (https://mosquitto.org/download/)
 *   - mosquitto_sub.exe disponible dans le PATH
 *   - PHP CLI disponible (inclus avec XAMPP)
 *
 * VÉRIFICATION QUE LE LISTENER TOURNE :
 *   Aller sur la page d'accueil → le badge ESP32 doit passer "En ligne"
 *   dans les 30 secondes après le démarrage de l'ESP32.
 *
 * =============================================================
 */

declare(strict_types=1);

// ── Chemin absolu vers le répertoire de l'application ────────────────────────
// MODIFIER SI NÉCESSAIRE selon votre installation XAMPP
define('APP_ROOT', __DIR__);

// Simuler l'environnement web minimal pour config.php
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['PHP_SELF']    = $_SERVER['PHP_SELF']    ?? '/mqtt_listener.php';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once APP_ROOT . '/config.php';

// ── Topics à écouter ─────────────────────────────────────────────────────────
const TOPIC_POINTAGE       = 'bioaccess/esp32/pointage';
const TOPIC_STATUS_ESP32   = 'bioaccess/esp32/status';
const TOPIC_ENROLL_CONFIRM = 'bioaccess/esp32/enroll/confirm';

// ── Intervalle de vérification (secondes) ────────────────────────────────────
const HEARTBEAT_TIMEOUT_S = 90;   // ESP32 considéré hors-ligne après 90 s sans heartbeat
const LOG_MAX_LINES       = 1000; // rotation du fichier log

// ── Logging ──────────────────────────────────────────────────────────────────
$logFile = APP_ROOT . '/logs/mqtt_listener.log';

function listenerLog(string $level, string $message): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
    echo $line; // Affichage console
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    // Rotation : garder les 1000 dernières lignes
    static $lineCount = 0;
    $lineCount++;
    if ($lineCount > LOG_MAX_LINES) {
        $lineCount = 0;
        $lines = file($logFile);
        if ($lines && count($lines) > LOG_MAX_LINES) {
            file_put_contents($logFile, implode('', array_slice($lines, -800)));
        }
    }
}

// ── Vérification des prérequis ───────────────────────────────────────────────
function checkPrerequisites(): bool {
    // Chercher mosquitto_sub (Windows ou Linux)
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $binary    = $isWindows ? 'mosquitto_sub.exe' : 'mosquitto_sub';

    // Vérification via where (Windows) ou which (Linux)
    $checkCmd = $isWindows ? "where $binary 2>nul" : "which $binary 2>/dev/null";
    exec($checkCmd, $out, $ret);

    if ($ret !== 0) {
        // Chercher dans les emplacements courants de Mosquitto sur Windows
        $commonPaths = [
            'C:\\Program Files\\mosquitto\\mosquitto_sub.exe',
            'C:\\Program Files (x86)\\mosquitto\\mosquitto_sub.exe',
        ];
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                putenv('PATH=' . getenv('PATH') . ';' . dirname($path));
                listenerLog('INFO', "mosquitto_sub trouvé dans : " . dirname($path));
                return true;
            }
        }
        listenerLog('ERROR', "$binary introuvable. Installez Mosquitto depuis https://mosquitto.org/download/");
        listenerLog('ERROR', "Assurez-vous que le dossier Mosquitto est dans le PATH Windows.");
        return false;
    }

    listenerLog('INFO', "mosquitto_sub trouvé : " . trim(implode('', $out)));
    return true;
}

// ── Mise à jour du statut ESP32 en base ──────────────────────────────────────
function updateEsp32Status(SQLite3 $db, bool $online, array $data = []): void {
    $now = date('Y-m-d H:i:s');

    $settings = [
        'esp32_online'    => $online ? '1' : '0',
        'esp32_last_seen' => $now,
    ];

    if (!empty($data['ip']))       $settings['esp32_ip']       = $data['ip'];
    if (isset($data['rssi']))      $settings['esp32_rssi']      = (string)$data['rssi'];
    if (isset($data['uptime_s']))  $settings['esp32_uptime_s']  = (string)$data['uptime_s'];

    $stmt = $db->prepare(
        "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
    );

    foreach ($settings as $key => $value) {
        $stmt->bindValue(1, $key,   SQLITE3_TEXT);
        $stmt->bindValue(2, $value, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }
}

// ── Insertion d'un pointage ───────────────────────────────────────────────────
function processPointage(SQLite3 $db, array $data): array {
    $fpId     = isset($data['fingerprint_id']) ? (int)$data['fingerprint_id'] : 0;
    $datetime = trim($data['datetime'] ?? '');

    // Validation
    if ($fpId < 1 || $fpId > 127) {
        return ['success' => false, 'error' => "fingerprint_id invalide : $fpId"];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $datetime)) {
        return ['success' => false, 'error' => "datetime invalide : $datetime"];
    }
    $datetime = str_replace('T', ' ', $datetime); // normaliser ISO8601

    // Chercher l'employé
    $stmt = $db->prepare("SELECT id, nom, prenom FROM employees WHERE fingerprint_id = ?");
    $stmt->bindValue(1, $fpId, SQLITE3_INTEGER);
    $emp = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$emp) {
        return ['success' => false, 'error' => "Empreinte #$fpId non assignée à un employé"];
    }

    $empId = (int)$emp['id'];
    $nom   = $emp['prenom'] . ' ' . $emp['nom'];

    // Vérifier doublon
    $chk = $db->prepare("SELECT COUNT(*) FROM pointages WHERE employee_id = ? AND datetime = ?");
    $chk->bindValue(1, $empId,    SQLITE3_INTEGER);
    $chk->bindValue(2, $datetime, SQLITE3_TEXT);
    if ((int)$chk->execute()->fetchArray(SQLITE3_NUM)[0] > 0) {
        return ['success' => true, 'skipped' => true, 'message' => "Doublon ignoré pour $nom @ $datetime"];
    }

    // Déterminer ENTRÉE ou SORTIE (alternance)
    $sqlDate  = substr($datetime, 0, 10);
    $lastStmt = $db->prepare(
        "SELECT type_pointage FROM pointages WHERE employee_id = ? AND DATE(datetime) = ? ORDER BY datetime DESC LIMIT 1"
    );
    $lastStmt->bindValue(1, $empId,   SQLITE3_INTEGER);
    $lastStmt->bindValue(2, $sqlDate, SQLITE3_TEXT);
    $lastRow = $lastStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $type    = (!$lastRow || $lastRow['type_pointage'] === 'SORTIE') ? 'ENTREE' : 'SORTIE';

    // Insertion
    try {
        $db->exec('BEGIN');

        $ins = $db->prepare("INSERT INTO pointages (employee_id, type_pointage, datetime) VALUES (?, ?, ?)");
        $ins->bindValue(1, $empId,    SQLITE3_INTEGER);
        $ins->bindValue(2, $type,     SQLITE3_TEXT);
        $ins->bindValue(3, $datetime, SQLITE3_TEXT);
        $ins->execute();

        $syncLog = $db->prepare(
            "INSERT INTO sync_log (sync_date, entries_synced, status, details) VALUES (datetime('now'), 1, 'success', ?)"
        );
        $syncLog->bindValue(1, "MQTT push: fp=$fpId emp=$empId $type $datetime", SQLITE3_TEXT);
        $syncLog->execute();

        $db->exec('COMMIT');

    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'error' => "DB error: " . $e->getMessage()];
    }

    // Notifier le front-end en temps réel via MQTT
    // Le JS dans header.php écoute 'bioaccess/events/#'
    $eventPayload = json_encode([
        'employee_id' => $empId,
        'nom'         => $emp['nom'],
        'prenom'      => $emp['prenom'],
        'fp_id'       => $fpId,
        'type'        => $type,
        'datetime'    => $datetime,
    ], JSON_UNESCAPED_UNICODE);

    mqttPublish('bioaccess/events/pointage', $eventPayload, 0);

    return [
        'success' => true,
        'type'    => $type,
        'nom'     => $nom,
        'emp_id'  => $empId,
    ];
}

// ── Confirmation d'enrôlement ─────────────────────────────────────────────────
function processEnrollConfirm(SQLite3 $db, array $data): void {
    $queueId     = (int)($data['queue_id']     ?? 0);
    $success     = (bool)($data['success']     ?? false);
    $fpId        = (int)($data['fingerprint_id'] ?? 0);
    $errorMsg    = $data['error'] ?? '';

    if ($queueId <= 0) {
        listenerLog('WARN', "enroll/confirm reçu sans queue_id valide");
        return;
    }

    // Mettre à jour enroll_queue
    try {
        // Créer la table si elle n'existe pas
        $db->exec("CREATE TABLE IF NOT EXISTS enroll_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            fingerprint_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            error_msg TEXT
        )");

        if ($success && $fpId > 0) {
            // Récupérer l'employee_id depuis la queue
            $q = $db->prepare("SELECT employee_id, fingerprint_id FROM enroll_queue WHERE id = ?");
            $q->bindValue(1, $queueId, SQLITE3_INTEGER);
            $qRow = $q->execute()->fetchArray(SQLITE3_ASSOC);

            if ($qRow) {
                $empId       = (int)$qRow['employee_id'];
                $confirmedFp = $fpId > 0 ? $fpId : (int)$qRow['fingerprint_id'];

                // Mettre à jour employees.fingerprint_id
                $upd = $db->prepare(
                    "UPDATE employees SET fingerprint_id = ?, date_modification = datetime('now') WHERE id = ?"
                );
                $upd->bindValue(1, $confirmedFp, SQLITE3_INTEGER);
                $upd->bindValue(2, $empId,       SQLITE3_INTEGER);
                $upd->execute();

                // Marquer la queue comme confirmée
                $upd2 = $db->prepare(
                    "UPDATE enroll_queue SET status = 'confirmed', updated_at = datetime('now') WHERE id = ?"
                );
                $upd2->bindValue(1, $queueId, SQLITE3_INTEGER);
                $upd2->execute();

                listenerLog('OK', "Enrôlement confirmé : queue=$queueId emp=$empId fp=$confirmedFp");
            }
        } else {
            // Marquer comme échoué
            $upd = $db->prepare(
                "UPDATE enroll_queue SET status = 'failed', error_msg = ?, updated_at = datetime('now') WHERE id = ?"
            );
            $upd->bindValue(1, $errorMsg, SQLITE3_TEXT);
            $upd->bindValue(2, $queueId,  SQLITE3_INTEGER);
            $upd->execute();

            listenerLog('WARN', "Enrôlement échoué : queue=$queueId erreur=$errorMsg");
        }
    } catch (Exception $e) {
        listenerLog('ERROR', "processEnrollConfirm DB : " . $e->getMessage());
    }
}

// ── Construire la commande mosquitto_sub ──────────────────────────────────────
function buildMosquittoSubCmd(): string {
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $binary    = $isWindows ? 'mosquitto_sub' : 'mosquitto_sub';

    // Écouter les 3 topics en même temps avec le format "topic|||payload"
    $topics = [
        TOPIC_POINTAGE,
        TOPIC_STATUS_ESP32,
        TOPIC_ENROLL_CONFIRM,
    ];
    $topicArgs = implode(' ', array_map(fn($t) => '-t ' . escapeshellarg($t), $topics));

    $cmd = sprintf(
        '%s -h %s -p %d -q 1 %s -F "%%t|||%%p" -v',
        $binary,
        escapeshellarg(MQTT_HOST),
        MQTT_PORT,
        $topicArgs
    );

    if (MQTT_USER !== '') {
        $cmd .= ' -u ' . escapeshellarg(MQTT_USER) . ' -P ' . escapeshellarg(MQTT_PASS);
    }

    return $cmd;
}

// =============================================================================
// POINT D'ENTRÉE PRINCIPAL
// =============================================================================

listenerLog('INFO', '══════════════════════════════════════════');
listenerLog('INFO', '  mqtt_listener.php  —  Démarrage');
listenerLog('INFO', '══════════════════════════════════════════');
listenerLog('INFO', "Broker MQTT : " . MQTT_HOST . ":" . MQTT_PORT);
listenerLog('INFO', "Topics surveillés :");
listenerLog('INFO', "  → " . TOPIC_POINTAGE       . "  (pointages temps réel)");
listenerLog('INFO', "  → " . TOPIC_STATUS_ESP32   . "  (statut ESP32)");
listenerLog('INFO', "  → " . TOPIC_ENROLL_CONFIRM . "  (confirmation enrôlement)");

// Vérifier les prérequis
if (!checkPrerequisites()) {
    exit(1);
}

// Vérifier le broker
if (!mqttIsOnline()) {
    listenerLog('ERROR', "Broker MQTT injoignable sur " . MQTT_HOST . ":" . MQTT_PORT);
    listenerLog('ERROR', "Vérifiez que Mosquitto est démarré :");
    listenerLog('ERROR', "  Windows : net start mosquitto");
    listenerLog('ERROR', "  Linux   : sudo systemctl start mosquitto");
    exit(1);
}
listenerLog('OK', "Broker MQTT joignable ✓");

// Marquer l'ESP32 initialement comme inconnu (le listener vient de démarrer)
$db = Database::getInstance()->getConnection();
updateEsp32Status($db, false);
listenerLog('INFO', "Statut ESP32 initialisé à 'hors-ligne' — en attente heartbeat...");

// Boucle principale avec reconnexion automatique
$restartDelay = 2; // secondes avant de relancer mosquitto_sub en cas d'erreur
$cmd          = buildMosquittoSubCmd();
listenerLog('INFO', "Commande : $cmd");

while (true) {
    listenerLog('INFO', "Ouverture du pipe mosquitto_sub...");

    $process = popen($cmd . ' 2>&1', 'r');
    if (!$process) {
        listenerLog('ERROR', "Impossible de démarrer mosquitto_sub. Retry dans {$restartDelay}s...");
        sleep($restartDelay);
        continue;
    }

    listenerLog('OK', "En écoute MQTT... (Ctrl+C pour arrêter)");
    $lastHeartbeat = time();

    while (!feof($process)) {
        $line = fgets($process);
        if ($line === false || $line === '') {
            // Vérifier le timeout ESP32
            if (time() - $lastHeartbeat > HEARTBEAT_TIMEOUT_S) {
                $db = Database::getInstance()->getConnection();
                updateEsp32Status($db, false);
                $lastHeartbeat = time(); // Reset pour éviter le spam
                listenerLog('WARN', "Heartbeat ESP32 absent depuis " . HEARTBEAT_TIMEOUT_S . "s → marqué hors-ligne");
            }
            usleep(100_000); // 100 ms
            continue;
        }

        $line = trim($line);
        if ($line === '') continue;

        // Format attendu : "topic|||payload_json"
        $parts = explode('|||', $line, 2);
        if (count($parts) !== 2) {
            // Peut être un message d'erreur de mosquitto_sub
            if (str_contains($line, 'Error') || str_contains($line, 'error')) {
                listenerLog('WARN', "mosquitto_sub: $line");
            }
            continue;
        }

        $topic   = trim($parts[0]);
        $payload = trim($parts[1]);
        $data    = json_decode($payload, true);

        if ($data === null) {
            listenerLog('WARN', "Payload JSON invalide sur $topic : $payload");
            continue;
        }

        $db = Database::getInstance()->getConnection();

        // ── Traitement selon le topic ─────────────────────────────────────
        switch ($topic) {

            // ── POINTAGE (le plus important) ──────────────────────────────
            case TOPIC_POINTAGE:
                listenerLog('INFO', "← POINTAGE reçu : fp_id=" . ($data['fingerprint_id'] ?? '?') . " datetime=" . ($data['datetime'] ?? '?'));
                $res = processPointage($db, $data);
                if ($res['success'] && !($res['skipped'] ?? false)) {
                    listenerLog('OK', "  ✓ Inséré : " . ($res['nom'] ?? '?') . " → " . ($res['type'] ?? '?'));
                } elseif ($res['skipped'] ?? false) {
                    listenerLog('INFO', "  ⏭ " . ($res['message'] ?? 'Doublon ignoré'));
                } else {
                    listenerLog('ERROR', "  ✗ Erreur : " . ($res['error'] ?? 'inconnue'));
                }
                break;

            // ── STATUT / HEARTBEAT ESP32 ──────────────────────────────────
            case TOPIC_STATUS_ESP32:
                $isOnline      = (bool)($data['online'] ?? false);
                $lastHeartbeat = time();
                updateEsp32Status($db, $isOnline, $data);

                $ip    = $data['ip']   ?? 'N/A';
                $rssi  = $data['rssi'] ?? 'N/A';
                $state = $isOnline ? 'EN LIGNE' : 'HORS-LIGNE';
                listenerLog('INFO', "← STATUS ESP32 : $state | IP=$ip | RSSI=$rssi dBm");
                break;

            // ── CONFIRMATION ENRÔLEMENT ───────────────────────────────────
            case TOPIC_ENROLL_CONFIRM:
                listenerLog('INFO', "← ENROLL CONFIRM : queue_id=" . ($data['queue_id'] ?? '?') . " success=" . ($data['success'] ? 'oui' : 'non'));
                processEnrollConfirm($db, $data);
                break;

            default:
                listenerLog('WARN', "Topic non géré : $topic");
        }
    }

    pclose($process);
    listenerLog('WARN', "mosquitto_sub s'est terminé. Reconnexion dans {$restartDelay}s...");
    sleep($restartDelay);
}
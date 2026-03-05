<?php
/**
 * api_controller.php — API Web + MQTT pour communication avec ESP32
 * ==================================================================
 *
 * CHANGEMENTS MQTT vs ancienne version TCP/HTTP :
 *
 *  AVANT (polling HTTP) :
 *    - ESP32 pollingait GET enroll_command toutes les 3 s
 *    - ESP32 appelait POST enroll_confirm après enrôlement
 *    - ESP32 appelait POST pointage pour chaque pointage
 *    - Front-end pollingait GET enroll_status toutes les 2 s
 *
 *  APRÈS (MQTT push) :
 *    - handleEnrollStart() publie la commande ENROLL sur MQTT_TOPIC_ENROLL
 *      → L'ESP32 la reçoit instantanément (plus de polling)
 *    - L'ESP32 publie le résultat sur bioaccess/events/enroll
 *      → handleEnrollConfirm() est appelé par le subscriber MQTT
 *      → Le front-end reçoit le statut en push via WebSocket MQTT
 *    - Les pointages arrivent via sync_esp32.php (mode PUSH MQTT)
 *      → handlePointage() notifie le front-end via MQTT_TOPIC_EVENTS
 *
 * TABLE REQUISE (créée automatiquement) :
 *   enroll_queue (id, employee_id, fingerprint_id, status, created_at, updated_at)
 */

require_once __DIR__ . '/config.php';

if (!defined('ESP32_DEVICE_TOKEN')) {
    define('ESP32_DEVICE_TOKEN', getenv('ESP32_DEVICE_TOKEN') ?: 'device_secret_token_change_me');
}

class ApiController {

    private SQLite3 $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureEnrollQueueTable();
    }

    private function ensureEnrollQueueTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS enroll_queue (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id    INTEGER NOT NULL,
                fingerprint_id INTEGER NOT NULL,
                status         TEXT DEFAULT 'pending',
                created_at     TEXT DEFAULT (datetime('now')),
                updated_at     TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (employee_id) REFERENCES employees(id)
            )
        ");
    }

    // =========================================================================
    //  POINT D'ENTRÉE PRINCIPAL
    // =========================================================================

    public function handleRequest(): void {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200); exit();
        }

        try {
            $action = $_GET['action'] ?? '';
            $data   = json_decode(file_get_contents('php://input'), true) ?? [];

            // Endpoints device (appelés par sync_esp32.php / subscriber MQTT)
            if (in_array($action, ['pointage', 'enroll_confirm', 'status'])) {
                if (!$this->authenticateDevice()) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'Token appareil invalide']);
                    return;
                }
                $response = match($action) {
                    'pointage'       => $this->handlePointage($data),
                    'enroll_confirm' => $this->handleEnrollConfirm($data),
                    'status'         => $this->getSystemStatus(),
                };
                echo json_encode($response);
                return;
            }

            // Endpoints web (session PHP)
            if (!$this->authenticateSession()) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Non autorisé']);
                return;
            }

            $response = match($action) {
                'enroll_start'  => $this->handleEnrollStart($data),
                'enroll_status' => $this->getEnrollStatus($data),
                'employees'     => $this->getEmployees(),
                'sync'          => $this->handleSync($data),
                'backup'        => $this->handleBackup(),
                default         => (function() {
                    http_response_code(400);
                    return ['success' => false, 'message' => 'Action non supportée'];
                })(),
            };

            echo json_encode($response);

        } catch (Exception $e) {
            http_response_code(500);
            logError("API Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erreur interne du serveur']);
        }
    }

    // =========================================================================
    //  AUTHENTIFICATION
    // =========================================================================

    private function authenticateDevice(): bool {
        $token = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
        return !empty($token) && hash_equals(ESP32_DEVICE_TOKEN, $token);
    }

    private function authenticateSession(): bool {
        return isset($_SESSION['user_id']);
    }

    // =========================================================================
    //  ENRÔLEMENT — CÔTÉ WEB
    // =========================================================================

    /**
     * Crée l'employé en base et publie immédiatement la commande ENROLL sur MQTT.
     * L'ESP32 souscrit à MQTT_TOPIC_ENROLL et réagit en temps réel.
     * POST body JSON: { csrf_token, nom, prenom, poste, email, telephone }
     */
    private function handleEnrollStart(array $data): array {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['success' => false, 'message' => 'Token CSRF invalide'];
        }

        $nom       = trim($data['nom']       ?? '');
        $prenom    = trim($data['prenom']    ?? '');
        $poste     = trim($data['poste']     ?? '');
        $email     = trim($data['email']     ?? '');
        $telephone = trim($data['telephone'] ?? '');

        if (!$nom || !$prenom) {
            return ['success' => false, 'message' => 'Nom et prénom obligatoires'];
        }

        $fingerprint_id = $this->findNextFingerprintId();
        if (!$fingerprint_id) {
            return ['success' => false, 'message' => 'Aucun ID d\'empreinte disponible (1-127 saturés)'];
        }

        // Insérer l'employé
        $stmt = $this->db->prepare(
            "INSERT INTO employees (nom, prenom, poste, email, telephone, fingerprint_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bindValue(1, $nom,            SQLITE3_TEXT);
        $stmt->bindValue(2, $prenom,         SQLITE3_TEXT);
        $stmt->bindValue(3, $poste,          SQLITE3_TEXT);
        $stmt->bindValue(4, $email,          SQLITE3_TEXT);
        $stmt->bindValue(5, $telephone,      SQLITE3_TEXT);
        $stmt->bindValue(6, $fingerprint_id, SQLITE3_INTEGER);

        if (!$stmt->execute()) {
            return ['success' => false, 'message' => 'Erreur insertion employé'];
        }
        $employee_id = $this->db->lastInsertRowID();

        // Créer la file d'enrôlement
        $stmt = $this->db->prepare(
            "INSERT INTO enroll_queue (employee_id, fingerprint_id, status) VALUES (?, ?, 'pending')"
        );
        $stmt->bindValue(1, $employee_id,    SQLITE3_INTEGER);
        $stmt->bindValue(2, $fingerprint_id, SQLITE3_INTEGER);
        if (!$stmt->execute()) {
            return ['success' => false, 'message' => 'Erreur création file d\'enrôlement'];
        }
        $queue_id = $this->db->lastInsertRowID();

        // ── Publier la commande ENROLL sur MQTT (remplace le polling HTTP) ──
        $mqttRes = mqttPublish(MQTT_TOPIC_ENROLL, [
            'cmd'            => 'ENROLL',
            'queue_id'       => $queue_id,
            'fingerprint_id' => $fingerprint_id,
            'employee_id'    => $employee_id,
            'nom'            => $nom,
            'prenom'         => $prenom,
        ]);

        if (!$mqttRes['success']) {
            logError("handleEnrollStart: broker MQTT injoignable — queue #$queue_id en attente. " . $mqttRes['error']);
            return [
                'success'        => true,
                'employee_id'    => $employee_id,
                'queue_id'       => $queue_id,
                'fingerprint_id' => $fingerprint_id,
                'warning'        => 'Broker MQTT injoignable — commande en file d\'attente.',
            ];
        }

        // Passer en processing (commande transmise à l'ESP32)
        $this->db->exec(
            "UPDATE enroll_queue SET status='processing', updated_at=datetime('now') WHERE id=$queue_id"
        );

        logActivity("Enrôlement MQTT : $prenom $nom, fp=$fingerprint_id, queue=#$queue_id",
                    $_SESSION['user_id'] ?? null);

        return [
            'success'        => true,
            'employee_id'    => $employee_id,
            'queue_id'       => $queue_id,
            'fingerprint_id' => $fingerprint_id,
            'message'        => 'Commande ENROLL publiée sur MQTT — ESP32 en mode enregistrement.',
        ];
    }

    /**
     * Retourne le statut de l'enrôlement depuis la DB.
     * Le front-end peut appeler cet endpoint en fallback si MQTT WebSocket échoue.
     * GET ?action=enroll_status&queue_id=X
     */
    private function getEnrollStatus(array $data): array {
        $queue_id = (int)($data['queue_id'] ?? $_GET['queue_id'] ?? 0);
        if ($queue_id < 1) {
            return ['success' => false, 'message' => 'queue_id manquant'];
        }

        $stmt = $this->db->prepare("SELECT status, updated_at FROM enroll_queue WHERE id = ?");
        $stmt->bindValue(1, $queue_id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            return ['success' => false, 'message' => 'Commande non trouvée'];
        }

        return [
            'success'    => true,
            'status'     => $row['status'],   // pending | processing | done | failed
            'updated_at' => $row['updated_at'],
        ];
    }

    // =========================================================================
    //  ENRÔLEMENT — CONFIRMATION ESP32 (via subscriber MQTT)
    // =========================================================================

    /**
     * L'ESP32 publie le résultat sur bioaccess/events/enroll.
     * Le subscriber MQTT (cron_sync.sh ou daemon) appelle ce endpoint.
     * POST body JSON: { queue_id, success: true|false, error?: "..." }
     */
    private function handleEnrollConfirm(array $data): array {
        $queue_id = (int)($data['queue_id'] ?? 0);
        $ok       = (bool)($data['success'] ?? false);
        $error    = $data['error'] ?? '';

        if ($queue_id < 1) {
            return ['success' => false, 'message' => 'queue_id manquant'];
        }

        $new_status = $ok ? 'done' : 'failed';

        $stmt = $this->db->prepare(
            "UPDATE enroll_queue SET status = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->bindValue(1, $new_status, SQLITE3_TEXT);
        $stmt->bindValue(2, $queue_id,   SQLITE3_INTEGER);

        if (!$stmt->execute()) {
            return ['success' => false, 'message' => 'Erreur mise à jour queue'];
        }

        if (!$ok) {
            $stmt2 = $this->db->prepare(
                "UPDATE employees SET fingerprint_id = NULL
                 WHERE id = (SELECT employee_id FROM enroll_queue WHERE id = ?)"
            );
            $stmt2->bindValue(1, $queue_id, SQLITE3_INTEGER);
            $stmt2->execute();
            logError("Enrôlement échoué (queue #$queue_id) : $error");
        } else {
            logActivity("Enrôlement confirmé via MQTT (queue #$queue_id)");
        }

        // Notifier le front-end en temps réel via MQTT WebSocket
        mqttPublish(MQTT_TOPIC_EVENTS . '/enroll', [
            'queue_id' => $queue_id,
            'status'   => $new_status,
        ]);

        return ['success' => true, 'status' => $new_status];
    }

    // =========================================================================
    //  POINTAGE (appelé par sync_esp32.php / subscriber MQTT)
    // =========================================================================

    /**
     * Insère un pointage reçu via MQTT.
     * POST body JSON: { fingerprint_id, datetime }
     */
    private function handlePointage(array $data): array {
        $fingerprint_id = (int)($data['fingerprint_id'] ?? 0);
        $datetime       = trim($data['datetime'] ?? '');

        if ($fingerprint_id < 1 || $fingerprint_id > 127) {
            return ['success' => false, 'message' => 'ID d\'empreinte invalide'];
        }
        if (!$datetime) {
            return ['success' => false, 'message' => 'datetime manquant'];
        }

        $stmt = $this->db->prepare("SELECT id, nom, prenom FROM employees WHERE fingerprint_id = ?");
        $stmt->bindValue(1, $fingerprint_id, SQLITE3_INTEGER);
        $employee = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$employee) {
            return ['success' => false, 'message' => 'Aucun employé associé à cette empreinte'];
        }

        $employee_id = (int)$employee['id'];
        $type        = $this->determinePointageType($employee_id);

        $stmt = $this->db->prepare(
            "INSERT INTO pointages (employee_id, type_pointage, datetime) VALUES (?, ?, ?)"
        );
        $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $type,        SQLITE3_TEXT);
        $stmt->bindValue(3, $datetime,    SQLITE3_TEXT);

        if (!$stmt->execute()) {
            return ['success' => false, 'message' => 'Erreur lors de l\'enregistrement'];
        }

        logActivity("Pointage MQTT : $type — emp=$employee_id fp=$fingerprint_id");

        // Notifier le front-end en temps réel
        mqttPublish(MQTT_TOPIC_EVENTS . '/pointage', [
            'employee_id' => $employee_id,
            'nom'         => $employee['nom'],
            'prenom'      => $employee['prenom'],
            'type'        => $type,
            'datetime'    => $datetime,
        ]);

        return [
            'success'       => true,
            'type'          => $type,
            'employee_name' => $employee['prenom'] . ' ' . $employee['nom'],
        ];
    }

    private function determinePointageType(int $employee_id): string {
        $stmt = $this->db->prepare(
            "SELECT type_pointage FROM pointages WHERE employee_id = ? ORDER BY datetime DESC LIMIT 1"
        );
        $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return ($result && $result['type_pointage'] === 'ENTREE') ? 'SORTIE' : 'ENTREE';
    }

    // =========================================================================
    //  AUTRES ENDPOINTS
    // =========================================================================

    private function getSystemStatus(): array {
        return [
            'success' => true,
            'mqtt'    => [
                'broker' => MQTT_HOST . ':' . MQTT_PORT,
                'online' => mqttIsOnline(),
                'ws_url' => 'ws://' . MQTT_HOST . ':' . MQTT_WS_PORT,
            ],
            'stats'   => [
                'employees'       => $this->db->querySingle("SELECT COUNT(*) FROM employees"),
                'pointages'       => $this->db->querySingle("SELECT COUNT(*) FROM pointages"),
                'pointages_today' => $this->db->querySingle(
                    "SELECT COUNT(*) FROM pointages WHERE date(datetime) = date('now')"
                ),
                'pending_enrolls' => $this->db->querySingle(
                    "SELECT COUNT(*) FROM enroll_queue WHERE status IN ('pending','processing')"
                ),
            ],
        ];
    }

    private function getEmployees(): array {
        $stmt   = $this->db->prepare(
            "SELECT id, nom, prenom, poste, fingerprint_id FROM employees ORDER BY nom, prenom"
        );
        $rows   = [];
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return ['success' => true, 'employees' => $rows];
    }

    private function handleSync(array $data): array {
        if (empty($data['entries']) || !is_array($data['entries'])) {
            return ['success' => false, 'message' => 'Aucune donnée à synchroniser'];
        }
        $synced = 0; $errors = [];
        foreach ($data['entries'] as $i => $entry) {
            $res = $this->handlePointage($entry);
            $res['success'] ? $synced++ : ($errors[] = "Entry $i : " . $res['message']);
        }
        return ['success' => true, 'synced' => $synced, 'errors' => $errors, 'total' => count($data['entries'])];
    }

    private function handleBackup(): array {
        try {
            $file = Database::getInstance()->backupDatabase();
            if ($file) {
                logActivity("Sauvegarde créée via API");
                return ['success' => true, 'backup_file' => basename($file)];
            }
            return ['success' => false, 'message' => 'Erreur sauvegarde'];
        } catch (Exception $e) {
            logError("Erreur sauvegarde API : " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur sauvegarde'];
        }
    }

    // =========================================================================
    //  HELPERS
    // =========================================================================

    private function findNextFingerprintId(): int {
        $result = $this->db->query(
            "SELECT fingerprint_id FROM employees WHERE fingerprint_id IS NOT NULL"
        );
        $used = [];
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $used[] = (int)$row[0];
        }
        for ($i = 1; $i <= 127; $i++) {
            if (!in_array($i, $used, true)) return $i;
        }
        return 0;
    }
}

// Point d'entrée
(new ApiController())->handleRequest();
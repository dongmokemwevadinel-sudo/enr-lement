<?php
// config.php - Communication MQTT avec ESP32
declare(strict_types=1);

if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);

    // =========================================================================
    // Chemins
    // =========================================================================
    define('ROOT_PATH',   __DIR__);
    define('DB_PATH',     ROOT_PATH . '/pointage.db');
    define('BACKUP_PATH', ROOT_PATH . '/backups/');
    define('LOG_PATH',    ROOT_PATH . '/logs/');

    // =========================================================================
    // Application
    // =========================================================================
    define('APP_NAME',    'Système Pointage Biométrique');
    define('APP_VERSION', '2.0.0');
    define('BASE_URL',    'http://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/');

    // =========================================================================
    // ⚙️  Configuration MQTT  ← MODIFIER SELON VOTRE RÉSEAU
    // =========================================================================
    define('MQTT_HOST',    getenv('MQTT_HOST')    ?: '127.0.0.1'); // Adresse du broker Mosquitto
    define('MQTT_PORT',    (int)(getenv('MQTT_PORT')    ?: 1883)); // Port TCP MQTT standard
    define('MQTT_WS_PORT', (int)(getenv('MQTT_WS_PORT') ?: 9001)); // Port WebSocket (front JS)
    define('MQTT_USER',    getenv('MQTT_USER')    ?: '');          // Laisser vide si pas d'auth
    define('MQTT_PASS',    getenv('MQTT_PASS')    ?: '');
    define('MQTT_QOS',     1);                                      // QoS par défaut (at least once)

    // Topics MQTT utilisés par le système
    define('MQTT_TOPIC_CMD',    'bioaccess/esp32/command'); // PHP  → ESP32 : commandes générales
    define('MQTT_TOPIC_STATUS', 'bioaccess/esp32/status');  // ESP32 → PHP  : statuts / PONG
    define('MQTT_TOPIC_ENROLL', 'bioaccess/esp32/enroll'); // PHP  → ESP32 : commandes d'enrôlement
    define('MQTT_TOPIC_EVENTS', 'bioaccess/events');        // ESP32 → front : évènements temps réel
    define('MQTT_TOPIC_POINTAGE', 'bioaccess/esp32/pointage'); // PHP  → ESP32 : commandes de pointage

    // =========================================================================
    // Timeouts / sécurité session
    // =========================================================================
    define('SESSION_TIMEOUT',      1800); // 30 minutes
    define('CSRF_TOKEN_LIFETIME',  3600); // 1 heure
    define('MAX_LOGIN_ATTEMPTS',   5);
    define('LOGIN_LOCKOUT_TIME',   900);  // 15 minutes

    // =========================================================================
    // Création des répertoires
    // =========================================================================
    if (!file_exists(BACKUP_PATH)) mkdir(BACKUP_PATH, 0755, true);
    if (!file_exists(LOG_PATH))    mkdir(LOG_PATH,    0755, true);

    // =========================================================================
    // Sessions sécurisées
    // =========================================================================
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'domain'   => $_SERVER['HTTP_HOST'],
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_name('SECURE_SESSION');
        session_start();

        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } elseif (time() - $_SESSION['created'] > 300) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }

    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();

    // =========================================================================
    // Fonctions utilitaires
    // =========================================================================
    function sanitizeInput($data): string {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }
        return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
    }

    function isValidID($id): bool {
        return is_numeric($id) && $id >= 1 && $id <= 127;
    }

    function isValidDate($date): bool {
        return DateTime::createFromFormat('Y-m-d', $date) !== false;
    }

    // =========================================================================
    // CSRF
    // =========================================================================
    function generateCSRFToken(): string {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME)) {
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    function verifyCSRFToken($token): bool {
        if (!isset($_SESSION['csrf_token'], $_SESSION['csrf_token_time'])) return false;
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        if (!is_string($token)) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // =========================================================================
    // Communication ESP32 via MQTT
    // =========================================================================

    /**
     * Publie un message sur un topic MQTT via la CLI mosquitto_pub.
     *
     * $message est un STRING — texte brut ou JSON déjà encodé.
     * L'ESP32 attend du texte brut sur bioaccess/esp32/command :
     *   "PING", "DEL 5", "STATUS", "COUNT", "LIST", "CLEAR", "ENROLL 3"
     * Pour l'enrôlement avec queue_id, utiliser esp32StartEnroll().
     *
     * @param  string $topic    Topic MQTT cible
     * @param  string $message  Message à publier (texte brut ou JSON encodé)
     * @param  int    $qos      Niveau de QoS (0, 1 ou 2)
     * @return array  ['success' => bool, 'response' => string, 'error' => string]
     */
    function mqttPublish(string $topic, string $message, int $qos = MQTT_QOS): array {
        $result = ['success' => false, 'response' => '', 'error' => ''];

        $cmd = 'mosquitto_pub'
             . ' -h ' . escapeshellarg(MQTT_HOST)
             . ' -p ' . MQTT_PORT
             . ' -t ' . escapeshellarg($topic)
             . ' -m ' . escapeshellarg($message)
             . ' -q ' . $qos;

        if (MQTT_USER !== '') {
            $cmd .= ' -u ' . escapeshellarg(MQTT_USER)
                  . ' -P ' . escapeshellarg(MQTT_PASS);
        }

        $output = [];
        $rc     = 0;
        exec($cmd . ' 2>&1', $output, $rc);

        if ($rc === 0) {
            $result['success']  = true;
            $result['response'] = 'Message publié sur ' . $topic;
        } else {
            $result['error'] = 'mosquitto_pub a échoué (code ' . $rc . ') : ' . implode(' ', $output);
            logError("mqttPublish [$topic] – " . $result['error']);
        }

        return $result;
    }

    /**
     * Envoie une commande TEXTE BRUT à l'ESP32 via MQTT.
     *
     * L'ESP32 attend du texte brut sur bioaccess/esp32/command.
     * Exemples : "PING", "STATUS", "DEL 5", "COUNT", "LIST", "CLEAR", "ENROLL 3"
     *
     * @param  string $command  Commande complète (ex: "DEL 5", "PING")
     * @return array  ['success' => bool, 'response' => string, 'error' => string]
     */
    function esp32SendCommand(string $command): array {
        $command = trim($command);
        $cmd     = strtoupper(explode(' ', $command, 2)[0]);

        // Les commandes d'enrôlement avec queue_id passent par esp32StartEnroll()
        // Le cas "ENROLL N" manuel reste sur MQTT_TOPIC_CMD en texte brut
        $result = mqttPublish(MQTT_TOPIC_CMD, $command);

        if ($result['success']) {
            $result['response'] = "Commande [$cmd] publiée sur " . MQTT_TOPIC_CMD;
        }

        logActivity("esp32SendCommand via MQTT : $command");
        return $result;
    }

    /**
     * Démarre un enrôlement via le topic dédié (avec queue_id).
     * Publie {"queue_id":N,"fingerprint_id":N} sur bioaccess/esp32/enroll/command.
     *
     * @param  int $queueId       ID dans la table enroll_queue
     * @param  int $fingerprintId Slot empreinte (1–127)
     * @return array
     */
    function esp32StartEnroll(int $queueId, int $fingerprintId): array {
        if ($fingerprintId < 1 || $fingerprintId > 127) {
            return ['success' => false, 'response' => '', 'error' => 'fingerprint_id hors plage 1-127'];
        }

        $payload = json_encode([
            'queue_id'       => $queueId,
            'fingerprint_id' => $fingerprintId,
        ], JSON_UNESCAPED_UNICODE);

        $result = mqttPublish(MQTT_TOPIC_ENROLL, $payload, 1);

        if ($result['success']) {
            $result['response'] = "Enrôlement démarré : fp=$fingerprintId q=$queueId";
        }

        logActivity("esp32StartEnroll : fp=$fingerprintId queue=$queueId → " . MQTT_TOPIC_ENROLL);
        return $result;
    }

    /**
     * Vérifie si le broker MQTT est joignable (test TCP).
     */
    function mqttIsOnline(): bool {
        $sock = @fsockopen(MQTT_HOST, MQTT_PORT, $errno, $errstr, 2);
        if ($sock) { fclose($sock); return true; }
        return false;
    }

    /**
     * Vérifie si l'ESP32 est en ligne via la table settings (mise à jour par mqtt_listener.php).
     * Ne fait aucun appel MQTT bloquant.
     */
    function esp32IsOnline(): bool {
        // Priorité 1 : si mqtt_listener.php est actif → lire la DB (précis)
        try {
            $db          = Database::getInstance()->getConnection();
            $onlineVal   = $db->querySingle("SELECT value FROM settings WHERE key='esp32_online'");
            $lastSeenVal = $db->querySingle("SELECT value FROM settings WHERE key='esp32_last_seen'");
            if ($onlineVal !== null && $lastSeenVal !== null) {
                if ($onlineVal !== '1') return false;
                $elapsed = time() - (int)strtotime((string)$lastSeenVal);
                return $elapsed <= 90;
            }
        } catch (Throwable $e) { /* mqtt_listener non actif, continuer */ }

        // Priorité 2 : mqtt_listener non lancé → broker TCP joignable ?
        // L'état ESP32 réel sera mis à jour en temps réel par MQTT WebSocket (JS).
        return mqttIsOnline();
    }

    /**
     * Publie un PING sur le topic CMD (texte brut).
     * La réponse PONG arrive en push via MQTT_TOPIC_RESPONSE côté JS.
     */
    function checkESP32Connection(): bool {
        $res = mqttPublish(MQTT_TOPIC_CMD, 'PING');
        return $res['success'];
    }

    // =========================================================================
    // Pages autorisées
    // =========================================================================
    $allowed_pages = [
        'index'              => 'Accueil',
        'gestion_empreintes' => 'Gestion Empreintes',
        'presence'           => 'Liste Présence',
        'reports'            => 'Rapports',
        'login'              => 'Connexion',
        'logout'             => 'Déconnexion',
        'esp32_proxy'        => 'Proxy ESP32',
    ];

    $current_page = basename($_SERVER['PHP_SELF'], '.php');
    if (!array_key_exists($current_page, $allowed_pages)) {
        $current_page = 'index';
    }

    // =========================================================================
    // Classe Database
    // =========================================================================
    class Database {
        private static ?Database $instance = null;
        private SQLite3 $db;
        private array $migrations;

        private function __construct() {
            $this->migrations = [
                '001_initial_schema' => "
                    CREATE TABLE IF NOT EXISTS employees (
                        id INTEGER PRIMARY KEY,
                        nom TEXT NOT NULL,
                        prenom TEXT NOT NULL,
                        poste TEXT,
                        email TEXT,
                        telephone TEXT,
                        fingerprint_id INTEGER UNIQUE,
                        date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
                        date_modification DATETIME DEFAULT CURRENT_TIMESTAMP
                    );

                    CREATE TABLE IF NOT EXISTS pointages (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        employee_id INTEGER NOT NULL,
                        type_pointage TEXT NOT NULL CHECK(type_pointage IN ('ENTREE', 'SORTIE')),
                        datetime TEXT NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                    );

                    CREATE TABLE IF NOT EXISTS sync_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        sync_date TEXT NOT NULL,
                        entries_synced INTEGER DEFAULT 0,
                        status TEXT DEFAULT 'success',
                        details TEXT
                    );

                    CREATE TABLE IF NOT EXISTS users (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        username TEXT UNIQUE NOT NULL,
                        password_hash TEXT NOT NULL,
                        email TEXT,
                        role TEXT DEFAULT 'user',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        last_login DATETIME,
                        login_attempts INTEGER DEFAULT 0,
                        locked_until DATETIME
                    );

                    CREATE TABLE IF NOT EXISTS settings (
                        key TEXT PRIMARY KEY,
                        value TEXT NOT NULL,
                        description TEXT,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );

                    CREATE TABLE IF NOT EXISTS activity_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        action TEXT NOT NULL,
                        details TEXT,
                        ip_address TEXT,
                        user_agent TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );

                    CREATE INDEX IF NOT EXISTS idx_pointages_datetime   ON pointages(datetime);
                    CREATE INDEX IF NOT EXISTS idx_pointages_employee    ON pointages(employee_id);
                    CREATE INDEX IF NOT EXISTS idx_employees_fingerprint ON employees(fingerprint_id);
                    CREATE INDEX IF NOT EXISTS idx_users_username        ON users(username);
                ",

                '002_default_settings' => "
                    INSERT OR IGNORE INTO settings (key, value, description) VALUES
                    ('company_name',      'Votre Entreprise', 'Nom de l''entreprise'),
                    ('work_hours_start',  '08:00',            'Heure de début de travail'),
                    ('work_hours_end',    '17:00',            'Heure de fin de travail'),
                    ('auto_logout',       '30',               'Déconnexion automatique (minutes)'),
                    ('max_fingerprint_id','127',              'ID maximum pour les empreintes'),
                    ('mqtt_host',         '127.0.0.1',        'Adresse IP du broker MQTT'),
                    ('mqtt_port',         '1883',             'Port TCP du broker MQTT'),
                    ('mqtt_ws_port',      '9001',             'Port WebSocket MQTT (front JS)'),
                    ('mqtt_user',         '',                 'Utilisateur MQTT (optionnel)'),
                    ('mqtt_pass',         '',                 'Mot de passe MQTT (optionnel)');
                ",

                '003_default_admin' => "
                    INSERT OR IGNORE INTO users (username, password_hash, email, role)
                    VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin@example.com', 'admin');
                "
            ];

            try {
                $this->db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $this->db->enableExceptions(true);
                $this->db->busyTimeout(5000);
                $this->createTables();
                $this->runMigrations();
                $this->checkDefaultData();
            } catch (Exception $e) {
                logError("Erreur base de données: " . $e->getMessage());
                throw new Exception("Erreur d'initialisation de la base de données");
            }
        }

        public static function getInstance(): Database {
            if (self::$instance === null) {
                self::$instance = new Database();
            }
            return self::$instance;
        }

        private function createTables(): void {
            // Tables créées via les migrations
        }

        private function runMigrations(): void {
            $this->db->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name TEXT UNIQUE NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            foreach ($this->migrations as $name => $sql) {
                $stmt   = $this->db->prepare("SELECT COUNT(*) FROM migrations WHERE migration_name = ?");
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $result = $stmt->execute()->fetchArray(SQLITE3_NUM);

                if ($result[0] == 0) {
                    try {
                        $this->db->exec('BEGIN TRANSACTION');
                        $this->db->exec($sql);
                        $stmt2 = $this->db->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
                        $stmt2->bindValue(1, $name, SQLITE3_TEXT);
                        $stmt2->execute();
                        $this->db->exec('COMMIT');
                        logActivity("Migration appliquée: $name");
                    } catch (Exception $e) {
                        $this->db->exec('ROLLBACK');
                        logError("Erreur migration $name: " . $e->getMessage());
                        throw $e;
                    }
                }
            }
        }

        private function checkDefaultData(): void {
            // Vérification via migrations
        }

        public function getConnection(): SQLite3 {
            return $this->db;
        }

        public function backupDatabase(): ?string {
            try {
                $backup_file = BACKUP_PATH . 'backup_' . date('Y-m-d_H-i-s') . '.db';
                if (copy(DB_PATH, $backup_file)) {
                    logActivity("Sauvegarde créée: " . basename($backup_file));
                    $backups = glob(BACKUP_PATH . 'backup_*.db');
                    if (count($backups) > 10) {
                        usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));
                        for ($i = 10; $i < count($backups); $i++) unlink($backups[$i]);
                    }
                    return $backup_file;
                }
            } catch (Exception $e) {
                logError("Erreur sauvegarde: " . $e->getMessage());
            }
            return null;
        }

        public function getSetting(string $key, ?string $default = null): ?string {
            try {
                $stmt   = $this->db->prepare("SELECT value FROM settings WHERE key = ?");
                $stmt->bindValue(1, $key, SQLITE3_TEXT);
                $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                return $result ? $result['value'] : $default;
            } catch (Exception $e) {
                logError("Erreur getSetting: " . $e->getMessage());
                return $default;
            }
        }

        public function setSetting(string $key, string $value): bool {
            try {
                $stmt = $this->db->prepare(
                    "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
                );
                $stmt->bindValue(1, $key, SQLITE3_TEXT);
                $stmt->bindValue(2, $value, SQLITE3_TEXT);
                return $stmt->execute() !== false;
            } catch (Exception $e) {
                logError("Erreur setSetting: " . $e->getMessage());
                return false;
            }
        }
    }

    // =========================================================================
    // Logging
    // =========================================================================
    function logError(string $message): void {
        $log_file  = LOG_PATH . 'error_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        file_put_contents($log_file, "[$timestamp] [IP: $ip] $message\n", FILE_APPEND | LOCK_EX);
    }

    function logActivity(string $message, ?int $user_id = null): void {
        $log_file  = LOG_PATH . 'activity_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $user      = $user_id ?? ($_SESSION['user_id'] ?? 'system');
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        file_put_contents(
            $log_file,
            "[$timestamp] [User: $user] [IP: $ip] $message\n",
            FILE_APPEND | LOCK_EX
        );
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO activity_log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
            $stmt->bindValue(2, 'activity', SQLITE3_TEXT);
            $stmt->bindValue(3, $message,   SQLITE3_TEXT);
            $stmt->bindValue(4, $ip,        SQLITE3_TEXT);
            $stmt->bindValue(5, $_SERVER['HTTP_USER_AGENT'] ?? 'unknown', SQLITE3_TEXT);
            $stmt->execute();
        } catch (Exception $e) {
            // Ne pas bloquer si le log échoue
        }
    }

    // =========================================================================
    // Initialisation base de données
    // =========================================================================
    try {
        $database = Database::getInstance();
        $db       = $database->getConnection();
    } catch (Exception $e) {
        die("Erreur d'initialisation: " . $e->getMessage());
    }

    // =========================================================================
    // Authentification (sauf login / logout / esp32_proxy)
    // =========================================================================
    if (!in_array($current_page, ['login', 'logout', 'api', 'esp32_proxy']) && !isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // =========================================================================
    // Gestionnaire d'erreurs PHP
    // =========================================================================
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        logError("Erreur [$errno] dans $errfile ligne $errline: $errstr");
        return true;
    });

    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            logError("Erreur fatale: " . $error['message'] . " dans " . $error['file'] . " ligne " . $error['line']);
        }
    });

    // =========================================================================
    // Auto-déconnexion sur timeout
    // =========================================================================
    if (isset($_SESSION['user_id'], $_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        header('Location: login.php?timeout=1');
        exit;
    }

} // fin CONFIG_LOADED
<?php
// Database.php - Modèle de base de données (Singleton)
require_once __DIR__ . '/config.php';

class Database {
    private static ?Database $instance = null;
    private SQLite3 $db;

    private array $migrations = [
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
                locked_until DATETIME,
                remember_token TEXT,
                token_expires DATETIME
            );

            CREATE TABLE IF NOT EXISTS api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME,
                last_used DATETIME
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

            CREATE INDEX IF NOT EXISTS idx_pointages_datetime  ON pointages(datetime);
            CREATE INDEX IF NOT EXISTS idx_pointages_employee   ON pointages(employee_id);
            CREATE INDEX IF NOT EXISTS idx_employees_fingerprint ON employees(fingerprint_id);
            CREATE INDEX IF NOT EXISTS idx_users_username       ON users(username);
            CREATE INDEX IF NOT EXISTS idx_activity_log_created ON activity_log(created_at);
        ",

        '002_default_data' => "
            INSERT OR IGNORE INTO settings (key, value, description) VALUES
            ('company_name',       'Tagus Drone',    'Nom de l''entreprise'),
            ('work_hours_start',   '08:00',          'Heure de début de travail'),
            ('work_hours_end',     '17:00',          'Heure de fin de travail'),
            ('auto_logout',        '30',             'Déconnexion automatique (minutes)'),
            ('max_fingerprint_id', '127',            'ID maximum pour les empreintes'),
            ('esp32_ip',           '192.168.137.171',  'Adresse IP de l''ESP32 (WiFi)'),
            ('esp32_port',         '8080',           'Port TCP de l''ESP32'),
            ('esp32_timeout',      '5',              'Timeout connexion ESP32 (secondes)'),
            ('max_login_attempts', '5',              'Tentatives de connexion maximum'),
            ('login_lockout_time', '900',            'Temps de verrouillage (secondes)');
        ",

        '003_sample_employees' => "
            INSERT OR IGNORE INTO employees (id, nom, prenom, poste, email) VALUES
            (1, 'Dupont',  'Jean',   'Pilote Drone',  'jean.dupont@tagus-drone.com'),
            (2, 'Martin',  'Marie',  'Technicienne',  'marie.martin@tagus-drone.com'),
            (3, 'Durand',  'Pierre', 'Développeur',   'pierre.durand@tagus-drone.com'),
            (4, 'Lambert', 'Sophie', 'Commerciale',   'sophie.lambert@tagus-drone.com');
        "
    ];

    // Migration séparée pour créer l'admin par défaut
    // (utilise password_hash() au moment de l'exécution PHP, pas dans SQL statique)
    private function insertDefaultAdmin(): void {
        $exists = $this->db->querySingle("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        if ($exists == 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->db->prepare(
                "INSERT INTO users (username, password_hash, email, role) VALUES ('admin', ?, 'admin@tagus-drone.com', 'admin')"
            );
            $stmt->bindValue(1, $hash, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    private function __construct() {
        try {
            $this->db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $this->db->enableExceptions(true);
            $this->db->busyTimeout(5000);
            $this->db->exec('PRAGMA foreign_keys = ON');

            $this->runMigrations();
            $this->insertDefaultAdmin();

        } catch (Exception $e) {
            logError("Erreur initialisation DB: " . $e->getMessage());
            throw new Exception("Impossible d'initialiser la base de données");
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): SQLite3 {
        return $this->db;
    }

    private function runMigrations(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name TEXT UNIQUE NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

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

    // Hérité de l'ancienne checkDefaultData() — fusionné dans insertDefaultAdmin()
    private function checkDefaultData(): void {}

    public function backupDatabase(): ?string {
        try {
            if (!file_exists(BACKUP_PATH)) mkdir(BACKUP_PATH, 0755, true);

            $backupFile = BACKUP_PATH . 'backup_' . date('Y-m-d_His') . '.db';

            $this->db->close();

            if (copy(DB_PATH, $backupFile)) {
                $this->db = new SQLite3(DB_PATH);
                $this->compressBackup($backupFile);
                $this->cleanOldBackups();
                logActivity("Sauvegarde créée: " . basename($backupFile));
                return $backupFile;
            }

            $this->db = new SQLite3(DB_PATH);

        } catch (Exception $e) {
            logError("Erreur sauvegarde: " . $e->getMessage());
        }
        return null;
    }

    private function compressBackup(string $backupFile): void {
        if (extension_loaded('zip')) {
            $zipFile = $backupFile . '.zip';
            $zip     = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
                $zip->addFile($backupFile, 'pointage.db');
                $zip->close();
                unlink($backupFile);
            }
        }
    }

    private function cleanOldBackups(): void {
        $backups = glob(BACKUP_PATH . 'backup_*');
        if (count($backups) > 10) {
            usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
            for ($i = 0; $i < count($backups) - 10; $i++) unlink($backups[$i]);
        }
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

    public function query(string $sql, array $params = []): SQLite3Result {
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            }
            return $stmt->execute();
        } catch (Exception $e) {
            logError("Erreur query: " . $e->getMessage() . " — SQL: $sql");
            throw $e;
        }
    }

    public function execute(string $sql, array $params = []): bool {
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            }
            return $stmt->execute() !== false;
        } catch (Exception $e) {
            logError("Erreur execute: " . $e->getMessage() . " — SQL: $sql");
            return false;
        }
    }

    public function getLastInsertId(): int  { return $this->db->lastInsertRowID(); }
    public function beginTransaction(): bool { return (bool)$this->db->exec('BEGIN TRANSACTION'); }
    public function commit(): bool           { return (bool)$this->db->exec('COMMIT'); }
    public function rollback(): bool         { return (bool)$this->db->exec('ROLLBACK'); }

    public function getDatabaseSize(): string {
        clearstatcache();
        $size  = filesize(DB_PATH);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
        return round($size, 2) . ' ' . $units[$i];
    }

    public function optimize(): void {
        try {
            $this->db->exec('VACUUM');
            $this->db->exec('ANALYZE');
            logActivity("Base de données optimisée");
        } catch (Exception $e) {
            logError("Erreur optimisation DB: " . $e->getMessage());
        }
    }

    public function __destruct() {
        if (isset($this->db)) $this->db->close();
    }
}
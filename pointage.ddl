-- ============================================================
-- Schéma de base de données - Système de Pointage Biométrique
-- Communication MQTT (Mosquitto) avec ESP32
-- ============================================================

-- Table des employés
CREATE TABLE IF NOT EXISTS employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    prenom TEXT NOT NULL,
    poste TEXT,
    email TEXT,
    telephone TEXT,
    fingerprint_id INTEGER UNIQUE,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table des pointages
CREATE TABLE IF NOT EXISTS pointages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    type_pointage TEXT NOT NULL CHECK(type_pointage IN ('ENTREE','SORTIE')),
    datetime TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Table des utilisateurs
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

-- Table des tokens API
CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    last_used DATETIME
);

-- Table des logs de synchronisation MQTT ESP32
CREATE TABLE IF NOT EXISTS sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_date TEXT NOT NULL,
    entries_synced INTEGER DEFAULT 0,
    status TEXT DEFAULT 'success',
    details TEXT
);

-- Table des paramètres
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table des logs d'activité
CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table des migrations (gestion des versions du schéma)
CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration_name TEXT UNIQUE NOT NULL,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Index de performance
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_pointages_datetime   ON pointages(datetime);
CREATE INDEX IF NOT EXISTS idx_pointages_employee    ON pointages(employee_id);
CREATE INDEX IF NOT EXISTS idx_employees_fingerprint ON employees(fingerprint_id);
CREATE INDEX IF NOT EXISTS idx_users_username        ON users(username);
CREATE INDEX IF NOT EXISTS idx_activity_log_created  ON activity_log(created_at);

-- ============================================================
-- Données par défaut
-- ============================================================
INSERT OR IGNORE INTO settings (key, value, description) VALUES
    ('company_name',       'Tagus Drone',   'Nom de l''entreprise'),
    ('work_hours_start',   '08:00',         'Heure de début de travail'),
    ('work_hours_end',     '17:00',         'Heure de fin de travail'),
    ('auto_logout',        '30',            'Déconnexion automatique (minutes)'),
    ('max_fingerprint_id', '127',           'ID maximum pour les empreintes (capteur AS608)'),
    ('mqtt_host',          '127.0.0.1',     'Adresse IP du broker MQTT (Mosquitto)'),
    ('mqtt_port',          '1883',          'Port TCP du broker MQTT'),
    ('mqtt_ws_port',       '9001',          'Port WebSocket du broker MQTT (navigateur)'),
    ('mqtt_user',          '',              'Utilisateur MQTT (vide = anonyme)'),
    ('mqtt_pass',          '',              'Mot de passe MQTT (vide = anonyme)'),
    ('max_login_attempts', '5',             'Nombre maximum de tentatives de connexion'),
    ('login_lockout_time', '900',           'Durée de verrouillage après échecs (secondes)');

-- ============================================================
-- Table file d'enrôlement MQTT
-- Créée aussi automatiquement par api_controller.php
-- ============================================================
CREATE TABLE IF NOT EXISTS enroll_queue (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id    INTEGER NOT NULL,
    fingerprint_id INTEGER NOT NULL,
    status         TEXT DEFAULT 'pending',   -- pending | processing | done | failed
    created_at     TEXT DEFAULT (datetime('now')),
    updated_at     TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_enroll_queue_status ON enroll_queue(status);
CREATE INDEX IF NOT EXISTS idx_enroll_queue_emp    ON enroll_queue(employee_id);

<?php
// Employee.php - Modèle Employé
require_once __DIR__ . '/config.php';

class Employee {
    private $db;
    private $id;
    private $nom;
    private $prenom;
    private $poste;
    private $email;
    private $telephone;
    private $fingerprint_id;
    private $date_creation;
    private $date_modification;
    
    public function __construct($data = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($data) {
            $this->hydrate($data);
        }
    }
    
    private function hydrate(array $data): void {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    public static function getById(int $id): ?Employee {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            
            return $result ? new Employee($result) : null;
            
        } catch (Exception $e) {
            logError("Erreur getById: " . $e->getMessage());
            return null;
        }
    }
    
    public static function getByFingerprintId(int $fingerprint_id): ?Employee {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM employees WHERE fingerprint_id = ?");
            $stmt->bindValue(1, $fingerprint_id, SQLITE3_INTEGER);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            
            return $result ? new Employee($result) : null;
            
        } catch (Exception $e) {
            logError("Erreur getByFingerprintId: " . $e->getMessage());
            return null;
        }
    }
    
    public static function getAll(string $orderBy = 'nom, prenom'): array {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM employees ORDER BY $orderBy");
            $result = $stmt->execute();
            
            $employees = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $employees[] = new Employee($row);
            }
            
            return $employees;
            
        } catch (Exception $e) {
            logError("Erreur getAll: " . $e->getMessage());
            return [];
        }
    }
    
    public static function search(string $query): array {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM employees 
                WHERE nom LIKE ? OR prenom LIKE ? OR poste LIKE ?
                ORDER BY nom, prenom
            ");
            
            $searchTerm = '%' . $query . '%';
            $stmt->bindValue(1, $searchTerm, SQLITE3_TEXT);
            $stmt->bindValue(2, $searchTerm, SQLITE3_TEXT);
            $stmt->bindValue(3, $searchTerm, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $employees = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $employees[] = new Employee($row);
            }
            
            return $employees;
            
        } catch (Exception $e) {
            logError("Erreur search: " . $e->getMessage());
            return [];
        }
    }
    public static function getNextAvailableFingerprintId(): int {
        $db = Database::getInstance()->getConnection();
        $result = $db->query("SELECT fingerprint_id FROM employees WHERE fingerprint_id IS NOT NULL ORDER BY fingerprint_id ASC");
        $used = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $used[] = (int)$row['fingerprint_id'];
        }
        for ($id = 1; $id <= 127; $id++) {
            if (!in_array($id, $used)) return $id;
        }
        return 0; // Aucun ID dispo
    }
    
    public function save(): bool {
        try {
            if ($this->id) {
                // Mise à jour
                $stmt = $this->db->prepare("
                    UPDATE employees SET 
                    nom = ?, prenom = ?, poste = ?, email = ?, telephone = ?, 
                    fingerprint_id = ?, date_modification = datetime('now')
                    WHERE id = ?
                ");
                
                $stmt->bindValue(1, $this->nom, SQLITE3_TEXT);
                $stmt->bindValue(2, $this->prenom, SQLITE3_TEXT);
                $stmt->bindValue(3, $this->poste, SQLITE3_TEXT);
                $stmt->bindValue(4, $this->email, SQLITE3_TEXT);
                $stmt->bindValue(5, $this->telephone, SQLITE3_TEXT);
                $stmt->bindValue(6, $this->fingerprint_id, SQLITE3_INTEGER);
                $stmt->bindValue(7, $this->id, SQLITE3_INTEGER);
                
            } else {
                // Insertion
                $stmt = $this->db->prepare("
                    INSERT INTO employees (nom, prenom, poste, email, telephone, fingerprint_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->bindValue(1, $this->nom, SQLITE3_TEXT);
                $stmt->bindValue(2, $this->prenom, SQLITE3_TEXT);
                $stmt->bindValue(3, $this->poste, SQLITE3_TEXT);
                $stmt->bindValue(4, $this->email, SQLITE3_TEXT);
                $stmt->bindValue(5, $this->telephone, SQLITE3_TEXT);
                $stmt->bindValue(6, $this->fingerprint_id, SQLITE3_INTEGER);
            }
            
            $isNew   = !$this->id;  // mémoriser AVANT d'écraser $this->id
            $success = $stmt->execute() !== false;

            if ($success && $isNew) {
                $this->id = $this->db->lastInsertRowID();
            }

            if ($success) {
                logActivity("Employé " . ($isNew ? "créé" : "modifié") . ": " . $this->getFullName());
            }
            
            return $success;
            
        } catch (Exception $e) {
            logError("Erreur save employé: " . $e->getMessage());
            return false;
        }
    }
    
   /* public function delete(): bool {
        try {
            if (!$this->id) {
                return false;
            }
            
            $stmt = $this->db->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->bindValue(1, $this->id, SQLITE3_INTEGER);
            
            $success = $stmt->execute() !== false;
            
            if ($success) {
                logActivity("Employé supprimé: " . $this->getFullName());
            }
            
            return $success;
            
        } catch (Exception $e) {
            logError("Erreur delete employé: " . $e->getMessage());
            return false;
        }
    }*/
    public function delete(): bool {
        try {
            if (!$this->id) return false;
            
            $stmt = $this->db->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->bindValue(1, $this->id, SQLITE3_INTEGER);
            
            $success = $stmt->execute() !== false;
            if ($success) {
                logActivity("Employé supprimé: " . $this->getFullName());
            }
            return $success;
        } catch (Exception $e) {
            logError("Erreur suppression employé: " . $e->getMessage());
            return false;
        }
    }

    public function getPointages(array $filters = []): array {
        try {
            $whereClauses = ["employee_id = ?"];
            $params = [$this->id];
            
            if (!empty($filters['start_date'])) {
                $whereClauses[] = "datetime >= ?";
                $params[] = $filters['start_date'] . ' 00:00:00';
            }
            
            if (!empty($filters['end_date'])) {
                $whereClauses[] = "datetime <= ?";
                $params[] = $filters['end_date'] . ' 23:59:59';
            }
            
            if (!empty($filters['type'])) {
                $whereClauses[] = "type_pointage = ?";
                $params[] = $filters['type'];
            }
            
            $where = implode(' AND ', $whereClauses);
            $stmt = $this->db->prepare("
                SELECT * FROM pointages 
                WHERE $where 
                ORDER BY datetime DESC
            ");
            
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            }
            
            $result = $stmt->execute();
            $pointages = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $pointages[] = $row;
            }
            
            return $pointages;
            
        } catch (Exception $e) {
            logError("Erreur getPointages: " . $e->getMessage());
            return [];
        }
    }
    
    public function getPresenceStats(string $startDate, string $endDate): array {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(datetime) as date,
                    COUNT(*) as total_pointages,
                    SUM(CASE WHEN type_pointage = 'ENTREE' THEN 1 ELSE 0 END) as entries,
                    SUM(CASE WHEN type_pointage = 'SORTIE' THEN 1 ELSE 0 END) as exits
                FROM pointages 
                WHERE employee_id = ? AND datetime BETWEEN ? AND ?
                GROUP BY DATE(datetime)
                ORDER BY date
            ");
            
            $stmt->bindValue(1, $this->id, SQLITE3_INTEGER);
            $stmt->bindValue(2, $startDate . ' 00:00:00', SQLITE3_TEXT);
            $stmt->bindValue(3, $endDate . ' 23:59:59', SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $stats = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $stats[] = $row;
            }
            
            return $stats;
            
        } catch (Exception $e) {
            logError("Erreur getPresenceStats: " . $e->getMessage());
            return [];
        }
    }
    
    public function getMonthlySummary(int $year, int $month): array {
        $startDate = date('Y-m-01', strtotime("$year-$month-01"));
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $pointages = $this->getPointages([
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return [
            'month' => $month,
            'year' => $year,
            'total_pointages' => count($pointages),
            'entries' => count(array_filter($pointages, fn($p) => $p['type_pointage'] === 'ENTREE')),
            'exits' => count(array_filter($pointages, fn($p) => $p['type_pointage'] === 'SORTIE'))
        ];
    }
    
    public function isFingerprintAssigned(): bool {
        return !empty($this->fingerprint_id);
    }
    
    public function assignFingerprint(int $fingerprint_id): bool {
        if (!isValidID($fingerprint_id)) {
            return false;
        }
        
        // Vérifier si l'empreinte est déjà assignée
        $existing = self::getByFingerprintId($fingerprint_id);
        if ($existing && $existing->getId() !== $this->id) {
            return false;
        }
        
        $this->fingerprint_id = $fingerprint_id;
        return $this->save();
    }
    
    public function removeFingerprint(): bool {
        $this->fingerprint_id = null;
        return $this->save();
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getNom(): string { return $this->nom ?? ''; }
    public function getPrenom(): string { return $this->prenom ?? ''; }
    public function getFullName(): string { return $this->prenom . ' ' . $this->nom; }
    public function getPoste(): string { return $this->poste ?? ''; }
    public function getEmail(): string { return $this->email ?? ''; }
    public function getTelephone(): string { return $this->telephone ?? ''; }
    public function getFingerprintId(): ?int { return $this->fingerprint_id; }
    public function getDateCreation(): string { return $this->date_creation ?? ''; }
    public function getDateModification(): string { return $this->date_modification ?? ''; }
    
    // Setters
    public function setNom(string $nom): void { $this->nom = $nom; }
    public function setPrenom(string $prenom): void { $this->prenom = $prenom; }
    public function setPoste(string $poste): void { $this->poste = $poste; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function setTelephone(string $telephone): void { $this->telephone = $telephone; }
    public function setFingerprintId(?int $fingerprint_id): void { $this->fingerprint_id = $fingerprint_id; }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'poste' => $this->poste,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'fingerprint_id' => $this->fingerprint_id,
            'date_creation' => $this->date_creation,
            'date_modification' => $this->date_modification,
            'full_name' => $this->getFullName(),
            'has_fingerprint' => $this->isFingerprintAssigned()
        ];
    }
}
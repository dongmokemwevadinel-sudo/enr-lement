<?php
// Pointage.php - Modèle Pointage
require_once __DIR__ . '/config.php';

class Pointage {
    private $db;
    private $id;
    private $employee_id;
    private $type_pointage;
    private $datetime;
    private $created_at;
    private $employee; // Cache pour l'employé
    
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
    
    public static function getById(int $id): ?Pointage {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM pointages WHERE id = ?");
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            
            return $result ? new Pointage($result) : null;
            
        } catch (Exception $e) {
            logError("Erreur getById: " . $e->getMessage());
            return null;
        }
    }
    
    public static function getLastByEmployee(int $employee_id): ?Pointage {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM pointages 
                WHERE employee_id = ? 
                ORDER BY datetime DESC 
                LIMIT 1
            ");
            $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            
            return $result ? new Pointage($result) : null;
            
        } catch (Exception $e) {
            logError("Erreur getLastByEmployee: " . $e->getMessage());
            return null;
        }
    }
    
    public static function getTodayPointages(): array {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT p.*, e.nom, e.prenom 
                FROM pointages p
                LEFT JOIN employees e ON p.employee_id = e.id
                WHERE DATE(p.datetime) = DATE('now')
                ORDER BY p.datetime DESC
            ");
            
            $result = $stmt->execute();
            $pointages = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $pointages[] = new Pointage($row);
            }
            
            return $pointages;
            
        } catch (Exception $e) {
            logError("Erreur getTodayPointages: " . $e->getMessage());
            return [];
        }
    }
    
    public static function create(int $employee_id, string $type, string $datetime = null): ?Pointage {
        try {
            $pointage = new Pointage();
            $pointage->employee_id = $employee_id;
            $pointage->type_pointage = $type;
            $pointage->datetime = $datetime ?: date('Y-m-d H:i:s');
            
            if ($pointage->save()) {
                return $pointage;
            }
            
            return null;
            
        } catch (Exception $e) {
            logError("Erreur create pointage: " . $e->getMessage());
            return null;
        }
    }
    
    public function save(): bool {
        try {
            if ($this->id) {
                // Mise à jour
                $stmt = $this->db->prepare("
                    UPDATE pointages SET 
                    employee_id = ?, type_pointage = ?, datetime = ?
                    WHERE id = ?
                ");
                
                $stmt->bindValue(1, $this->employee_id, SQLITE3_INTEGER);
                $stmt->bindValue(2, $this->type_pointage, SQLITE3_TEXT);
                $stmt->bindValue(3, $this->datetime, SQLITE3_TEXT);
                $stmt->bindValue(4, $this->id, SQLITE3_INTEGER);
                
            } else {
                // Insertion
                $stmt = $this->db->prepare("
                    INSERT INTO pointages (employee_id, type_pointage, datetime)
                    VALUES (?, ?, ?)
                ");
                
                $stmt->bindValue(1, $this->employee_id, SQLITE3_INTEGER);
                $stmt->bindValue(2, $this->type_pointage, SQLITE3_TEXT);
                $stmt->bindValue(3, $this->datetime, SQLITE3_TEXT);
            }
            
            $success = $stmt->execute() !== false;
            
            if ($success && !$this->id) {
                $this->id = $this->db->lastInsertRowID();
                $this->created_at = date('Y-m-d H:i:s');
            }
            
            if ($success) {
                logActivity("Pointage " . ($this->id ? "modifié" : "créé") . ": " . $this->type_pointage);
            }
            
            return $success;
            
        } catch (Exception $e) {
            logError("Erreur save pointage: " . $e->getMessage());
            return false;
        }
    }
    
    public function delete(): bool {
        try {
            if (!$this->id) {
                return false;
            }
            
            $stmt = $this->db->prepare("DELETE FROM pointages WHERE id = ?");
            $stmt->bindValue(1, $this->id, SQLITE3_INTEGER);
            
            $success = $stmt->execute() !== false;
            
            if ($success) {
                logActivity("Pointage supprimé: " . $this->id);
            }
            
            return $success;
            
        } catch (Exception $e) {
            logError("Erreur delete pointage: " . $e->getMessage());
            return false;
        }
    }
    
    public function getEmployee(): ?Employee {
        if (!$this->employee && $this->employee_id) {
            $this->employee = Employee::getById($this->employee_id);
        }
        return $this->employee;
    }
    
    public function getFormattedDate(string $format = 'd/m/Y H:i'): string {
        return date($format, strtotime($this->datetime));
    }
    
    public function isEntry(): bool {
        return $this->type_pointage === 'ENTREE';
    }
    
    public function isExit(): bool {
        return $this->type_pointage === 'SORTIE';
    }
    
    public static function getStatsByDateRange(string $startDate, string $endDate): array {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    DATE(datetime) as date,
                    COUNT(*) as total,
                    SUM(CASE WHEN type_pointage = 'ENTREE' THEN 1 ELSE 0 END) as entries,
                    SUM(CASE WHEN type_pointage = 'SORTIE' THEN 1 ELSE 0 END) as exits,
                    COUNT(DISTINCT employee_id) as unique_employees
                FROM pointages 
                WHERE datetime BETWEEN ? AND ?
                GROUP BY DATE(datetime)
                ORDER BY date
            ");
            
            $stmt->bindValue(1, $startDate . ' 00:00:00', SQLITE3_TEXT);
            $stmt->bindValue(2, $endDate . ' 23:59:59', SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $stats = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $stats[] = $row;
            }
            
            return $stats;
            
        } catch (Exception $e) {
            logError("Erreur getStatsByDateRange: " . $e->getMessage());
            return [];
        }
    }
    
    public static function getEmployeeTimeSummary(int $employee_id, string $startDate, string $endDate): array {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    datetime,
                    type_pointage,
                    LEAD(datetime) OVER (PARTITION BY employee_id, DATE(datetime) ORDER BY datetime) as next_datetime
                FROM pointages 
                WHERE employee_id = ? AND datetime BETWEEN ? AND ?
                ORDER BY datetime
            ");
            
            $stmt->bindValue(1, $employee_id, SQLITE3_INTEGER);
            $stmt->bindValue(2, $startDate . ' 00:00:00', SQLITE3_TEXT);
            $stmt->bindValue(3, $endDate . ' 23:59:59', SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $sessions = [];
            $totalSeconds = 0;
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['type_pointage'] === 'ENTREE' && $row['next_datetime']) {
                    $entryTime = strtotime($row['datetime']);
                    $exitTime = strtotime($row['next_datetime']);
                    $duration = $exitTime - $entryTime;
                    
                    if ($duration > 0) {
                        $totalSeconds += $duration;
                        $sessions[] = [
                            'entry' => $row['datetime'],
                            'exit' => $row['next_datetime'],
                            'duration' => $duration
                        ];
                    }
                }
            }
            
            return [
                'total_seconds' => $totalSeconds,
                'total_hours' => round($totalSeconds / 3600, 2),
                'sessions' => $sessions,
                'average_per_day' => $totalSeconds / max(1, count(array_unique(array_column($sessions, 'entry'))))
            ];
            
        } catch (Exception $e) {
            logError("Erreur getEmployeeTimeSummary: " . $e->getMessage());
            return [];
        }
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getEmployeeId(): int { return $this->employee_id; }
    public function getTypePointage(): string { return $this->type_pointage; }
    public function getDatetime(): string { return $this->datetime; }
    public function getCreatedAt(): string { return $this->created_at; }
    
    // Setters
    public function setEmployeeId(int $employee_id): void { $this->employee_id = $employee_id; }
    public function setTypePointage(string $type_pointage): void { $this->type_pointage = $type_pointage; }
    public function setDatetime(string $datetime): void { $this->datetime = $datetime; }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type_pointage' => $this->type_pointage,
            'datetime' => $this->datetime,
            'created_at' => $this->created_at,
            'formatted_datetime' => $this->getFormattedDate(),
            'is_entry' => $this->isEntry(),
            'is_exit' => $this->isExit()
        ];
    }
}
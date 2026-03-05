<?php
// get_employees.php — Endpoint JSON : liste des employés
// header JSON en premier, avant tout output
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Authentification ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

// ── Paramètres optionnels ─────────────────────────────────────────────────────
$search     = sanitizeInput($_GET['search']          ?? '');
$onlyWithFP = !empty($_GET['with_fingerprint']);   // ?with_fingerprint=1

// ── Requête ───────────────────────────────────────────────────────────────────
try {
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $db->prepare("
            SELECT id, nom, prenom, poste, email, telephone, fingerprint_id, date_creation
            FROM employees
            WHERE nom LIKE ? OR prenom LIKE ? OR poste LIKE ?
            ORDER BY nom, prenom
        ");
        $stmt->bindValue(1, $like, SQLITE3_TEXT);
        $stmt->bindValue(2, $like, SQLITE3_TEXT);
        $stmt->bindValue(3, $like, SQLITE3_TEXT);
    } elseif ($onlyWithFP) {
        $stmt = $db->prepare("
            SELECT id, nom, prenom, poste, email, telephone, fingerprint_id, date_creation
            FROM employees
            WHERE fingerprint_id IS NOT NULL
            ORDER BY fingerprint_id
        ");
    } else {
        $stmt = $db->prepare("
            SELECT id, nom, prenom, poste, email, telephone, fingerprint_id, date_creation
            FROM employees
            ORDER BY nom, prenom
        ");
    }

    $result    = $stmt->execute();
    $employees = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $employees[] = [
            'id'              => (int)$row['id'],
            'nom'             => $row['nom'],
            'prenom'          => $row['prenom'],
            'full_name'       => $row['prenom'] . ' ' . $row['nom'],
            'poste'           => $row['poste']          ?? '',
            'email'           => $row['email']          ?? '',
            'telephone'       => $row['telephone']      ?? '',
            'fingerprint_id'  => $row['fingerprint_id'] !== null ? (int)$row['fingerprint_id'] : null,
            'has_fingerprint' => $row['fingerprint_id'] !== null,
            'date_creation'   => $row['date_creation']  ?? '',
        ];
    }

    echo json_encode(['success' => true, 'employees' => $employees, 'count' => count($employees)]);

} catch (Exception $e) {
    logError("get_employees.php : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}
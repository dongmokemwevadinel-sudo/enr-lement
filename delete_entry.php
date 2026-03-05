<?php
// delete_entry.php — Endpoint JSON : suppression d'un pointage
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Authentification ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

// ── Seuls les admins peuvent supprimer ────────────────────────────────────────
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit();
}

// ── Lecture du corps JSON ─────────────────────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Corps JSON invalide']);
    exit();
}

// ── Vérification CSRF ─────────────────────────────────────────────────────────
if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalide']);
    exit();
}

// ── Validation de l'ID ────────────────────────────────────────────────────────
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'ID de pointage invalide']);
    exit();
}

// ── Suppression ───────────────────────────────────────────────────────────────
try {
    // Vérifier que le pointage existe
    $chk = $db->prepare("SELECT id, employee_id, type_pointage, datetime FROM pointages WHERE id = ?");
    $chk->bindValue(1, $id, SQLITE3_INTEGER);
    $row = $chk->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pointage introuvable']);
        exit();
    }

    $stmt = $db->prepare("DELETE FROM pointages WHERE id = ?");
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);

    if ($stmt->execute()) {
        logActivity(
            "Pointage supprimé : ID $id | Employé {$row['employee_id']} | {$row['type_pointage']} le {$row['datetime']}",
            $_SESSION['user_id']
        );
        echo json_encode(['success' => true, 'message' => 'Pointage supprimé', 'id' => $id]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']);
    }

} catch (Exception $e) {
    logError("delete_entry.php : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}

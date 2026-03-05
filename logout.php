<?php
// logout.php
require_once __DIR__ . '/config.php';

$was_logged_in = isset($_SESSION['user_id']);
$username      = $_SESSION['username'] ?? 'Utilisateur inconnu';
$user_id       = $_SESSION['user_id'] ?? null;

// ── 1. Logger AVANT de toucher à la session ──────────────────────────────────
if ($was_logged_in) {
    logActivity("Déconnexion de l'utilisateur", $user_id);
}

// ── 2. Invalider le token "remember me" en base (avant session_destroy) ───────
if (isset($_COOKIE['remember_token']) && $user_id) {
    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE users SET remember_token = NULL, token_expires = NULL WHERE id = ?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->execute();
    } catch (Exception $e) {
        logError("Erreur invalidation remember_token : " . $e->getMessage());
    }
}

// ── 3. Supprimer le cookie "remember me" ──────────────────────────────────────
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
}

// ── 4. Vider et détruire la session ──────────────────────────────────────────
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// ── 5. Rediriger vers login ───────────────────────────────────────────────────
header('Location: login.php?logout=1&user=' . urlencode($username));
exit();
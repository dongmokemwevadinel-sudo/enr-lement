<?php
// login.php
require_once __DIR__ . '/config.php';

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error    = '';
$username = '';
$locked_until = null;

// Vérifier le verrouillage en session (pour les utilisateurs inconnus)
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
    $lockout_time   = $_SESSION['lockout_time'] ?? 0;
    $remaining_time = $lockout_time + LOGIN_LOCKOUT_TIME - time();

    if ($remaining_time > 0) {
        $error        = "Trop de tentatives. Réessayez dans " . ceil($remaining_time / 60) . " minutes.";
        $locked_until = date('H:i:s', $lockout_time + LOGIN_LOCKOUT_TIME);
    } else {
        unset($_SESSION['login_attempts'], $_SESSION['lockout_time']);
    }
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrf_token)) {
        $error = "Erreur de sécurité. Veuillez recharger la page.";
        logError("Tentative de connexion avec token CSRF invalide depuis " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        if (empty($username) || empty($password)) {
            $error = "Veuillez remplir tous les champs.";
        } else {
            try {
                $db = Database::getInstance()->getConnection();

                // Vérifier le verrouillage en base
                $stmt = $db->prepare("SELECT id, username, password_hash, role, login_attempts, locked_until FROM users WHERE username = ?");
                $stmt->bindValue(1, $username, SQLITE3_TEXT);
                $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if ($result && $result['locked_until']) {
                    $lock_time = strtotime($result['locked_until']);
                    if (time() < $lock_time) {
                        $error        = "Compte verrouillé jusqu'à " . date('H:i:s', $lock_time) . ".";
                        $locked_until = date('H:i:s', $lock_time);
                        logActivity("Tentative sur compte verrouillé : $username");
                    }
                }

                if (empty($error)) {
                    if ($result && password_verify($password, $result['password_hash'])) {
                        // ── Connexion réussie ──────────────────────────────
                        $stmt = $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = datetime('now') WHERE id = ?");
                        $stmt->bindValue(1, $result['id'], SQLITE3_INTEGER);
                        $stmt->execute();

                        $_SESSION['user_id']       = $result['id'];
                        $_SESSION['username']      = $result['username'];
                        $_SESSION['user_role']     = $result['role'];
                        $_SESSION['login_time']    = time();
                        $_SESSION['last_activity'] = time();
                        session_regenerate_id(true);

                        // Cookie "Se souvenir de moi"
                        if ($remember) {
                            $token      = bin2hex(random_bytes(32));
                            $expires    = time() + (30 * 24 * 3600);
                            $token_hash = password_hash($token, PASSWORD_DEFAULT);

                            $stmt = $db->prepare("UPDATE users SET remember_token = ?, token_expires = ? WHERE id = ?");
                            $stmt->bindValue(1, $token_hash, SQLITE3_TEXT);
                            $stmt->bindValue(2, date('Y-m-d H:i:s', $expires), SQLITE3_TEXT);
                            $stmt->bindValue(3, $result['id'], SQLITE3_INTEGER);
                            $stmt->execute();

                            setcookie('remember_token', $token, $expires, '/', '', isset($_SERVER['HTTPS']), true);
                        }

                        logActivity("Connexion réussie", $result['id']);

                        // Redirection sécurisée : on n'autorise que des chemins relatifs locaux
                        $raw      = $_GET['redirect'] ?? 'index.php';
                        $redirect = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $raw);
                        if (empty($redirect) || strpos($redirect, '..') !== false || strpos($redirect, '//') !== false) {
                            $redirect = 'index.php';
                        }
                        header('Location: ' . $redirect);
                        exit();

                    } else {
                        // ── Échec ─────────────────────────────────────────
                        if ($result) {
                            $attempts = $result['login_attempts'] + 1;
                            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                                $lockout_ts = time() + LOGIN_LOCKOUT_TIME;
                                $stmt = $db->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?");
                                $stmt->bindValue(1, $attempts,                           SQLITE3_INTEGER);
                                $stmt->bindValue(2, date('Y-m-d H:i:s', $lockout_ts),   SQLITE3_TEXT);
                                $stmt->bindValue(3, $result['id'],                       SQLITE3_INTEGER);
                                $error = "Trop de tentatives. Compte verrouillé pour " . round(LOGIN_LOCKOUT_TIME / 60) . " minutes.";
                                logActivity("Compte verrouillé après $attempts tentatives : $username");
                            } else {
                                $stmt = $db->prepare("UPDATE users SET login_attempts = ? WHERE id = ?");
                                $stmt->bindValue(1, $attempts,      SQLITE3_INTEGER);
                                $stmt->bindValue(2, $result['id'],  SQLITE3_INTEGER);
                                $error = "Identifiants incorrects. Tentative $attempts/" . MAX_LOGIN_ATTEMPTS . ".";
                            }
                            $stmt->execute();
                            logActivity("Tentative de connexion échouée : $username", $result['id']);
                        } else {
                            // Utilisateur inconnu — compteur en session
                            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                            if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                                $_SESSION['lockout_time'] = time();
                                $error = "Trop de tentatives. Veuillez attendre " . round(LOGIN_LOCKOUT_TIME / 60) . " minutes.";
                            } else {
                                $error = "Identifiants incorrects.";
                            }
                            logActivity("Tentative avec utilisateur inconnu : $username");
                        }
                    }
                }

            } catch (Exception $e) {
                $error = "Erreur système. Veuillez réessayer.";
                logError("Erreur connexion : " . $e->getMessage());
            }
        }
    }
}

// Nombre d'employés (pour l'affichage dans le footer de la page)
$total_employees = 0;
try {
    $total_employees = (int)Database::getInstance()->getConnection()->querySingle("SELECT COUNT(*) FROM employees");
} catch (Exception $e) { /* silencieux */ }

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root { --primary: #4361ee; --secondary: #3a0ca3; }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex; align-items: center; justify-content: center; padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-card {
            background: white; border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,.15);
            width: 100%; max-width: 450px; overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white; text-align: center; padding: 40px 20px;
        }
        .login-header i { font-size: 3rem; margin-bottom: 10px; display: block; }

        .login-body { padding: 35px 30px 25px; }

        .form-control {
            border-radius: 12px; padding: 12px 16px;
            border: 2px solid #e2e8f0; transition: all .3s; font-size: 15px;
        }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 .25rem rgba(67,97,238,.2); }

        .btn-login {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border: none; border-radius: 12px; padding: 14px; font-weight: 600;
            color: white; width: 100%; font-size: 16px; transition: all .3s;
        }
        .btn-login:hover  { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(67,97,238,.4); color: white; }
        .btn-login:disabled { background: #cbd5e0; transform: none; box-shadow: none; }

        .alert { border-radius: 12px; border: none; }

        .remember-forgot { display: flex; justify-content: space-between; align-items: center; margin: 18px 0; }

        .forgot-link { color: var(--primary); text-decoration: none; font-size: 14px; }
        .forgot-link:hover { text-decoration: underline; }

        .password-wrapper { position: relative; }
        .password-wrapper .toggle-pwd {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #64748b; background: none; border: none; padding: 0;
        }

        .login-footer { text-align: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; }

        .system-info {
            background: #f8fafc; border-radius: 10px;
            padding: 12px; margin-top: 12px; font-size: 12px; color: #64748b;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <i class="fas fa-fingerprint"></i>
        <h2 class="mb-1"><?= htmlspecialchars(APP_NAME) ?></h2>
        <p class="mb-0 opacity-90">Système de pointage biométrique</p>
    </div>

    <div class="login-body">

        <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <?php if ($locked_until): ?>
            <div class="mt-1 small">Déverrouillage à <?= htmlspecialchars($locked_until) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock me-2"></i>Session expirée. Veuillez vous reconnecter.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['logout'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            Déconnexion réussie
            <?php if (!empty($_GET['user'])): ?>
            (<?= htmlspecialchars($_GET['user']) ?>)
            <?php endif; ?>.
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="mb-3">
                <label for="username" class="form-label"><i class="fas fa-user me-1"></i> Nom d'utilisateur</label>
                <input type="text" class="form-control <?= !empty($error) && empty($locked_until) ? 'is-invalid' : '' ?>"
                       id="username" name="username"
                       value="<?= htmlspecialchars($username) ?>"
                       required autofocus autocomplete="username"
                       placeholder="Entrez votre nom d'utilisateur">
            </div>

            <div class="mb-3">
                <label for="password" class="form-label"><i class="fas fa-lock me-1"></i> Mot de passe</label>
                <div class="password-wrapper">
                    <input type="password" class="form-control <?= !empty($error) && empty($locked_until) ? 'is-invalid' : '' ?>"
                           id="password" name="password"
                           required autocomplete="current-password"
                           placeholder="Entrez votre mot de passe">
                    <button type="button" class="toggle-pwd" onclick="togglePassword()" tabindex="-1" aria-label="Afficher/Masquer">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="remember-forgot">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember" style="font-size:14px;">Se souvenir de moi</label>
                </div>
                <a href="#" class="forgot-link" onclick="showForgotPassword(); return false;">
                    Mot de passe oublié ?
                </a>
            </div>

            <button type="submit" class="btn btn-login" id="loginButton">
                <i class="fas fa-sign-in-alt me-2"></i>Se connecter
            </button>
        </form>

        <div class="login-footer">
            <small class="text-muted">
                Version <?= htmlspecialchars(APP_VERSION) ?> &bull;
                <span id="currentTime"><?= date('d/m/Y H:i:s') ?></span>
            </small>
            <div class="system-info">
                <div><i class="fas fa-shield-alt me-1"></i> Connexion sécurisée (CSRF + sessions)</div>
                <div><i class="fas fa-users me-1"></i> <?= $total_employees ?> employé<?= $total_employees > 1 ? 's' : '' ?> enregistré<?= $total_employees > 1 ? 's' : '' ?></div>
                <div><i class="fas fa-broadcast-tower me-1"></i> MQTT : <?= mqttIsOnline() ? '<span style="color:#2ecc71">Broker en ligne</span>' : '<span style="color:#e74c3c">Broker hors ligne</span>' ?></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon  = document.getElementById('toggleIcon');
        if (input.type === 'password') {
            input.type  = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type  = 'password';
            icon.className = 'fas fa-eye';
        }
    }

    function showForgotPassword() {
        alert('Veuillez contacter votre administrateur pour réinitialiser votre mot de passe.\n\nCompte par défaut : admin / admin123');
    }

    // Horloge en direct
    function updateClock() {
        const el = document.getElementById('currentTime');
        if (el) {
            el.textContent = new Date().toLocaleDateString('fr-FR') + ' ' + new Date().toLocaleTimeString('fr-FR');
        }
    }
    setInterval(updateClock, 1000);

    // Anti-double-submit
    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn = document.getElementById('loginButton');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Connexion en cours...';
    });
</script>
</body>
</html>
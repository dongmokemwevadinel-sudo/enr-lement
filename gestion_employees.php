<?php
// gestion_employes.php
// config.php appelle déjà session_start() — ne pas le rappeler ici
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$success_msg = '';
$error_msg   = '';

// ── Traitement formulaire POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Vérification CSRF pour toutes les actions POST
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_msg = "Erreur de sécurité (token CSRF invalide). Veuillez recharger la page.";
    } else {

        // ── Ajouter un employé ─────────────────────────────────────────────
        if ($action === 'add') {
            $nom            = sanitizeInput($_POST['nom']    ?? '');
            $prenom         = sanitizeInput($_POST['prenom'] ?? '');
            $poste          = sanitizeInput($_POST['poste']  ?? '');
            $fingerprint_id = !empty($_POST['fingerprint_id']) ? (int)$_POST['fingerprint_id'] : null;

            if (empty($nom) || empty($prenom)) {
                $error_msg = "Le nom et le prénom sont obligatoires.";
            } elseif ($fingerprint_id !== null && !isValidID($fingerprint_id)) {
                $error_msg = "L'ID d'empreinte doit être compris entre 1 et 127.";
            } else {
                // Vérifier unicité de l'empreinte
                if ($fingerprint_id !== null) {
                    $chk = $db->prepare("SELECT id FROM employees WHERE fingerprint_id = ?");
                    $chk->bindValue(1, $fingerprint_id, SQLITE3_INTEGER);
                    if ($chk->execute()->fetchArray()) {
                        $error_msg = "L'ID d'empreinte $fingerprint_id est déjà utilisé.";
                    }
                }

                if (empty($error_msg)) {
                    $stmt = $db->prepare("INSERT INTO employees (nom, prenom, poste, fingerprint_id) VALUES (?, ?, ?, ?)");
                    $stmt->bindValue(1, $nom,    SQLITE3_TEXT);
                    $stmt->bindValue(2, $prenom, SQLITE3_TEXT);
                    $stmt->bindValue(3, $poste,  SQLITE3_TEXT);
                    if ($fingerprint_id !== null) {
                        $stmt->bindValue(4, $fingerprint_id, SQLITE3_INTEGER);
                    } else {
                        $stmt->bindValue(4, null, SQLITE3_NULL);
                    }
                    if ($stmt->execute()) {
                        logActivity("Employé ajouté : $prenom $nom", $_SESSION['user_id']);
                        $success_msg = "Employé $prenom $nom ajouté avec succès.";
                    } else {
                        $error_msg = "Erreur lors de l'insertion.";
                    }
                }
            }
        }

        // ── Supprimer un employé ───────────────────────────────────────────
        elseif ($action === 'delete') {
            $emp_id = (int)($_POST['employee_id'] ?? 0);
            if ($emp_id < 1) {
                $error_msg = "ID employé invalide.";
            } else {
                $chk = $db->prepare("SELECT nom, prenom FROM employees WHERE id = ?");
                $chk->bindValue(1, $emp_id, SQLITE3_INTEGER);
                $emp_row = $chk->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$emp_row) {
                    $error_msg = "Employé introuvable.";
                } else {
                    $stmt = $db->prepare("DELETE FROM employees WHERE id = ?");
                    $stmt->bindValue(1, $emp_id, SQLITE3_INTEGER);
                    if ($stmt->execute()) {
                        logActivity("Employé supprimé : {$emp_row['prenom']} {$emp_row['nom']} (ID $emp_id)", $_SESSION['user_id']);
                        $success_msg = "Employé {$emp_row['prenom']} {$emp_row['nom']} supprimé.";
                    } else {
                        $error_msg = "Erreur lors de la suppression.";
                    }
                }
            }
        }
    }
}

<?php
// Récupérer la liste des employés après toute modification
$employes = [];
$res = $db->query("SELECT * FROM employees ORDER BY nom, prenom");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $employes[] = $row;
}
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Employés — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include __DIR__ . '/header.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-users me-2 text-primary"></i>Gestion des Employés</h2>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-home me-1"></i>Accueil
        </a>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Formulaire ajout -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Ajouter un employé</h5>
                </div>
                <div class="card-body">
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action"     value="add">
                        <div class="mb-3">
                            <label class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" name="nom" class="form-control" required maxlength="100" placeholder="Ex: Dupont">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prénom <span class="text-danger">*</span></label>
                            <input type="text" name="prenom" class="form-control" required maxlength="100" placeholder="Ex: Jean">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Poste</label>
                            <input type="text" name="poste" class="form-control" maxlength="100" placeholder="Ex: Technicien">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">ID Empreinte <small class="text-muted">(1–127, optionnel)</small></label>
                            <input type="number" name="fingerprint_id" class="form-control" min="1" max="127">
                            <div class="form-text">Laissez vide pour enrôler depuis la page empreintes.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-plus me-2"></i>Ajouter
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des employés -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>Liste des employés
                        <span class="badge bg-light text-dark ms-2"><?= count($employes) ?></span>
                    </h5>
                    <a href="gestion_empreinte.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-fingerprint me-1"></i>Empreintes
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($employes)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-users fa-3x mb-3 d-block"></i>
                        Aucun employé enregistré.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Poste</th>
                                    <th class="text-center">Empreinte</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employes as $emp): ?>
                                <tr id="emp-<?= $emp['id'] ?>">
                                    <td><small class="text-muted">#<?= $emp['id'] ?></small></td>
                                    <td><?= htmlspecialchars($emp['nom']) ?></td>
                                    <td><?= htmlspecialchars($emp['prenom']) ?></td>
                                    <td><?= htmlspecialchars($emp['poste'] ?? '—') ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($emp['fingerprint_id'])): ?>
                                        <span class="badge bg-primary">
                                            <i class="fas fa-fingerprint me-1"></i><?= $emp['fingerprint_id'] ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Non assignée</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="confirmDelete(<?= $emp['id'] ?>, '<?= addslashes($emp['prenom'] . ' ' . $emp['nom']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /row -->
</div><!-- /container -->

<!-- Modal suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Supprimer l'employé</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action"      value="delete">
                <input type="hidden" name="employee_id" id="deleteEmployeeId">
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer <strong id="deleteEmployeeName"></strong> ?</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Tous les pointages associés seront également supprimés.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Supprimer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(id, name) {
    document.getElementById('deleteEmployeeId').value = id;
    document.getElementById('deleteEmployeeName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>
<?php
// save_employee.php — Endpoint JSON : créer ou mettre à jour un employé
// =====================================================================
// Architecture MQTT correcte (asynchrone) :
//
//   L'enrôlement d'empreinte NE PEUT PAS être synchrone en MQTT.
//   L'ESP32 met 10-90 s pour scanner deux fois le doigt.
//
//   Flux pour une CRÉATION avec empreinte :
//     1. Insérer l'employé en DB (sans fingerprint_id encore)
//     2. Créer une ligne dans enroll_queue (status='pending')
//     3. Publier {"queue_id":N,"fingerprint_id":N} sur MQTT_TOPIC_ENROLL
//     4. L'ESP32 confirme sur bioaccess/esp32/enroll/confirm → mqtt_listener
//        (ou polling JS via api_controller.php?action=enroll_status) met à jour
//        enroll_queue + employees.fingerprint_id
//
//   Flux pour une SUPPRESSION d'empreinte :
//     - Publier DEL <id> sur MQTT_TOPIC_CMD (fire-and-forget, QoS 0)
//     - Mettre fingerprint_id à NULL en DB immédiatement
//       (l'ESP32 supprime en < 1 s, pas besoin d'attendre la réponse)
//
//   Paramètre JSON supplémentaire :
//     "enroll_mode": "queue"  (défaut) → enrôlement asynchrone via enroll_queue
//     "enroll_mode": "skip"            → sauvegarder en DB uniquement, pas d'enrôlement
// =====================================================================
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
// Pas de mqtt_config.php — tout est dans config.php (mqttPublish, esp32StartEnroll, etc.)

// ── Authentification ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

// ── Lecture du corps JSON ─────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Corps JSON invalide']);
    exit();
}

// ── Vérification CSRF ─────────────────────────────────────────────────────────
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalide']);
    exit();
}

// ── Validation des champs ─────────────────────────────────────────────────────
$nom       = sanitizeInput($input['nom']       ?? '');
$prenom    = sanitizeInput($input['prenom']    ?? '');
$poste     = sanitizeInput($input['poste']     ?? '');
$email     = trim($input['email']              ?? '');
$telephone = sanitizeInput($input['telephone'] ?? '');

// fingerprint_id : entier 1-127 ou null
$fingerprint_id = (isset($input['fingerprint_id']) && $input['fingerprint_id'] !== '' && $input['fingerprint_id'] !== null)
                  ? (int)$input['fingerprint_id'] : null;

// id : entier si mise à jour, null si création
$id = (isset($input['id']) && $input['id'] !== '') ? (int)$input['id'] : null;

// Mode d'enrôlement : "queue" (défaut, asynchrone) ou "skip" (DB uniquement)
$enrollMode = ($input['enroll_mode'] ?? 'queue') === 'skip' ? 'skip' : 'queue';

if (empty($nom) || empty($prenom)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nom et prénom sont obligatoires']);
    exit();
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Adresse e-mail invalide']);
    exit();
}
if ($fingerprint_id !== null && !isValidID($fingerprint_id)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "L'ID d'empreinte doit être compris entre 1 et 127"]);
    exit();
}

// ── Helper : créer une entrée enroll_queue et déclencher l'enrôlement MQTT ────
function startEnrollQueue(SQLite3 $db, int $employeeId, int $fingerprintId): array
{
    // Créer la table enroll_queue si elle n'existe pas encore
    $db->exec("
        CREATE TABLE IF NOT EXISTS enroll_queue (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id    INTEGER NOT NULL,
            fingerprint_id INTEGER NOT NULL,
            status         TEXT    NOT NULL DEFAULT 'pending',
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            error_msg      TEXT
        )
    ");

    // Annuler tout enrôlement pending précédent pour cet employé
    $db->exec("UPDATE enroll_queue SET status='cancelled' WHERE employee_id=$employeeId AND status='pending'");

    // Créer la nouvelle entrée
    $stmt = $db->prepare("
        INSERT INTO enroll_queue (employee_id, fingerprint_id, status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->bindValue(1, $employeeId,    SQLITE3_INTEGER);
    $stmt->bindValue(2, $fingerprintId, SQLITE3_INTEGER);
    $stmt->execute();
    $queueId = $db->lastInsertRowID();

    // Vérifier que le broker est joignable
    if (!mqttIsOnline()) {
        return [
            'success'  => false,
            'queue_id' => $queueId,
            'message'  => "Employé enregistré mais broker MQTT injoignable. "
                        . "L'enrôlement démarrera quand le broker sera disponible.",
        ];
    }

    // Publier sur bioaccess/esp32/enroll/command
    $res = esp32StartEnroll($queueId, $fingerprintId);

    if (!$res['success']) {
        return [
            'success'  => false,
            'queue_id' => $queueId,
            'message'  => "Employé enregistré mais envoi MQTT échoué : " . $res['error']
                        . " — L'enrôlement peut être relancé depuis Gestion Empreintes.",
        ];
    }

    return [
        'success'  => true,
        'queue_id' => $queueId,
        'message'  => "Enrôlement démarré — posez le doigt sur le capteur.",
    ];
}

try {
    // ── Vérifier unicité empreinte (sauf si c'est le même employé) ────────────
    if ($fingerprint_id !== null) {
        $chkSql = "SELECT id FROM employees WHERE fingerprint_id = ?" . ($id ? " AND id != ?" : "");
        $chk    = $db->prepare($chkSql);
        $chk->bindValue(1, $fingerprint_id, SQLITE3_INTEGER);
        if ($id) $chk->bindValue(2, $id, SQLITE3_INTEGER);
        if ($chk->execute()->fetchArray()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "L'ID d'empreinte $fingerprint_id est déjà utilisé"]);
            exit();
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    // CAS 1 — CRÉATION d'un nouvel employé
    // ════════════════════════════════════════════════════════════════════════════
    if (!$id) {

        // En mode "queue", l'employé est d'abord inséré sans fingerprint_id.
        // Le fingerprint_id sera mis à jour par mqtt_listener.php quand
        // l'ESP32 confirmera sur bioaccess/esp32/enroll/confirm.
        $insertFpId = ($enrollMode === 'skip') ? $fingerprint_id : null;

        $stmt = $db->prepare("
            INSERT INTO employees (nom, prenom, poste, email, telephone, fingerprint_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bindValue(1, $nom,       SQLITE3_TEXT);
        $stmt->bindValue(2, $prenom,    SQLITE3_TEXT);
        $stmt->bindValue(3, $poste,     SQLITE3_TEXT);
        $stmt->bindValue(4, $email,     SQLITE3_TEXT);
        $stmt->bindValue(5, $telephone, SQLITE3_TEXT);
        $insertFpId !== null
            ? $stmt->bindValue(6, $insertFpId, SQLITE3_INTEGER)
            : $stmt->bindValue(6, null,         SQLITE3_NULL);
        $stmt->execute();
        $newId = $db->lastInsertRowID();

        logActivity("Employé créé : $prenom $nom (ID $newId)", $_SESSION['user_id'] ?? null);

        // Déclencher l'enrôlement asynchrone si demandé
        $enrollInfo = [];
        if ($fingerprint_id !== null && $enrollMode === 'queue') {
            $enrollInfo = startEnrollQueue($db, $newId, $fingerprint_id);
        }

        echo json_encode(array_merge([
            'success' => true,
            'message' => 'Employé créé' . ($fingerprint_id !== null && $enrollMode === 'queue'
                         ? ' — ' . ($enrollInfo['message'] ?? 'Enrôlement en cours')
                         : ''),
            'id'      => $newId,
        ], $fingerprint_id !== null && $enrollMode === 'queue' ? [
            'enroll_pending' => true,
            'queue_id'       => $enrollInfo['queue_id'] ?? null,
            'enroll_ok'      => $enrollInfo['success']  ?? false,
        ] : []));

    // ════════════════════════════════════════════════════════════════════════════
    // CAS 2 — MISE À JOUR d'un employé existant
    // ════════════════════════════════════════════════════════════════════════════
    } else {

        // Récupérer l'ancien fingerprint_id
        $check = $db->prepare("SELECT id, fingerprint_id FROM employees WHERE id = ?");
        $check->bindValue(1, $id, SQLITE3_INTEGER);
        $existing = $check->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Employé introuvable']);
            exit();
        }

        $oldFpId   = $existing['fingerprint_id'] !== null ? (int)$existing['fingerprint_id'] : null;
        $fpChanged = ($fingerprint_id !== $oldFpId);

        // ── Supprimer l'ancienne empreinte de l'ESP32 (fire-and-forget) ────────
        if ($fpChanged && $oldFpId !== null && mqttIsOnline()) {
            // DEL est quasi-instantané côté ESP32 — on publie sans attendre la réponse.
            // QoS 0 suffit ici (l'empreinte sera de toute façon écrasée ou ignorée).
            esp32SendCommand("DEL $oldFpId");
            // Pas de blocage : si le DEL échoue, l'ancienne empreinte est "orpheline"
            // mais elle ne peut plus pointer vers un employé valide.
        }

        // ── Mettre à jour la DB ─────────────────────────────────────────────────
        // En mode "queue", on ne met pas encore le nouveau fingerprint_id en DB
        // (il sera mis à jour à la confirmation de l'enrôlement).
        $dbFpId = $fpChanged && $fingerprint_id !== null && $enrollMode === 'queue'
                  ? null           // sera mis à jour après confirmation ESP32
                  : $fingerprint_id;

        $stmt = $db->prepare("
            UPDATE employees
            SET nom = ?, prenom = ?, poste = ?, email = ?, telephone = ?,
                fingerprint_id = ?, date_modification = datetime('now')
            WHERE id = ?
        ");
        $stmt->bindValue(1, $nom,       SQLITE3_TEXT);
        $stmt->bindValue(2, $prenom,    SQLITE3_TEXT);
        $stmt->bindValue(3, $poste,     SQLITE3_TEXT);
        $stmt->bindValue(4, $email,     SQLITE3_TEXT);
        $stmt->bindValue(5, $telephone, SQLITE3_TEXT);
        $dbFpId !== null
            ? $stmt->bindValue(6, $dbFpId, SQLITE3_INTEGER)
            : $stmt->bindValue(6, null,     SQLITE3_NULL);
        $stmt->bindValue(7, $id, SQLITE3_INTEGER);
        $stmt->execute();

        logActivity("Employé modifié : $prenom $nom (ID $id)", $_SESSION['user_id'] ?? null);

        // ── Déclencher l'enrôlement asynchrone si l'empreinte a changé ─────────
        $enrollInfo = [];
        if ($fpChanged && $fingerprint_id !== null && $enrollMode === 'queue') {
            $enrollInfo = startEnrollQueue($db, $id, $fingerprint_id);
        }

        echo json_encode(array_merge([
            'success' => true,
            'message' => 'Employé mis à jour' . ($fpChanged && $fingerprint_id !== null && $enrollMode === 'queue'
                         ? ' — ' . ($enrollInfo['message'] ?? 'Enrôlement en cours')
                         : ''),
            'id'      => $id,
        ], $fpChanged && $fingerprint_id !== null && $enrollMode === 'queue' ? [
            'enroll_pending' => true,
            'queue_id'       => $enrollInfo['queue_id'] ?? null,
            'enroll_ok'      => $enrollInfo['success']  ?? false,
        ] : []));
    }

} catch (Exception $e) {
    logError("save_employee.php : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}
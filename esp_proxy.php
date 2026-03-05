<?php
// esp32_proxy.php
// Proxy léger : reçoit une commande depuis JavaScript (AJAX)
// et l'envoie à l'ESP32 via TCP socket, puis retourne la réponse en JSON.

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Seules ces commandes sont autorisées depuis le JS (sécurité)
const ALLOWED_COMMANDS = ['PING', 'STATUS', 'COUNT', 'SYNC', 'LIST'];

$cmd = strtoupper(trim($_GET['cmd'] ?? $_POST['cmd'] ?? ''));

if (empty($cmd)) {
    echo json_encode(['success' => false, 'error' => 'Commande manquante']);
    exit;
}

// Vérification que la commande de base est autorisée
$base_cmd = explode(' ', $cmd)[0];
if (!in_array($base_cmd, ALLOWED_COMMANDS, true)) {
    echo json_encode(['success' => false, 'error' => "Commande '$base_cmd' non autorisée via AJAX"]);
    exit;
}

// Ouverture socket TCP
$sock = @fsockopen(ESP32_IP, ESP32_PORT, $errno, $errstr, ESP32_TIMEOUT);
if (!$sock) {
    echo json_encode([
        'success'  => false,
        'error'    => "Connexion impossible (" . ESP32_IP . ":" . ESP32_PORT . ") : $errstr ($errno)"
    ]);
    exit;
}

stream_set_timeout($sock, ESP32_TIMEOUT);
fwrite($sock, $cmd . "\n");

$response = '';
$deadline = microtime(true) + ESP32_TIMEOUT;
while (!feof($sock) && microtime(true) < $deadline) {
    $line = fgets($sock, 256);
    if ($line === false) break;
    $response .= $line;
    $trimmed = trim($line);
    // Fin de réponse attendue
    if (in_array($trimmed, ['PONG', 'FP_COUNT ERR', 'END_SYNC', 'DUMP_SENT_USB']) ||
        str_starts_with($trimmed, 'FP_COUNT ') ||
        str_starts_with($trimmed, 'IP:')) {
        break;
    }
}
fclose($sock);

echo json_encode([
    'success'  => true,
    'response' => trim($response)
]);
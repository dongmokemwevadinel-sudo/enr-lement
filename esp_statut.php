<?php
// =============================================================================
// esp32_status.php — Endpoint AJAX léger pour le badge statut ESP32
// =============================================================================
// Retourne le statut de l'ESP32 en lisant la table settings (DB),
// mise à jour en temps réel par mqtt_listener.php.
//
// Contrairement à esp32_proxy.php, ce fichier ne fait AUCUN appel MQTT
// bloquant — il lit simplement la DB. Réponse en < 5 ms.
//
// Réponse JSON :
// {
//   "online":      true|false,
//   "ip":          "192.168.x.x",
//   "rssi":        -55,
//   "last_seen":   "2026-03-01 10:09:28",
//   "last_seen_s": 12,          ← secondes depuis le dernier heartbeat
//   "uptime_s":    3600,
//   "broker_ok":   true|false,  ← broker TCP joignable
//   "listener_ok": true|false,  ← mqtt_listener.php a déjà écrit en DB
//   "checked_at":  "10:09:40"
// }
// =============================================================================

declare(strict_types=1);

// Pas de session nécessaire pour ce endpoint interne
// (appelé uniquement depuis les pages déjà authentifiées)
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['PHP_SELF']    = $_SERVER['PHP_SELF']    ?? '/esp32_status.php';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

// ── Vérifier l'authentification ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// ── Lire les settings ESP32 depuis la DB ──────────────────────────────────────
$response = [
    'online'      => false,
    'ip'          => 'inconnu',
    'rssi'        => 0,
    'last_seen'   => null,
    'last_seen_s' => null,
    'uptime_s'    => 0,
    'broker_ok'   => false,
    'listener_ok' => false,
    'checked_at'  => date('H:i:s'),
];

try {
    $db = Database::getInstance()->getConnection();

    // Lire toutes les clés esp32_* en une seule requête
    $res = $db->query(
        "SELECT key, value FROM settings WHERE key LIKE 'esp32_%' OR key = 'mqtt_host' OR key = 'mqtt_port'"
    );
    $settings = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }

    $response['listener_ok'] = isset($settings['esp32_online']);

    if ($response['listener_ok']) {
        $onlineVal   = $settings['esp32_online']         ?? '0';
        $lastSeenVal = $settings['esp32_last_seen']      ?? null;
        $uptime      = (int)($settings['esp32_uptime_s'] ?? 0);
        $ip          = $settings['esp32_ip']             ?? 'inconnu';
        $rssi        = (int)($settings['esp32_rssi']     ?? 0);

        $lastSeenTs  = $lastSeenVal ? strtotime($lastSeenVal) : 0;
        $elapsedS    = $lastSeenTs > 0 ? (time() - $lastSeenTs) : 9999;

        // En ligne si : flag = 1 ET heartbeat reçu il y a moins de 90 secondes
        $response['online']      = ($onlineVal === '1') && ($elapsedS <= 90);
        $response['ip']          = $ip;
        $response['rssi']        = $rssi;
        $response['last_seen']   = $lastSeenVal;
        $response['last_seen_s'] = $elapsedS;
        $response['uptime_s']    = $uptime;
    }

} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

// ── Vérifier la connexion TCP au broker (non bloquant, timeout 1 s) ──────────
$brokerHost = defined('MQTT_HOST') ? MQTT_HOST : '127.0.0.1';
$brokerPort = defined('MQTT_PORT') ? MQTT_PORT : 1883;
$sock = @fsockopen($brokerHost, $brokerPort, $errno, $errstr, 1);
if ($sock) {
    fclose($sock);
    $response['broker_ok'] = true;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
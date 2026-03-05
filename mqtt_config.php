<?php
// =============================================================================
// mqtt_config.php — Couche de communication MQTT pour BioAccess Pro
// =============================================================================
//
// Rôle
// -----
// Ce fichier est le complément de config.php pour la couche MQTT.
// Il fournit la classe MqttClient (backends CLI et PECL) et
// la fonction mqttSubscribeOnce().
//
// Les fonctions principales (mqttPublish, esp32SendCommand, esp32StartEnroll,
// mqttIsOnline, esp32IsOnline, checkESP32Connection) sont définies dans
// config.php et ne sont PAS redéfinies ici.
//
// Architecture de communication (corrigée)
// -----------------------------------------
//  PHP                          Broker (Mosquitto)                    ESP32
//  ---                          ------------------                    -----
//
//  esp32SendCommand("PING")
//    publie texte brut  -----> bioaccess/esp32/command  -----------> reçoit
//    ESP32 répond  <--------- bioaccess/esp32/response  <----------- "PONG"
//
//  esp32StartEnroll(queueId, fpId)
//    publie JSON  ----------> bioaccess/esp32/enroll/command -------> reçoit
//    ESP32 confirme <-------- bioaccess/esp32/enroll/confirm <------- {"queue_id":N,...}
//
//  mqtt_listener.php (daemon)
//    écoute  <-------------- bioaccess/esp32/pointage  <------------ push pointage
//    écoute  <-------------- bioaccess/esp32/status    <------------ heartbeat 30s / LWT
//
// Dépendances
// -----------
// Mode CLI (défaut, sans extension PHP supplémentaire) :
//   sudo apt install mosquitto mosquitto-clients
//
// Mode PECL (optionnel, plus rapide pour le daemon mqtt_listener.php) :
//   sudo apt install libmosquitto-dev
//   sudo pecl install mosquitto
//   ; ajouter "extension=mosquitto.so" dans php.ini
//
// Important
// ---------
// Ce fichier doit être inclus APRÈS config.php (qui définit les constantes
// MQTT_HOST, MQTT_PORT, MQTT_USER, MQTT_PASS, MQTT_QOS, MQTT_TOPIC_*).
// Il ne redéfinit AUCUNE de ces constantes.
// =============================================================================

if (defined('MQTT_LOADED')) return;
define('MQTT_LOADED', true);

// Vérification : config.php doit avoir été chargé avant ce fichier.
if (!defined('MQTT_HOST')) {
    throw new RuntimeException('mqtt_config.php doit être inclus APRÈS config.php');
}

// =============================================================================
// SECTION 1 -- TIMEOUTS SPÉCIFIQUES AU CLIENT MQTT
// (Les paramètres du broker sont déjà dans config.php)
// =============================================================================

if (!defined('MQTT_CMD_TIMEOUT_SEC')) {
    // Timeout pour PING, STATUS, DEL, COUNT — commandes rapides
    define('MQTT_CMD_TIMEOUT_SEC', 8);
}
if (!defined('MQTT_ENROLL_TIMEOUT_SEC')) {
    // Timeout pour l'enrôlement (2 scans + createModel + storeModel)
    define('MQTT_ENROLL_TIMEOUT_SEC', 30);
}

// Identifiant unique du client PHP pour cette requête/process
if (!defined('MQTT_CLIENT_ID')) {
    define('MQTT_CLIENT_ID', 'bioaccess_php_' . getmypid() . '_' . substr(md5(uniqid()), 0, 6));
}

// =============================================================================
// SECTION 2 -- CLASSE MqttClient
// =============================================================================
// Deux backends au choix (détectés automatiquement) :
//
//   CLI  : sous-processus mosquitto_pub / mosquitto_sub.
//          Fonctionne sans extension PHP supplémentaire.
//          Convient pour les requêtes web normales.
//
//   PECL : extension php-mosquitto (si installée).
//          Recommandé pour mqtt_listener.php (daemon long-running).
//          Plus rapide, pas de fork, gestion des callbacks natifs.
// =============================================================================

class MqttClient
{
    /** true si l'extension PECL php-mosquitto est disponible */
    private static bool $hasPecl = false;

    // ---- Init ---------------------------------------------------------------
    public static function init(): void
    {
        self::$hasPecl = extension_loaded('mosquitto');
    }

    // =========================================================================
    // API publique
    // =========================================================================

    /**
     * Publie un message (string brut ou JSON) sur un topic MQTT.
     *
     * CORRECTION : accepte un string et non un array — l'ESP32 attend du texte
     * brut sur bioaccess/esp32/command (ex: "PING", "DEL 5").
     *
     * @param string $topic   Topic destination
     * @param string $payload Contenu : texte brut ou JSON encodé
     * @param int    $qos     0 = fire-and-forget | 1 = at-least-once (défaut)
     */
    public static function publish(string $topic, string $payload, int $qos = 1): bool
    {
        self::logMqtt($topic, $payload, 'OUT');
        return self::$hasPecl
            ? self::peclPublish($topic, $payload, $qos)
            : self::cliPublish($topic, $payload, $qos);
    }

    /**
     * S'abonne à un topic et retourne le premier message reçu.
     * Bloque jusqu'à $timeoutSec secondes, retourne null si timeout.
     */
    public static function subscribe(string $topic, int $timeoutSec = MQTT_CMD_TIMEOUT_SEC): ?string
    {
        return self::$hasPecl
            ? self::peclSubscribe($topic, $timeoutSec)
            : self::cliSubscribe($topic, $timeoutSec);
    }

    /**
     * Envoie une commande TEXTE BRUT à l'ESP32 et attend sa réponse.
     *
     * CORRECTION : l'ESP32 attend du texte brut sur bioaccess/esp32/command
     * ("PING", "DEL 5", etc.) et répond sur bioaccess/esp32/response.
     * L'ancien mécanisme publiait du JSON {"cmd":"...","correlation_id":"..."}
     * que l'ESP32 ne comprend pas.
     *
     * Mécanisme :
     *   1. S'abonne à MQTT_TOPIC_RESPONSE avant de publier.
     *   2. Publie la commande en texte brut sur MQTT_TOPIC_CMD.
     *   3. Attend la réponse pendant $timeoutSec secondes.
     *
     * @param string $cmd        Commande texte brute (ex. 'PING', 'DEL 2', 'COUNT')
     * @param int    $timeoutSec Durée d'attente maximale en secondes
     * @return array {
     *   'success'  => bool,
     *   'response' => string,
     *   'error'    => string,
     *   'raw'      => string
     * }
     */
    public static function command(string $cmd, int $timeoutSec = MQTT_CMD_TIMEOUT_SEC): array
    {
        $cmd = trim($cmd);
        self::logMqtt(MQTT_TOPIC_CMD, $cmd, 'OUT');

        $result = self::$hasPecl
            ? self::peclCommand($cmd, MQTT_TOPIC_RESPONSE, $timeoutSec)
            : self::cliCommand($cmd, MQTT_TOPIC_RESPONSE, $timeoutSec);

        if ($result['success'] && !empty($result['raw'])) {
            self::logMqtt(MQTT_TOPIC_RESPONSE, $result['raw'], 'IN');
        }

        return $result;
    }

    /**
     * Vérifie si l'ESP32 est joignable (PING → PONG, timeout 4 s).
     */
    public static function isOnline(): bool
    {
        $res = self::command('PING', 4);
        return $res['success'] && str_contains($res['response'] ?? '', 'PONG');
    }


    // =========================================================================
    // Backend CLI (mosquitto_pub / mosquitto_sub)
    // =========================================================================

    private static function buildAuthArgs(): string
    {
        if (MQTT_USER === '') return '';
        return ' -u ' . escapeshellarg(MQTT_USER)
             . ' -P ' . escapeshellarg(MQTT_PASS);
    }

    private static function cliPublish(string $topic, string $payload, int $qos): bool
    {
        $cmd = sprintf(
            'mosquitto_pub -h %s -p %d%s -q %d -t %s -m %s 2>/dev/null',
            escapeshellarg(MQTT_HOST),
            MQTT_PORT,
            self::buildAuthArgs(),
            $qos,
            escapeshellarg($topic),
            escapeshellarg($payload)
        );
        exec($cmd, $out, $ret);
        return $ret === 0;
    }

    /**
     * Lecture unique via mosquitto_sub (-C 1 = quitter après 1 message).
     * Le payload est capturé via un fichier temporaire.
     */
    private static function cliSubscribe(string $topic, int $timeoutSec): ?string
    {
        $tmpOut = tempnam(sys_get_temp_dir(), 'mqtt_sub_');
        $cmd = sprintf(
            'timeout %d mosquitto_sub -h %s -p %d%s -q 1 -C 1 -t %s > %s 2>/dev/null',
            $timeoutSec,
            escapeshellarg(MQTT_HOST),
            MQTT_PORT,
            self::buildAuthArgs(),
            escapeshellarg($topic),
            escapeshellarg($tmpOut)
        );
        exec($cmd);
        $result = is_file($tmpOut) ? trim((string)file_get_contents($tmpOut)) : null;
        @unlink($tmpOut);
        return ($result === '' || $result === null) ? null : $result;
    }

    /**
     * Pattern request/response en mode CLI.
     *
     * CORRECTION : publie $payload (texte brut) sur MQTT_TOPIC_CMD
     * et attend la réponse sur $responseTopic (bioaccess/esp32/response).
     *
     * Séquence :
     *   1. Lance mosquitto_sub en arrière-plan sur le topic de réponse.
     *   2. Attend 150 ms pour que le subscriber soit enregistré sur le broker.
     *   3. Publie la commande texte brute avec mosquitto_pub.
     *   4. Polling sur le fichier temporaire jusqu'au timeout (intervalle 100 ms).
     */
    private static function cliCommand(string $payload, string $responseTopic, int $timeoutSec): array
    {
        $auth   = self::buildAuthArgs();
        $tmpOut = tempnam(sys_get_temp_dir(), 'mqtt_resp_');

        // Étape 1 : subscriber en arrière-plan
        $subCmd = sprintf(
            'timeout %d mosquitto_sub -h %s -p %d%s -q 1 -C 1 -t %s > %s 2>/dev/null &',
            $timeoutSec + 2,
            escapeshellarg(MQTT_HOST),
            MQTT_PORT,
            $auth,
            escapeshellarg($responseTopic),
            escapeshellarg($tmpOut)
        );
        exec($subCmd);

        // Étape 2 : laisser le subscriber s'enregistrer sur le broker
        usleep(150_000); // 150 ms (augmenter à 250 ms si broker distant)

        // Étape 3 : publication de la commande (texte brut)
        $pubCmd = sprintf(
            'mosquitto_pub -h %s -p %d%s -q 1 -t %s -m %s 2>/dev/null',
            escapeshellarg(MQTT_HOST),
            MQTT_PORT,
            $auth,
            escapeshellarg(MQTT_TOPIC_CMD),  // CORRECTION : utilise la constante correcte
            escapeshellarg($payload)
        );
        exec($pubCmd, $pubOut, $pubRet);

        if ($pubRet !== 0) {
            @unlink($tmpOut);
            return [
                'success'  => false,
                'response' => '',
                'error'    => 'Impossible de publier sur le broker MQTT '
                            . '(' . MQTT_HOST . ':' . MQTT_PORT . '). '
                            . 'Vérifiez que Mosquitto est démarré '
                            . '(sudo systemctl start mosquitto).',
                'raw'      => '',
            ];
        }

        // Étape 4 : polling jusqu'au timeout
        $deadline = time() + $timeoutSec;
        $raw      = null;
        while (time() < $deadline) {
            if (is_file($tmpOut) && filesize($tmpOut) > 0) {
                clearstatcache(true, $tmpOut);
                $raw = trim((string)file_get_contents($tmpOut));
                if ($raw !== '') break;
            }
            usleep(100_000); // 100 ms entre chaque poll
        }
        @unlink($tmpOut);

        if ($raw === null || $raw === '') {
            return [
                'success'  => false,
                'response' => '',
                'error'    => "Timeout ({$timeoutSec}s) — l'ESP32 n'a pas répondu "
                            . "sur $responseTopic. Vérifiez la connexion WiFi "
                            . "de l'ESP32 et le broker.",
                'raw'      => '',
            ];
        }

        // La réponse de l'ESP32 est du texte brut (ex: "PONG", "FP_COUNT:5")
        // On essaie quand même de décoder du JSON au cas où
        $decoded = json_decode($raw, true);
        return [
            'success'  => true,
            'response' => is_array($decoded) ? ($decoded['response'] ?? $raw) : $raw,
            'error'    => '',
            'raw'      => $raw,
        ];
    }


    // =========================================================================
    // Backend PECL php-mosquitto
    // =========================================================================

    private static function peclPublish(string $topic, string $payload, int $qos): bool
    {
        try {
            $cli = new Mosquitto\Client(MQTT_CLIENT_ID . '_pub_' . mt_rand(0, 9999));
            self::peclConnect($cli);
            $cli->publish($topic, $payload, $qos, false);
            $cli->loop(1);
            $cli->disconnect();
            return true;
        } catch (Throwable $e) {
            self::logError('PECL publish : ' . $e->getMessage());
            return false;
        }
    }

    private static function peclSubscribe(string $topic, int $timeoutSec): ?string
    {
        $received = null;
        try {
            $cli = new Mosquitto\Client(MQTT_CLIENT_ID . '_sub_' . mt_rand(0, 9999));
            self::peclConnect($cli);
            $cli->subscribe($topic, 1);
            $cli->onMessage(function ($msg) use (&$received): void {
                $received = $msg->payload;
            });
            $deadline = time() + $timeoutSec;
            while ($received === null && time() < $deadline) {
                $cli->loop(200);
            }
            $cli->disconnect();
        } catch (Throwable $e) {
            self::logError('PECL subscribe : ' . $e->getMessage());
        }
        return $received;
    }

    /**
     * Request/response via PECL.
     * CORRECTION : publie du texte brut sur MQTT_TOPIC_CMD,
     * écoute la réponse sur MQTT_TOPIC_RESPONSE.
     */
    private static function peclCommand(
        string $cmd,
        string $responseTopic,
        int    $timeoutSec
    ): array {
        $received = null;

        try {
            $cli = new Mosquitto\Client(MQTT_CLIENT_ID . '_cmd_' . mt_rand(0, 9999));
            self::peclConnect($cli);

            // S'abonner AVANT de publier (évite de rater la réponse)
            $cli->subscribe($responseTopic, 1);
            $cli->onMessage(function ($msg) use (&$received): void {
                $received = $msg->payload;
            });

            // Publier la commande en texte brut
            $cli->publish(MQTT_TOPIC_CMD, $cmd, 1, false);

            // Boucler jusqu'à réception ou timeout
            $deadline = time() + $timeoutSec;
            while ($received === null && time() < $deadline) {
                $cli->loop(200);
            }
            $cli->disconnect();

        } catch (Throwable $e) {
            self::logError('PECL command : ' . $e->getMessage());
            return [
                'success'  => false,
                'response' => '',
                'error'    => 'Erreur MQTT PECL : ' . $e->getMessage(),
                'raw'      => '',
            ];
        }

        if ($received === null) {
            return [
                'success'  => false,
                'response' => '',
                'error'    => "Timeout ({$timeoutSec}s) — l'ESP32 n'a pas répondu (PECL).",
                'raw'      => '',
            ];
        }

        $decoded = json_decode($received, true);
        return [
            'success'  => true,
            'response' => is_array($decoded) ? ($decoded['response'] ?? $received) : $received,
            'error'    => '',
            'raw'      => $received,
        ];
    }

    /**
     * Connecte un client PECL au broker avec authentification si configurée.
     */
    private static function peclConnect($cli): void
    {
        if (MQTT_USER !== '') {
            $cli->setCredentials(MQTT_USER, MQTT_PASS);
        }
        $cli->connect(MQTT_HOST, MQTT_PORT, 5);
    }


    // =========================================================================
    // Journalisation
    // =========================================================================

    /**
     * Enregistre un échange dans la table mqtt_log de la DB.
     * Silencieux en cas d'erreur (ne doit pas bloquer l'application).
     *
     * @param string $direction 'IN' (reçu depuis l'ESP32) ou 'OUT' (envoyé)
     */
    private static function logMqtt(string $topic, string $payload, string $direction): void
    {
        try {
            if (!class_exists('Database')) return;
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO mqtt_log (topic, payload, direction, processed, created_at)
                 VALUES (?, ?, ?, 0, datetime('now'))"
            );
            $stmt->bindValue(1, $topic,                        SQLITE3_TEXT);
            $stmt->bindValue(2, mb_substr($payload, 0, 2000),  SQLITE3_TEXT);
            $stmt->bindValue(3, $direction,                    SQLITE3_TEXT);
            $stmt->execute();
        } catch (Throwable $e) {
            self::logError('mqtt_log INSERT : ' . $e->getMessage());
        }
    }

    /**
     * Délégation vers logError() de config.php.
     * Fallback vers error_log() si logError() n'est pas encore disponible.
     */
    private static function logError(string $msg): void
    {
        if (function_exists('logError')) {
            logError('[MqttClient] ' . $msg);
        } else {
            error_log('[BioAccess MqttClient] ' . $msg);
        }
    }
}

// Initialiser le backend (détecte PECL une seule fois au chargement)
MqttClient::init();


// =============================================================================
// SECTION 3 -- FONCTION PUBLIQUE COMPLÉMENTAIRE
// Les fonctions principales sont dans config.php :
//   mqttPublish()       — publie un message string sur un topic
//   esp32SendCommand()  — envoie une commande texte brute à l'ESP32
//   esp32StartEnroll()  — démarre un enrôlement (JSON queue_id + fingerprint_id)
//   mqttIsOnline()      — teste la connexion TCP au broker
//   checkESP32Connection() — envoie PING au broker
// =============================================================================

/**
 * Attend le premier message sur un topic (appel bloquant).
 *
 * Usage : attendre une confirmation asynchrone de l'ESP32
 * quand le pattern command/response standard ne suffit pas.
 * Ex : attendre bioaccess/esp32/enroll/confirm après esp32StartEnroll().
 *
 * @param string $topic      Topic à écouter (ex: MQTT_TOPIC_ENROLL_CONFIRM)
 * @param int    $timeoutSec Durée maximale d'attente en secondes
 * @return string|null       Payload reçu, ou null si timeout
 */
function mqttSubscribeOnce(string $topic, int $timeoutSec = MQTT_CMD_TIMEOUT_SEC): ?string
{
    return MqttClient::subscribe($topic, $timeoutSec);
}
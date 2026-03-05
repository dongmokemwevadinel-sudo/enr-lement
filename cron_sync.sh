#!/bin/bash
# =============================================================
# cron_sync.sh — Synchronisation automatique ESP32 ↔ DB (MQTT)
# =============================================================
#
# INSTALLATION (toutes les 5 minutes) :
#   chmod +x cron_sync.sh
#   crontab -e
#   */5 * * * * /var/www/html/pointage/cron_sync.sh
#
# MODE PUSH CONTINU (listener MQTT permanent) :
#   Démarre un subscriber MQTT qui insère les pointages en temps
#   réel dès que l'ESP32 publie sur bioaccess/sync/push.
#   Lance-le en arrière-plan avec :
#     nohup /var/www/html/pointage/cron_sync.sh --push &
#
# TOPICS MQTT utilisés :
#   bioaccess/esp32/command  → PHP publie SYNC
#   bioaccess/sync/begin     ← ESP32 indique N entrées
#   bioaccess/sync/entry     ← ESP32 envoie chaque pointage
#   bioaccess/sync/ack       → PHP confirme insertion
#   bioaccess/sync/nack      → PHP signale erreur
#   bioaccess/sync/end       ← ESP32 termine
#   bioaccess/sync/push      ← ESP32 push direct (mode continu)
#
# PRÉ-REQUIS :
#   - Mosquitto + mosquitto_sub + mosquitto_pub installés
#   - PHP CLI disponible
#   - Broker MQTT en écoute (voir config.php MQTT_HOST/MQTT_PORT)
# =============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="$(which php)"
SYNC_SCRIPT="$SCRIPT_DIR/sync_esp32.php"
LOG_FILE="$SCRIPT_DIR/logs/cron_sync.log"
LOCK_FILE="/tmp/sync_esp32.lock"

# ── Configuration MQTT (doit correspondre à config.php) ───────
MQTT_HOST="${MQTT_HOST:-127.0.0.1}"
MQTT_PORT="${MQTT_PORT:-1883}"
MQTT_USER="${MQTT_USER:-}"
MQTT_PASS="${MQTT_PASS:-}"

TOPIC_PUSH="bioaccess/sync/push"
TOPIC_CMD="bioaccess/esp32/command"

mkdir -p "$SCRIPT_DIR/logs"
TS=$(date '+%Y-%m-%d %H:%M:%S')

# ── Arguments mosquitto_sub (auth optionnelle) ────────────────
MQTT_AUTH_ARGS=""
if [ -n "$MQTT_USER" ]; then
    MQTT_AUTH_ARGS="-u $MQTT_USER -P $MQTT_PASS"
fi

# =============================================================
# MODE --push : subscriber MQTT permanent pour les pointages
#               en temps réel (remplace le polling HTTP)
# =============================================================
if [ "$1" = "--push" ]; then
    echo "[$TS] [PUSH] Démarrage subscriber MQTT push (topic: $TOPIC_PUSH)" >> "$LOG_FILE"
    echo "[$TS] [PUSH] Broker : $MQTT_HOST:$MQTT_PORT" >> "$LOG_FILE"

    # Boucle infinie : chaque message reçu déclenche un appel PHP
    mosquitto_sub \
        -h "$MQTT_HOST" \
        -p "$MQTT_PORT" \
        -t "$TOPIC_PUSH" \
        -q 1 \
        $MQTT_AUTH_ARGS | while IFS= read -r payload; do

        TS_NOW=$(date '+%Y-%m-%d %H:%M:%S')

        # Extraire fp_id et datetime du JSON reçu
        FP_ID=$(echo "$payload" | grep -o '"fp_id"[[:space:]]*:[[:space:]]*[0-9]*' | grep -o '[0-9]*')
        DATETIME=$(echo "$payload" | grep -oP '"datetime"\s*:\s*"\K[^"]+')

        if [ -z "$FP_ID" ] || [ -z "$DATETIME" ]; then
            echo "[$TS_NOW] [PUSH] Payload invalide ignoré : $payload" >> "$LOG_FILE"
            continue
        fi

        echo "[$TS_NOW] [PUSH] fp_id=$FP_ID datetime=$DATETIME" >> "$LOG_FILE"

        # Appel PHP pour insérer le pointage
        RESULT=$("$PHP_BIN" "$SYNC_SCRIPT" 2>&1 <<< "")
        # Utiliser l'endpoint push HTTP interne
        RESULT=$(curl -s -X POST \
            "http://localhost/$(basename "$SCRIPT_DIR")/sync_esp32.php?action=push&token=${SYNC_TOKEN:-sync_secret_token_change_me}" \
            -H "Content-Type: application/json" \
            -d "{\"fp_id\":$FP_ID,\"datetime\":\"$DATETIME\"}" 2>/dev/null)

        SUCCESS=$(echo "$RESULT" | grep -o '"success"[[:space:]]*:[[:space:]]*true')
        if [ -n "$SUCCESS" ]; then
            echo "[$TS_NOW] [PUSH]  ✓ Pointage inséré" >> "$LOG_FILE"
        else
            ERR=$(echo "$RESULT" | grep -o '"error"[[:space:]]*:[[:space:]]*"[^"]*"')
            echo "[$TS_NOW] [PUSH]  ✗ Erreur : $ERR" >> "$LOG_FILE"
        fi

        # Rotation logs
        if [ -f "$LOG_FILE" ] && [ "$(wc -l < "$LOG_FILE")" -gt 500 ]; then
            tail -300 "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
        fi
    done

    exit 0
fi

# =============================================================
# MODE NORMAL : synchronisation complète via le protocole MQTT
# =============================================================

# ── Verrou : évite les exécutions simultanées ─────────────────
if [ -f "$LOCK_FILE" ]; then
    OLD_PID=$(cat "$LOCK_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "[$TS] [SKIP] Sync déjà en cours (PID $OLD_PID)" >> "$LOG_FILE"
        exit 0
    fi
    rm -f "$LOCK_FILE"
fi
echo $$ > "$LOCK_FILE"

echo "[$TS] [START] Synchronisation MQTT lancée (broker: $MQTT_HOST:$MQTT_PORT)" >> "$LOG_FILE"

# ── Vérification broker MQTT avant de lancer PHP ──────────────
if ! mosquitto_pub \
        -h "$MQTT_HOST" \
        -p "$MQTT_PORT" \
        -t "bioaccess/cron/ping" \
        -m "{\"ts\":\"$TS\"}" \
        -q 1 \
        $MQTT_AUTH_ARGS 2>/dev/null; then
    echo "[$TS] [ERR ] Broker MQTT injoignable ($MQTT_HOST:$MQTT_PORT)" >> "$LOG_FILE"
    rm -f "$LOCK_FILE"
    exit 1
fi

# ── Exécution du script PHP de synchronisation ────────────────
OUTPUT=$("$PHP_BIN" "$SYNC_SCRIPT" 2>&1)
EXIT_CODE=$?
SYNCED=$(echo "$OUTPUT" | grep -o '"synced":[[:space:]]*[0-9]*' | grep -o '[0-9]*' | head -1)
ERRORS=$(echo "$OUTPUT" | grep -o '"errors":[[:space:]]*\[[^]]*\]' | grep -c '"')

if [ "$EXIT_CODE" -eq 0 ]; then
    echo "[$TS] [ OK ] Synchronisés : ${SYNCED:-0} | Erreurs : ${ERRORS:-0}" >> "$LOG_FILE"
else
    echo "[$TS] [ERR ] Échec PHP (code $EXIT_CODE)" >> "$LOG_FILE"
    echo "$OUTPUT" >> "$LOG_FILE"
fi

# ── Rotation : garder 500 lignes max ──────────────────────────
if [ -f "$LOG_FILE" ] && [ "$(wc -l < "$LOG_FILE")" -gt 500 ]; then
    tail -300 "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
fi

rm -f "$LOCK_FILE"
exit $EXIT_CODE
/*
 * Système de pointage de présence - ESP32 (WiFi + MQTT)
 * Compatible PlatformIO
 * VERSION SANS RTC — NTP pour l'heure
 *
 * ═══════════════════════════════════════════════════════════════════
 * ARCHITECTURE MQTT
 * ═══════════════════════════════════════════════════════════════════
 *
 *  Broker : Mosquitto sur le serveur PHP (même machine)
 *  Port   : 1883 (non-TLS) — passer à 8883 + certs pour la prod
 *
 *  Topics publiés par l'ESP32 :
 *    bioaccess/esp32/pointage       {"fingerprint_id":N,"datetime":"YYYY-MM-DD HH:MM:SS"}
 *    bioaccess/esp32/enroll/confirm {"queue_id":N,"success":true|false,"error":"..."}
 *    bioaccess/esp32/status         {"ip":"...","rssi":N,"time":"...","enroll_state":"...","eeprom_pending":N,"online":true}
 *    bioaccess/esp32/response       réponse texte aux commandes manuelles
 *
 *  Topics auxquels l'ESP32 s'abonne :
 *    bioaccess/esp32/enroll/command {"queue_id":N,"fingerprint_id":N}  ← PHP publie quand admin enrôle
 *    bioaccess/esp32/command        "PING" | "DEL <id>" | "STATUS" | "NTP_SYNC" | "COUNT" | "LIST" | "CLEAR"
 *
 *  Flux POINTAGE :
 *    Empreinte détectée → publie bioaccess/esp32/pointage (QoS 1)
 *    Si broker injoignable → stockage EEPROM (sync PULL via TCP reste dispo)
 *
 *  Flux ENRÔLEMENT :
 *    PHP publie bioaccess/esp32/enroll/command → ESP32 reçoit immédiatement
 *    ESP32 passe en machine à états non-bloquante (WAIT_SCAN1→LIFT→SCAN2→STORE→CONFIRM)
 *    ESP32 publie bioaccess/esp32/enroll/confirm → PHP met à jour enroll_queue
 *    ESP32 repasse automatiquement en mode VERIFY
 *
 * ═══════════════════════════════════════════════════════════════════
 * BROCHES ESP32 :
 *   Capteur empreinte (UART2) : RX=16, TX=17
 *   Buzzer                    : GPIO 25
 *   LED verte                 : GPIO 26
 *   LED rouge                 : GPIO 27
 * ═══════════════════════════════════════════════════════════════════
 *
 * DÉPENDANCES platformio.ini :
 *   lib_deps =
 *     adafruit/Adafruit Fingerprint Sensor Library
 *     knolleary/PubSubClient @ ^2.8
 *     bblanchon/ArduinoJson @ ^7.0.0
 *
 * CORRECTIONS APPLIQUÉES :
 *   1. Buzzer : tone()/noTone() remplacés par ledcAttach/ledcWriteTone/ledcWrite
 *      pour corriger "E (xxxx) ledc: LEDC is not initialized"
 *   2. Forward declarations ajoutées pour éviter les erreurs de compilation
 *      (mqttCallback, mqttConnect, publishStatus, handleEnrollStateMachine,
 *       verifyID, writeEntry, traiterCommandeTCP)
 *   3. publishStatus() ajoutée avec "online":true dans le payload JSON pour
 *      que le front PHP/JS détecte correctement l'ESP32 comme connecté
 *   4. Commentaire LWT corrigé : le LWT publie {"online":false} sur TOPIC_STATUS
 *      pour signaler une déconnexion propre au broker
 */

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiServer.h>   // gardé pour le mode PULL TCP (fallback EEPROM)
#include <WiFiClient.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <EEPROM.h>
#include "Adafruit_Fingerprint.h"
#include <time.h>

// =====================================================================
// CONFIGURATION — À MODIFIER SELON VOTRE RÉSEAU
// =====================================================================
#define WIFI_SSID          "DESKTOP-UT466C6 7306"
#define WIFI_PASSWORD      "5g704O6["

// Broker MQTT (même serveur que PHP)
#define MQTT_BROKER        "192.168.137.1"
#define MQTT_PORT          1883
#define MQTT_CLIENT_ID     "esp32-pointage"
#define MQTT_USER          ""          // laisser vide si pas d'auth
#define MQTT_PASSWORD_STR  ""          // laisser vide si pas d'auth
#define MQTT_KEEPALIVE_S   60
#define MQTT_RECONNECT_MS  5000

// Topics — identiques aux constantes de config.php
#define TOPIC_POINTAGE        "bioaccess/esp32/pointage"
#define TOPIC_ENROLL_CMD      "bioaccess/esp32/enroll/command"
#define TOPIC_ENROLL_CONFIRM  "bioaccess/esp32/enroll/confirm"
#define TOPIC_STATUS          "bioaccess/esp32/status"
#define TOPIC_COMMAND         "bioaccess/esp32/command"
#define TOPIC_RESPONSE        "bioaccess/esp32/response"

// TCP fallback EEPROM (mode PULL)
#define TCP_PORT           8080
#define EEPROM_SIZE        512

// NTP
#define NTP_SERVER1        "pool.ntp.org"
#define NTP_SERVER2        "time.google.com"
#define NTP_SERVER3        "fr.pool.ntp.org"
#define GMT_OFFSET         3600   // UTC+1 (Europe/Paris heure normale)
#define DST_OFFSET         0      // mettre 3600 en été si besoin
#define NTP_SYNC_INTERVAL  3600000UL

// Status publish interval
#define STATUS_INTERVAL_MS 30000UL

// ── Buzzer : canal LEDC dédié ──────────────────────────────────────
// FIX : tone()/noTone() déclenchent "LEDC is not initialized" sur ESP32.
// Solution : utiliser directement l'API LEDC bas niveau.
//
// Arduino Core 2.x (framework-arduinoespressif32 < 3.x) :
//   ledcSetup(channel, freq, resolution) + ledcAttachPin(pin, channel)
//   + ledcWriteTone(channel, freq) / ledcWrite(channel, duty)
//
// Arduino Core 3.x (framework >= 3.x) :
//   ledcAttach(pin, freq, resolution) + ledcWriteTone(pin, freq) / ledcWrite(pin, duty)
//
// Ce code cible Core 2.x (PlatformIO framework-arduinoespressif32 @ 3.20017.x).
#define BUZZER_LEDC_CHANNEL  0    // canal LEDC 0 (libre par défaut)
#define BUZZER_LEDC_RES      10   // résolution 10 bits
// =====================================================================

#define USB   Serial
HardwareSerial FPSER(2);
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&FPSER);

const int PIN_BUZZER = 25;
const int PIN_GREEN  = 26;
const int PIN_RED    = 27;

// MQTT
WiFiClient   wifiClient;
PubSubClient mqtt(wifiClient);
unsigned long lastMqttReconnect = 0;
unsigned long lastStatusPublish = 0;

// TCP serveur (mode PULL fallback)
WiFiServer tcpServer(TCP_PORT);
WiFiClient tcpClient;
bool          hostPresent  = false;
unsigned long lastHostSeen = 0;
const unsigned long HOST_TIMEOUT_MS = 30000UL;

// Sync PULL (EEPROM -> PHP via TCP)
bool     syncInProgress = false, waitingAck = false;
uint16_t currentSeq     = 0;
String   lastSentLine   = "";
int      ackRetriesLeft = 0;
const int ACK_RETRIES   = 3;
const unsigned long ACK_TIMEOUT_MS = 2000UL;
unsigned long ackDeadline = 0;
String   ackInbox        = "";

// NTP
bool          timeSynced  = false;
unsigned long lastNTPsync = 0;

// EEPROM circulaire
const int ADDR_HEAD  = 0, ADDR_TAIL = 2, ADDR_SEQ = 4, START_ADDR = 6;
const int ENTRY_SIZE = 10;
int      maxEntries  = 0;
uint16_t headIndex   = 0, tailIndex = 0, seqCounter = 0;

// DateTime
struct DateTime {
  int year, month, day, hour, minute, second;
  DateTime() : year(1970), month(1), day(1), hour(0), minute(0), second(0) {}
  DateTime(int y,int m,int d,int h,int mi,int s)
    : year(y), month(m), day(d), hour(h), minute(mi), second(s) {}
};

// Machine a etats ENROLEMENT (non-bloquant)
enum EnrollState {
  ENROLL_IDLE,
  ENROLL_WAIT_SCAN1,
  ENROLL_WAIT_LIFT,
  ENROLL_WAIT_SCAN2,
  ENROLL_STORING,
  ENROLL_CONFIRM
};
EnrollState   enrollState    = ENROLL_IDLE;
int           enrollFpId     = -1;
int           enrollQueueId  = -1;
unsigned long enrollDeadline = 0;

// =====================================================================
// FORWARD DECLARATIONS
// FIX : sans ces déclarations, le compilateur ne trouve pas les
// fonctions appelées avant leur définition (ex: setup() appelle
// mqttConnect() qui est définie plus bas).
// =====================================================================
void    buzzOK();
void    buzzERR();
void    ledGreen();
void    ledRed();
bool    syncNTPTime();
DateTime getCurrentDateTime();
bool    mqttPublish(const char* topic, const char* payload, bool retained = false);
void    mqttRespond(const String &msg);
void    publishStatus();
void    confirmEnroll(bool ok, const char* errMsg = "");
void    mqttCallback(char* topic, byte* payload, unsigned int length);
bool    mqttConnect();
void    mqttLoop();
bool    publishPointage(uint8_t fp_id, const DateTime &dt);
void    handleEnrollStateMachine();
int     verifyID();
void    writeEntry(uint8_t id);
bool    readTailEntry(uint16_t &outSeq, uint8_t &outId, DateTime &outDT);
void    advanceTail();
void    markHostSeen();
String  readHostLineNonBlocking();
void    hostPrint(const String &s);
void    hostPrint(const char *s);
void    syncStateMachineStep();
void    traiterCommandeTCP(const String &cmd);
void    handleIncomingHost();

// =====================================================================
// LEDs & Buzzer
// FIX : on utilise ledcAttach/ledcWriteTone/ledcWrite au lieu de
// tone()/noTone() qui déclenchent "ledc: LEDC is not initialized"
// sur ESP32 Arduino Core >= 3.x.
// ledcAttach() est appelé une seule fois dans setup().
// =====================================================================
// FIX Core 2.x : ledcWriteTone/ledcWrite prennent le NUMÉRO DE CANAL,
// pas le numéro de pin. Le canal est initialisé dans setup() via
// ledcSetup() + ledcAttachPin().
void buzzOK() {
  for (int i = 0; i < 2; i++) {
    ledcWriteTone(BUZZER_LEDC_CHANNEL, 2000);
    delay(80);
    ledcWrite(BUZZER_LEDC_CHANNEL, 0);   // silence = duty 0
    delay(50);
  }
}

void buzzERR() {
  for (int i = 0; i < 2; i++) {
    ledcWriteTone(BUZZER_LEDC_CHANNEL, 400);
    delay(120);
    ledcWrite(BUZZER_LEDC_CHANNEL, 0);
    delay(60);
  }
}

void ledGreen() { digitalWrite(PIN_GREEN, HIGH); delay(200); digitalWrite(PIN_GREEN, LOW); }
void ledRed()   { digitalWrite(PIN_RED,   HIGH); delay(200); digitalWrite(PIN_RED,   LOW); }

// =====================================================================
// EEPROM utilitaires
// =====================================================================
uint16_t eepromReadU16(int addr) {
  return (uint16_t)((EEPROM.read(addr) << 8) | EEPROM.read(addr + 1));
}
void eepromWriteU16(int addr, uint16_t v) {
  EEPROM.write(addr,   (v >> 8) & 0xFF);
  EEPROM.write(addr+1,  v       & 0xFF);
  EEPROM.commit();
}
void loadIndexes() {
  headIndex  = eepromReadU16(ADDR_HEAD);
  tailIndex  = eepromReadU16(ADDR_TAIL);
  seqCounter = eepromReadU16(ADDR_SEQ);
  int dataBytes = EEPROM_SIZE - START_ADDR;
  maxEntries = dataBytes / ENTRY_SIZE;
  if (maxEntries <= 0) maxEntries = 1;
  if (headIndex  >= (uint16_t)maxEntries) headIndex  = 0;
  if (tailIndex  >= (uint16_t)maxEntries) tailIndex  = 0;
  if (seqCounter == 0xFFFF)               seqCounter = 0;
}
void saveIndexes() {
  eepromWriteU16(ADDR_HEAD, headIndex);
  eepromWriteU16(ADDR_TAIL, tailIndex);
  eepromWriteU16(ADDR_SEQ,  seqCounter);
}
int      entryAddr(uint16_t idx) { return START_ADDR + (idx % maxEntries) * ENTRY_SIZE; }
bool     hasPendingEntries()     { return headIndex != tailIndex; }
uint16_t pendingCount() {
  return (headIndex >= tailIndex)
    ? (headIndex - tailIndex)
    : ((uint16_t)maxEntries - tailIndex + headIndex);
}

// =====================================================================
// NTP
// =====================================================================
bool syncNTPTime() {
  if (WiFi.status() != WL_CONNECTED) return false;
  USB.println("[NTP] Synchronisation...");
  configTime(GMT_OFFSET, DST_OFFSET, NTP_SERVER1, NTP_SERVER2, NTP_SERVER3);
  struct tm ti;
  int retry = 0;
  while (!getLocalTime(&ti) && retry < 10) { delay(500); USB.print("."); retry++; }
  USB.println();
  if (retry < 10) {
    timeSynced  = true;
    lastNTPsync = millis();
    char buf[30];
    strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", &ti);
    USB.printf("[NTP] %s\n", buf);
    buzzOK(); ledGreen();
    return true;
  }
  USB.println("[NTP] Echec");
  timeSynced = false;
  return false;
}

DateTime getCurrentDateTime() {
  struct tm ti;
  DateTime dt;
  if (timeSynced && getLocalTime(&ti)) {
    dt.year   = ti.tm_year + 1900;
    dt.month  = ti.tm_mon  + 1;
    dt.day    = ti.tm_mday;
    dt.hour   = ti.tm_hour;
    dt.minute = ti.tm_min;
    dt.second = ti.tm_sec;
  } else {
    USB.println("[WARN] Pas de temps NTP !");
  }
  return dt;
}

// =====================================================================
// MQTT helpers
// =====================================================================
bool mqttPublish(const char* topic, const char* payload, bool retained) {
  if (!mqtt.connected()) return false;
  bool ok = mqtt.publish(topic, payload, retained);
  USB.printf("[MQTT] pub %-40s %s\n", topic, ok ? "OK" : "FAIL");
  return ok;
}

void mqttRespond(const String &msg) {
  mqttPublish(TOPIC_RESPONSE, msg.c_str());
  USB.println("[RSP] " + msg);
}

// FIX : "online":true ajouté dans le payload pour que le PHP/JS
// puisse distinguer un statut "en ligne" du LWT "online":false.
void publishStatus() {
  DateTime now = getCurrentDateTime();
  char ts[30];
  snprintf(ts, sizeof(ts), "%04d-%02d-%02d %02d:%02d:%02d",
    now.year, now.month, now.day, now.hour, now.minute, now.second);
  const char* stNames[] = {"IDLE","WAIT_SCAN1","WAIT_LIFT","WAIT_SCAN2","STORING","CONFIRM"};
  char payload[300];
  snprintf(payload, sizeof(payload),
    "{\"online\":true,\"ip\":\"%s\",\"rssi\":%d,\"time\":\"%s\","
    "\"enroll_state\":\"%s\",\"eeprom_pending\":%u,"
    "\"ntp_synced\":%s,\"uptime_s\":%lu}",
    WiFi.localIP().toString().c_str(),
    WiFi.RSSI(),
    ts,
    stNames[enrollState],
    (unsigned)pendingCount(),
    timeSynced ? "true" : "false",
    millis() / 1000UL);
  mqttPublish(TOPIC_STATUS, payload, true);  // retained = true
  lastStatusPublish = millis();
}

// =====================================================================
// Confirmation enrolement (MQTT)
// =====================================================================
void confirmEnroll(bool ok, const char* errMsg) {
  if (enrollQueueId >= 0) {
    char payload[192];
    if (ok) {
      snprintf(payload, sizeof(payload),
        "{\"queue_id\":%d,\"success\":true,\"fingerprint_id\":%d}",
        enrollQueueId, enrollFpId);
    } else {
      snprintf(payload, sizeof(payload),
        "{\"queue_id\":%d,\"success\":false,\"error\":\"%s\"}",
        enrollQueueId, errMsg);
    }
    mqttPublish(TOPIC_ENROLL_CONFIRM, payload);
  } else {
    // mode manuel (ENROLL envoyé via bioaccess/esp32/command)
    mqttRespond(ok
      ? "ENROLL_OK:"  + String(enrollFpId)
      : "ENROLL_FAIL:" + String(enrollFpId) + ":" + String(errMsg));
  }
  if (ok) {
    USB.printf("[ENROLL] Succes fp=%d q=%d\n", enrollFpId, enrollQueueId);
    buzzOK(); ledGreen(); ledGreen();
  } else {
    USB.printf("[ENROLL] Echec : %s\n", errMsg);
    buzzERR(); ledRed();
  }
  enrollState    = ENROLL_IDLE;
  enrollFpId     = -1;
  enrollQueueId  = -1;
}

// =====================================================================
// MQTT callback (reception des messages)
// =====================================================================
void mqttCallback(char* topic, byte* payload, unsigned int length) {
  char buf[512];
  unsigned int len = min(length, (unsigned int)(sizeof(buf) - 1));
  memcpy(buf, payload, len);
  buf[len] = '\0';
  String msg = String(buf);
  USB.printf("[MQTT] recv %-40s → %s\n", topic, buf);

  // ── bioaccess/esp32/enroll/command ───────────────────────────────
  if (strcmp(topic, TOPIC_ENROLL_CMD) == 0) {
    if (enrollState != ENROLL_IDLE) {
      mqttRespond("ERR: enrolement deja en cours");
      return;
    }
    JsonDocument doc;
    if (deserializeJson(doc, msg) != DeserializationError::Ok) {
      USB.println("[ENROLL] JSON invalide");
      return;
    }
    enrollFpId    = doc["fingerprint_id"].as<int>();
    enrollQueueId = doc["queue_id"].as<int>();
    if (enrollFpId < 1 || enrollFpId > 127) {
      char fail[96];
      snprintf(fail, sizeof(fail),
        "{\"queue_id\":%d,\"success\":false,\"error\":\"ID hors plage 1-127\"}",
        enrollQueueId);
      mqttPublish(TOPIC_ENROLL_CONFIRM, fail);
      enrollFpId = enrollQueueId = -1;
      return;
    }
    USB.printf("[ENROLL] Demarre fp=%d q=%d\n", enrollFpId, enrollQueueId);
    enrollDeadline = millis() + 90000UL;
    enrollState    = ENROLL_WAIT_SCAN1;
    buzzOK(); ledGreen(); ledGreen();
    return;
  }

  // ── bioaccess/esp32/command ──────────────────────────────────────
  // L'ESP32 reçoit ici des commandes en TEXTE BRUT (pas du JSON).
  // Exemples : "PING", "STATUS", "DEL 5", "COUNT", "LIST", "CLEAR"
  if (strcmp(topic, TOPIC_COMMAND) == 0) {
    msg.trim();

    if (msg.equalsIgnoreCase("PING")) {
      mqttRespond("PONG");
      return;
    }

    if (msg.equalsIgnoreCase("STATUS")) {
      publishStatus();
      return;
    }

    if (msg.equalsIgnoreCase("NTP_SYNC")) {
      mqttRespond(syncNTPTime() ? "NTP_SYNC_OK" : "NTP_SYNC_FAIL");
      return;
    }

    if (msg.equalsIgnoreCase("COUNT")) {
      mqttRespond(finger.getTemplateCount() == FINGERPRINT_OK
        ? "FP_COUNT:" + String(finger.templateCount)
        : "FP_COUNT:ERR");
      return;
    }

    if (msg.equalsIgnoreCase("LIST")) {
      USB.println("== EEPROM DUMP ==");
      for (int i = 0; i < maxEntries; i++) {
        int addr = entryAddr(i);
        uint16_t seq = (uint16_t)((EEPROM.read(addr)   << 8) | EEPROM.read(addr+1));
        uint8_t  id  =                 EEPROM.read(addr+2);
        uint16_t yr  = (uint16_t)((EEPROM.read(addr+3) << 8) | EEPROM.read(addr+4));
        if (!(seq == 0xFFFF && id == 0xFF)) {
          char b[80];
          snprintf(b, sizeof(b), "slot %d seq=%u id=%u %04u/%02u/%02u %02u:%02u:%02u",
            i, (unsigned)seq, (unsigned)id, (unsigned)yr,
            EEPROM.read(addr+5), EEPROM.read(addr+6),
            EEPROM.read(addr+7), EEPROM.read(addr+8), EEPROM.read(addr+9));
          USB.println(b);
        }
      }
      USB.println("== END DUMP ==");
      mqttRespond("LIST_SENT_USB");
      return;
    }

    if (msg.equalsIgnoreCase("CLEAR")) {
      for (int i = 0; i < EEPROM_SIZE; i++) EEPROM.write(i, 0xFF);
      EEPROM.commit();
      headIndex = tailIndex = 0;
      seqCounter = 0;
      saveIndexes();
      mqttRespond("EEPROM_CLEARED");
      return;
    }

    if (msg.startsWith("DEL ")) {
      int id = msg.substring(4).toInt();
      if (id < 1 || id > 127) { mqttRespond("ERR: ID invalide (1-127)"); return; }
      uint8_t p = finger.deleteModel(id);
      if (p == FINGERPRINT_OK) { mqttRespond("DEL_OK:"   + String(id)); buzzOK(); ledGreen(); }
      else                     { mqttRespond("DEL_FAIL:" + String(id) + ":" + String(p)); buzzERR(); ledRed(); }
      return;
    }

    if (msg.startsWith("INFO ")) {
      int id = msg.substring(5).toInt();
      mqttRespond(finger.loadModel(id) == FINGERPRINT_OK
        ? "INFO:" + String(id) + ":PRESENT"
        : "INFO:" + String(id) + ":ABSENT");
      return;
    }

    // ENROLL manuel via commande texte (sans queue_id en base)
    if (msg.startsWith("ENROLL ")) {
      if (enrollState != ENROLL_IDLE) { mqttRespond("ERR: enrolement deja en cours"); return; }
      int id = msg.substring(7).toInt();
      if (id < 1 || id > 127) { mqttRespond("ERR: ID invalide (1-127)"); return; }
      enrollFpId     = id;
      enrollQueueId  = -1;   // -1 = pas de queue_id (mode manuel)
      enrollDeadline = millis() + 90000UL;
      enrollState    = ENROLL_WAIT_SCAN1;
      mqttRespond("ENROLL_STARTED:" + String(id));
      buzzOK(); ledGreen();
      return;
    }

    mqttRespond("Cmds: PING STATUS NTP_SYNC COUNT LIST CLEAR DEL <id> INFO <id> ENROLL <id>");
  }
}

// =====================================================================
// MQTT connexion / reconnexion
// =====================================================================
bool mqttConnect() {
  if (WiFi.status() != WL_CONNECTED) return false;
  USB.printf("[MQTT] Connexion %s:%d...\n", MQTT_BROKER, MQTT_PORT);

  // LWT : si l'ESP32 se déconnecte brutalement, le broker publie
  // {"online":false} sur TOPIC_STATUS (retained) pour alerter le PHP/JS.
  const char* lwt = "{\"online\":false}";
  bool ok;
  if (strlen(MQTT_USER) > 0)
    ok = mqtt.connect(MQTT_CLIENT_ID, MQTT_USER, MQTT_PASSWORD_STR, TOPIC_STATUS, 0, true, lwt);
  else
    ok = mqtt.connect(MQTT_CLIENT_ID, nullptr, nullptr, TOPIC_STATUS, 0, true, lwt);

  if (ok) {
    USB.println("[MQTT] Connecte !");
    mqtt.subscribe(TOPIC_ENROLL_CMD, 1);  // QoS 1 pour l'enrôlement (critique)
    mqtt.subscribe(TOPIC_COMMAND,    0);  // QoS 0 pour les commandes manuelles
    USB.printf("[MQTT] Abonne : %s  %s\n", TOPIC_ENROLL_CMD, TOPIC_COMMAND);
    publishStatus();
    buzzOK();
  } else {
    USB.printf("[MQTT] Echec rc=%d\n", mqtt.state());
  }
  return ok;
}

void mqttLoop() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (!mqtt.connected()) {
    unsigned long now = millis();
    if (now - lastMqttReconnect >= MQTT_RECONNECT_MS) {
      lastMqttReconnect = now;
      mqttConnect();
    }
  } else {
    mqtt.loop();
  }
}

// =====================================================================
// Pointage MQTT
// =====================================================================
bool publishPointage(uint8_t fp_id, const DateTime &dt) {
  if (!mqtt.connected()) return false;
  char payload[96];
  snprintf(payload, sizeof(payload),
    "{\"fingerprint_id\":%u,\"datetime\":\"%04u-%02u-%02u %02u:%02u:%02u\"}",
    (unsigned)fp_id,
    (unsigned)dt.year,  (unsigned)dt.month,  (unsigned)dt.day,
    (unsigned)dt.hour,  (unsigned)dt.minute, (unsigned)dt.second);
  bool ok = mqtt.publish(TOPIC_POINTAGE, payload, false);
  USB.printf("[POINTAGE] MQTT %s fp=%u\n", ok ? "OK" : "FAIL", (unsigned)fp_id);
  return ok;
}

// =====================================================================
// Machine a etats enrolement (non-bloquant, appelee dans loop)
// =====================================================================
void handleEnrollStateMachine() {
  if (enrollState == ENROLL_IDLE) return;
  if (millis() > enrollDeadline) {
    USB.println("[ENROLL] Timeout — annulation");
    confirmEnroll(false, "Timeout : doigt non pose a temps");
    return;
  }
  int p;
  switch (enrollState) {
    case ENROLL_WAIT_SCAN1:
      p = finger.getImage();
      if (p == FINGERPRINT_NOFINGER) return;
      if (p != FINGERPRINT_OK) { confirmEnroll(false, "Erreur lecture scan 1"); return; }
      p = finger.image2Tz(1);
      if (p != FINGERPRINT_OK) { confirmEnroll(false, "Erreur conversion scan 1"); return; }
      USB.println("[ENROLL] Scan 1 OK — retirez le doigt");
      mqttRespond("ENROLL_SCAN1_OK");
      buzzOK();
      enrollState = ENROLL_WAIT_LIFT;
      break;

    case ENROLL_WAIT_LIFT:
      p = finger.getImage();
      if (p != FINGERPRINT_NOFINGER) return;
      USB.println("[ENROLL] Doigt retire — replacez le meme doigt...");
      mqttRespond("ENROLL_LIFT_OK");
      ledGreen();
      enrollState = ENROLL_WAIT_SCAN2;
      break;

    case ENROLL_WAIT_SCAN2:
      p = finger.getImage();
      if (p == FINGERPRINT_NOFINGER) return;
      if (p != FINGERPRINT_OK) { confirmEnroll(false, "Erreur lecture scan 2"); return; }
      p = finger.image2Tz(2);
      if (p != FINGERPRINT_OK) { confirmEnroll(false, "Erreur conversion scan 2"); return; }
      USB.println("[ENROLL] Scan 2 OK — creation du modele...");
      mqttRespond("ENROLL_SCAN2_OK");
      enrollState = ENROLL_STORING;
      break;

    case ENROLL_STORING:
      p = finger.createModel();
      if (p != FINGERPRINT_OK) { buzzERR(); ledRed(); confirmEnroll(false, "Erreur creation modele"); return; }
      p = finger.storeModel(enrollFpId);
      if (p != FINGERPRINT_OK) { buzzERR(); ledRed(); confirmEnroll(false, "Erreur stockage modele"); return; }
      USB.printf("[ENROLL] Empreinte stockee ID=%d\n", enrollFpId);
      enrollState = ENROLL_CONFIRM;
      break;

    case ENROLL_CONFIRM:
      confirmEnroll(true);
      break;

    default:
      enrollState = ENROLL_IDLE;
      break;
  }
}

// =====================================================================
// Verification empreinte (mode normal)
// =====================================================================
int verifyID() {
  if (enrollState != ENROLL_IDLE) return -1;
  int p = finger.getImage();
  if (p != FINGERPRINT_OK) return -1;
  p = finger.image2Tz();
  if (p != FINGERPRINT_OK) return -1;
  p = finger.fingerFastSearch();
  if (p != FINGERPRINT_OK) { buzzERR(); ledRed(); return -1; }
  USB.printf("[VERIFY] ID=%d conf=%d\n", finger.fingerID, finger.confidence);
  buzzOK(); ledGreen();
  return finger.fingerID;
}

// =====================================================================
// EEPROM ecriture / lecture (fallback si MQTT indisponible)
// =====================================================================
void writeEntry(uint8_t id) {
  uint16_t seq = seqCounter++;
  int addr = entryAddr(headIndex);
  DateTime now = getCurrentDateTime();
  EEPROM.write(addr+0, (seq    >> 8) & 0xFF); EEPROM.write(addr+1, seq    & 0xFF);
  EEPROM.write(addr+2, id);
  EEPROM.write(addr+3, (now.year >> 8) & 0xFF); EEPROM.write(addr+4, now.year & 0xFF);
  EEPROM.write(addr+5, now.month); EEPROM.write(addr+6, now.day);
  EEPROM.write(addr+7, now.hour);  EEPROM.write(addr+8, now.minute);
  EEPROM.write(addr+9, now.second);
  EEPROM.commit();
  headIndex = (headIndex + 1) % maxEntries;
  if (headIndex == tailIndex) tailIndex = (tailIndex + 1) % maxEntries;
  saveIndexes();
  char buf[120];
  snprintf(buf, sizeof(buf), "[EEPROM] STORED seq=%u id=%u %04u/%02u/%02u %02u:%02u:%02u",
    (unsigned)seq, (unsigned)id,
    now.year, now.month, now.day, now.hour, now.minute, now.second);
  USB.println(buf);
  buzzOK(); ledGreen();
  if (hostPresent && !syncInProgress) syncInProgress = true;
}

bool readTailEntry(uint16_t &outSeq, uint8_t &outId, DateTime &outDT) {
  if (!hasPendingEntries()) return false;
  int addr = entryAddr(tailIndex);
  outSeq = (uint16_t)((EEPROM.read(addr) << 8) | EEPROM.read(addr+1));
  outId  =  EEPROM.read(addr+2);
  uint16_t year = (uint16_t)((EEPROM.read(addr+3) << 8) | EEPROM.read(addr+4));
  outDT = DateTime(year,
    EEPROM.read(addr+5), EEPROM.read(addr+6),
    EEPROM.read(addr+7), EEPROM.read(addr+8), EEPROM.read(addr+9));
  return true;
}

void advanceTail() {
  if (hasPendingEntries()) { tailIndex = (tailIndex + 1) % maxEntries; saveIndexes(); }
}

// =====================================================================
// Mode PULL TCP (fallback EEPROM → PHP)
// =====================================================================
void markHostSeen() { hostPresent = true; lastHostSeen = millis(); }

String readHostLineNonBlocking() {
  if (!tcpClient || !tcpClient.connected()) return "";
  if (!tcpClient.available()) return "";
  String s = tcpClient.readStringUntil('\n');
  s.trim();
  return s;
}

void hostPrint(const String &s) {
  if (tcpClient && tcpClient.connected()) tcpClient.println(s);
  USB.println("[HOST] " + s);
}
void hostPrint(const char *s) { hostPrint(String(s)); }

void traiterCommandeTCP(const String &cmd) {
  if (cmd.length() == 0) return;
  USB.print("[TCP CMD] "); USB.println(cmd);
  markHostSeen();
  if (cmd.startsWith("ACK") || cmd.startsWith("NACK")) { ackInbox = cmd; return; }
  if (cmd.equalsIgnoreCase("PING"))  { hostPrint("PONG"); return; }
  if (cmd.equalsIgnoreCase("SYNC"))  {
    if (hasPendingEntries()) syncInProgress = true;
    else hostPrint("BEGIN_SYNC 0");
    return;
  }
  hostPrint("Use MQTT. TCP only: PING SYNC ACK NACK");
}

void syncStateMachineStep() {
  if (hostPresent && (millis() - lastHostSeen > HOST_TIMEOUT_MS)) {
    hostPresent = false; syncInProgress = false; waitingAck = false;
    USB.println("[SYNC] Host timeout"); hostPrint("HOST_TIMEOUT"); return;
  }
  if (!syncInProgress) {
    if (hostPresent && hasPendingEntries()) {
      char buf[32]; snprintf(buf, sizeof(buf), "BEGIN_SYNC %u", (unsigned)pendingCount());
      hostPrint(buf); syncInProgress = true; waitingAck = false;
    }
    return;
  }
  if (!hasPendingEntries()) {
    hostPrint("END_SYNC"); syncInProgress = false; waitingAck = false; buzzOK(); ledGreen(); return;
  }
  if (!waitingAck) {
    uint16_t seq; uint8_t id; DateTime dt;
    if (!readTailEntry(seq, id, dt)) { hostPrint("END_SYNC"); syncInProgress = false; return; }
    currentSeq = seq;
    char line[120];
    snprintf(line, sizeof(line), "ENTRY %u %u %04u/%02u/%02u %02u:%02u:%02u",
      (unsigned)seq, (unsigned)id, dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second);
    lastSentLine = String(line); hostPrint(lastSentLine);
    waitingAck = true; ackRetriesLeft = ACK_RETRIES; ackDeadline = millis() + ACK_TIMEOUT_MS;
    return;
  }
  String resp = ackInbox.length() ? ackInbox : readHostLineNonBlocking();
  if (resp.length()) {
    ackInbox = ""; markHostSeen(); resp.trim();
    if (resp.startsWith("ACK")) {
      int sp = resp.indexOf(' ');
      if (sp > 0) { uint16_t as = (uint16_t)resp.substring(sp+1).toInt(); if (as != currentSeq) return; }
      advanceTail(); waitingAck = false; lastSentLine = ""; return;
    } else if (resp.startsWith("NACK")) {
      hostPrint("SYNC_ABORT"); syncInProgress = false; waitingAck = false; return;
    } else {
      traiterCommandeTCP(resp); return;
    }
  }
  if (waitingAck && millis() > ackDeadline) {
    if (ackRetriesLeft > 0) { hostPrint(lastSentLine); ackRetriesLeft--; ackDeadline = millis() + ACK_TIMEOUT_MS; }
    else { hostPrint("SYNC_ABORT"); syncInProgress = false; waitingAck = false; }
  }
}

void handleIncomingHost() {
  if (!tcpClient || !tcpClient.connected()) {
    WiFiClient nc = tcpServer.accept();
    if (nc) {
      tcpClient = nc; markHostSeen();
      USB.print("[TCP] Client: "); USB.println(tcpClient.remoteIP().toString());
      hostPrint("ESP32_READY");
    }
  }
  if (!tcpClient || !tcpClient.connected()) return;
  String line = readHostLineNonBlocking();
  while (line.length()) {
    if (line.startsWith("ACK") || line.startsWith("NACK")) ackInbox = line;
    else traiterCommandeTCP(line);
    line = readHostLineNonBlocking();
  }
}

// =====================================================================
// SETUP
// =====================================================================
void setup() {
  // GPIO
  pinMode(PIN_GREEN,  OUTPUT);
  pinMode(PIN_RED,    OUTPUT);
  digitalWrite(PIN_GREEN, LOW);
  digitalWrite(PIN_RED,   LOW);

  // FIX Core 2.x : initialiser LEDC AVANT tout appel buzzer.
  // ledcSetup  → configure le canal (fréquence initiale + résolution)
  // ledcAttachPin → associe la pin GPIO au canal
  ledcSetup(BUZZER_LEDC_CHANNEL, 2000, BUZZER_LEDC_RES);
  ledcAttachPin(PIN_BUZZER, BUZZER_LEDC_CHANNEL);
  ledcWrite(BUZZER_LEDC_CHANNEL, 0);  // silence au démarrage

  USB.begin(115200);
  delay(500);
  USB.println("\n=== Systeme de pointage ESP32 WiFi+MQTT ===");

  EEPROM.begin(EEPROM_SIZE);
  FPSER.begin(57600, SERIAL_8N1, 16, 17);
  finger.begin(57600);
  delay(200);
  USB.println(finger.verifyPassword() ? "[FP] Capteur OK" : "[FP] Capteur non detecte !");

  loadIndexes();
  USB.printf("[EEPROM] maxEntries=%d head=%d tail=%d seq=%d\n",
    maxEntries, headIndex, tailIndex, seqCounter);

  // Première utilisation : initialiser l'EEPROM
  if (EEPROM.read(0) == 0xFF && EEPROM.read(1) == 0xFF && EEPROM.read(2) == 0xFF) {
    USB.println("[EEPROM] Init premiere utilisation...");
    for (int i = 0; i < EEPROM_SIZE; i++) EEPROM.write(i, 0xFF);
    EEPROM.commit();
    headIndex = tailIndex = seqCounter = 0;
    saveIndexes();
  }

  USB.print("[WiFi] Connexion : "); USB.println(WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  int tries = 0;
  while (WiFi.status() != WL_CONNECTED && tries < 30) { delay(500); USB.print("."); tries++; }
  USB.println();

  if (WiFi.status() == WL_CONNECTED) {
    USB.print("[WiFi] Connecte ! IP: "); USB.println(WiFi.localIP());
    if (!syncNTPTime()) { USB.println("[WARN] NTP indisponible"); buzzERR(); ledRed(); }
    tcpServer.begin();
    USB.printf("[TCP] Fallback port %d\n", TCP_PORT);
  } else {
    USB.println("[WiFi] ECHEC — mode hors-ligne");
    buzzERR(); ledRed();
  }

  mqtt.setServer(MQTT_BROKER, MQTT_PORT);
  mqtt.setCallback(mqttCallback);
  mqtt.setKeepAlive(MQTT_KEEPALIVE_S);
  mqtt.setBufferSize(512);

  if (WiFi.status() == WL_CONNECTED) mqttConnect();
}

// =====================================================================
// LOOP
// =====================================================================
void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    USB.println("[WiFi] Perdu — reconnexion...");
    WiFi.reconnect();
    delay(5000);
    return;
  }

  // Resynchronisation NTP périodique
  if (millis() - lastNTPsync > NTP_SYNC_INTERVAL) syncNTPTime();

  // MQTT keepalive + réception messages
  mqttLoop();

  // Status périodique
  if (mqtt.connected() && millis() - lastStatusPublish > STATUS_INTERVAL_MS) publishStatus();

  // TCP fallback (mode PULL EEPROM)
  syncStateMachineStep();
  handleIncomingHost();

  // ENROLEMENT ou VERIFICATION (mutuellement exclusifs)
  if (enrollState != ENROLL_IDLE) {
    handleEnrollStateMachine();
    delay(20);
    return;
  }

  // Mode VERIFY normal
  int id = verifyID();
  if (id >= 0) {
    if (!timeSynced) syncNTPTime();
    DateTime now = getCurrentDateTime();
    bool published = publishPointage((uint8_t)id, now);
    if (!published) {
      writeEntry((uint8_t)id);
      USB.println("[EEPROM] Sauvegarde locale — sync PULL ulterieure");
    }
    delay(1200);
  }

  delay(20);
}
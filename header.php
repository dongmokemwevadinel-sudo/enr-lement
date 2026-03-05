<?php
// header.php — MQTT remplace TCP ESP32
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$csrf_token   = generateCSRFToken();
$headerMqttOk = mqttIsOnline(); // Vérifie le broker MQTT au lieu de l'ESP32 directement
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Système de Pointage par Empreinte Digitale</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- MQTT.js WebSocket — connexion temps réel pour tout le site -->
    <script src="https://unpkg.com/mqtt@5.3.4/dist/mqtt.min.js"></script>
    <style>
        :root {
            --primary-color:   #2c3e50;
            --secondary-color: #3498db;
            --accent-color:    #e74c3c;
            --light-color:     #ecf0f1;
            --dark-color:      #34495e;
            --success-color:   #2ecc71;
            --warning-color:   #f39c12;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f5f7fa; color: #333; line-height: 1.6; }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white; padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,.1);
            display: flex; justify-content: space-between; align-items: center;
        }

        .logo { display: flex; align-items: center; gap: 15px; }
        .logo i { font-size: 2rem; color: var(--secondary-color); }
        .logo h1 { font-size: 1.8rem; font-weight: 600; }

        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-info i { font-size: 1.5rem; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; font-size: 1.1rem; }
        .user-role { font-size: .9rem; opacity: .8; }

        /* Badge statut MQTT dans le header */
        .mqtt-header-badge {
            padding: 5px 12px; border-radius: 20px; font-size: .8rem; font-weight: 500;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all .3s ease;
        }
        .mqtt-online  { background: rgba(76,175,80,.25);  color: #a5d6a7; border: 1px solid rgba(76,175,80,.4); }
        .mqtt-offline { background: rgba(244,67,54,.25);  color: #ef9a9a; border: 1px solid rgba(244,67,54,.4); }
        .mqtt-connecting { background: rgba(255,152,0,.25); color: #ffe082; border: 1px solid rgba(255,152,0,.4); }

        .nav-container { background-color: white; box-shadow: 0 2px 5px rgba(0,0,0,.05); }
        .nav-menu { display: flex; list-style: none; padding: 0; margin: 0; }
        .nav-item { position: relative; }
        .nav-link {
            display: block; padding: 1rem 1.5rem;
            color: var(--dark-color); text-decoration: none; font-weight: 500;
            transition: all .3s; border-bottom: 3px solid transparent;
        }
        .nav-link:hover, .nav-link.active {
            background-color: #f8f9fa; color: var(--secondary-color);
            border-bottom: 3px solid var(--secondary-color);
        }
        .nav-link i { margin-right: 8px; font-size: 1.1rem; }

        .logout-btn {
            background-color: var(--accent-color); color: white; border: none;
            padding: .5rem 1rem; border-radius: 4px; cursor: pointer; font-weight: 500;
            transition: background-color .3s; display: flex; align-items: center; gap: 5px;
            text-decoration: none;
        }
        .logout-btn:hover { background-color: #c0392b; color: white; }

        .main-content { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.05); padding: 1.5rem; margin-bottom: 2rem; }
        .card-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.5rem; color: var(--primary-color); font-weight: 600; }

        .alert { padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .btn { display: inline-block; padding: .5rem 1rem; border-radius: 4px; text-decoration: none; font-weight: 500; cursor: pointer; transition: all .3s; border: none; }
        .btn-primary { background-color: var(--secondary-color); color: white; }
        .btn-primary:hover { background-color: #2980b9; }
        .btn-danger  { background-color: var(--accent-color); color: white; }
        .btn-danger:hover  { background-color: #c0392b; }
        .btn-success { background-color: var(--success-color); color: white; }
        .btn-success:hover { background-color: #27ae60; }

        table { width: 100%; border-collapse: collapse; margin: 1.5rem 0; }
        table th, table td { padding: .75rem; text-align: left; border-bottom: 1px solid #ddd; }
        table th { background-color: #f8f9fa; font-weight: 600; color: var(--dark-color); }
        table tr:hover { background-color: #f8f9fa; }

        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; margin-bottom: .5rem; font-weight: 500; }
        .form-input { width: 100%; padding: .75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; }
        .form-input:focus { outline: none; border-color: var(--secondary-color); box-shadow: 0 0 0 3px rgba(52,152,219,.2); }

        @media(max-width:768px){
            .header { flex-direction: column; text-align: center; gap: 15px; }
            .user-info { justify-content: center; }
            .nav-menu { flex-direction: column; }
            .nav-link { border-bottom: 1px solid #eee; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
            <i class="fas fa-fingerprint"></i>
            <h1>Système de Pointage</h1>
        </div>
        <div class="user-info">
            <!-- Badge statut MQTT (mis à jour en temps réel via WebSocket) -->
            <span id="mqttHeaderBadge"
                  class="mqtt-header-badge <?= $headerMqttOk ? 'mqtt-online' : 'mqtt-offline' ?>">
                <i class="fas fa-broadcast-tower"></i>
                <span id="mqttHeaderText">
                    <?= $headerMqttOk ? 'MQTT Connecté' : 'MQTT Hors-ligne' ?>
                </span>
                <span style="opacity:.7;font-size:.7rem;">(<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)</span>
            </span>

            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($_SESSION['username']  ?? 'Utilisateur') ?></div>
                <div class="user-role"><?= htmlspecialchars($_SESSION['user_role'] ?? 'Rôle') ?></div>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Déconnexion
            </a>
        </div>
    </header>

    <div class="nav-container">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="index.php"
                   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i>Accueil
                </a>
            </li>
            <li class="nav-item">
                <a href="presence.php"
                   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'presence.php' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check"></i>Présence
                </a>
            </li>
            <li class="nav-item">
                <a href="gestion_empreintes.php"
                   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'gestion_empreintes.php' ? 'active' : '' ?>">
                    <i class="fas fa-fingerprint"></i>Gestion des empreintes
                </a>
            </li>
            <li class="nav-item">
                <a href="reports.php"
                   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i>Rapports
                </a>
            </li>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <li class="nav-item">
                <a href="admin_dashboard.php"
                   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i>Administration
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Client MQTT global partagé par toutes les pages -->
    <script>
    // ── Configuration MQTT WebSocket ────────────────────────────
    const SITE_MQTT_WS   = 'ws://<?= MQTT_HOST ?>:<?= MQTT_WS_PORT ?>';
    const SITE_MQTT_EVTS = '<?= MQTT_TOPIC_EVENTS ?>';
    const SITE_MQTT_STS  = '<?= MQTT_TOPIC_STATUS ?>';

    // Client MQTT global (accessible par toutes les pages via window.siteMqtt)
    window.siteMqtt = null;
    window.siteMqttConnected = false;

    (function initHeaderMqtt() {
        const badge    = document.getElementById('mqttHeaderBadge');
        const badgeTxt = document.getElementById('mqttHeaderText');

        function setBadge(state) {
            badge.className = 'mqtt-header-badge';
            if (state === 'online') {
                badge.classList.add('mqtt-online');
                badgeTxt.textContent = 'MQTT Connecté';
            } else if (state === 'connecting') {
                badge.classList.add('mqtt-connecting');
                badgeTxt.textContent = 'MQTT Connexion…';
            } else {
                badge.classList.add('mqtt-offline');
                badgeTxt.textContent = 'MQTT Hors-ligne';
            }
        }

        setBadge('connecting');

        const client = mqtt.connect(SITE_MQTT_WS, {
            clientId: 'bioaccess_site_' + Math.random().toString(36).slice(2, 8),
            clean: true,
            reconnectPeriod: 4000,
        });

        client.on('connect', () => {
            window.siteMqttConnected = true;
            setBadge('online');
            // Souscrire aux topics globaux
            client.subscribe([SITE_MQTT_STS, SITE_MQTT_EVTS + '/#']);
        });

        client.on('reconnect', () => setBadge('connecting'));
        client.on('disconnect', () => { window.siteMqttConnected = false; setBadge('offline'); });
        client.on('error', ()     => setBadge('offline'));

        client.on('message', (topic, raw) => {
            let data = {};
            try { data = JSON.parse(raw.toString()); } catch {}

            // Dispatcher les évènements globaux (pages filles écoutent cet event)
            window.dispatchEvent(new CustomEvent('mqtt:message', { detail: { topic, data } }));

            // Heartbeat ESP32 (toutes les 30 s) → badge header devient "Connecté"
            if (topic === SITE_MQTT_STS) {
                setBadge('online');
                // Si le message contient une IP, l'ESP32 est clairement en ligne
                if (data.ip) {
                    window.dispatchEvent(new CustomEvent('esp32:online', { detail: data }));
                }
            }

            if (topic.includes('/pointage') && data.prenom) {
                console.log('[MQTT] Pointage :', data.prenom, data.nom, data.type);
            }
        });

        window.siteMqtt = client;
    })();
    </script>

    <main class="main-content">
        <!-- Le contenu spécifique à chaque page sera inséré ici -->
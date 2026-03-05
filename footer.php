<!-- ==================== -->
<!-- FOOTER DYNAMIQUE     -->
<!-- ==================== -->
<?php
// Statistiques dynamiques (réutilise $db ouvert par config.php)
$total_employees     = $db->querySingle("SELECT COUNT(*) FROM employees") ?: 0;
$total_points        = $db->querySingle("SELECT COUNT(*) FROM pointages")  ?: 0;
$last_sync_raw       = $db->querySingle("SELECT datetime FROM pointages ORDER BY datetime DESC LIMIT 1");
$last_sync_formatted = $last_sync_raw ? date('d/m/Y H:i', strtotime($last_sync_raw)) : 'Jamais';

// Statut broker MQTT (remplace esp32IsOnline() qui testait TCP direct)
$footerMqttOnline = mqttIsOnline();
?>

<footer class="footer mt-5 py-4"
        style="background: linear-gradient(135deg, #1976d2 0%, #0d47a1 100%);
               color: white; margin-top: 50px !important;">
    <div class="container">
        <div class="row">

            <!-- Informations système -->
            <div class="col-md-4 mb-3">
                <h6><i class="fas fa-info-circle"></i> Informations Système</h6>
                <div class="footer-stats">
                    <small>
                        <i class="fas fa-users"></i> <?= $total_employees ?> employés<br>
                        <i class="fas fa-history"></i> <?= $total_points ?> pointages<br>
                        <i class="fas fa-sync"></i> Sync: <?= $last_sync_formatted ?>
                    </small>
                </div>
            </div>

            <!-- Liens rapides -->
            <div class="col-md-4 mb-3">
                <h6><i class="fas fa-link"></i> Accès Rapide</h6>
                <div class="footer-links">
                    <small>
                        <a href="index.php"              class="text-light"><i class="fas fa-home"></i> Accueil</a><br>
                        <a href="gestion_empreintes.php" class="text-light"><i class="fas fa-fingerprint"></i> Empreintes</a><br>
                        <a href="presence.php"           class="text-light"><i class="fas fa-list-check"></i> Présence</a><br>
                        <a href="reports.php"            class="text-light"><i class="fas fa-chart-bar"></i> Rapports</a>
                    </small>
                </div>
            </div>

            <!-- Statut & copyright -->
            <div class="col-md-4 mb-3">
                <h6><i class="fas fa-copyright"></i> <?= date('Y') ?> - Système Pointage</h6>
                <div class="footer-status">
                    <small>
                        <!-- Statut MQTT (mis à jour en push via WebSocket siteMqtt) -->
                        <span id="footerMqttStatus">
                            <i class="fas fa-broadcast-tower"></i>
                            MQTT :
                            <span id="footerMqttText"
                                  class="<?= $footerMqttOnline ? 'text-success' : 'text-warning' ?>">
                                <?= $footerMqttOnline ? 'Connecté' : 'Déconnecté' ?>
                            </span>
                            <span style="opacity:.7;font-size:.7rem;">
                                (<?= MQTT_HOST ?>:<?= MQTT_PORT ?>)
                            </span>
                        </span><br>

                        <!-- Statut ESP32 (push MQTT depuis bioaccess/esp32/status) -->
                        <span id="footerEsp32Status">
                            <i class="fas fa-microchip"></i>
                            ESP32 :
                            <span id="footerEsp32Text" class="text-warning">En attente…</span>
                        </span><br>

                        <span id="footerDatabaseStatus">
                            <i class="fas fa-database"></i> DB :
                            <span class="badge bg-success">Online</span>
                        </span><br>

                        <span id="footerUptime">
                            <i class="fas fa-clock"></i>
                            <?php
                            $uptime  = time() - (int)$_SERVER['REQUEST_TIME'];
                            $hours   = floor($uptime / 3600);
                            $minutes = floor(($uptime % 3600) / 60);
                            echo "Uptime : {$hours}h {$minutes}m";
                            ?>
                        </span>
                    </small>
                </div>
            </div>
        </div>

        <hr style="border-color: rgba(255,255,255,0.2);">

        <div class="row align-items-center">
            <div class="col-md-6">
                <p class="mb-0">
                    <i class="fas fa-heart text-danger"></i> Développé avec passion |
                    <span class="text-muted">v2.0.0</span>
                </p>
            </div>
            <div class="col-md-6 text-end">
                <div class="footer-actions">
                    <button class="btn btn-sm btn-outline-light me-2" onclick="refreshData()">
                        <i class="fas fa-sync"></i> Actualiser
                    </button>
                    <button class="btn btn-sm btn-outline-light" onclick="showSystemInfo()">
                        <i class="fas fa-info"></i> Infos
                    </button>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Modal informations système -->
<div class="modal fade" id="systemInfoModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Informations Système</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="system-info">
                    <p><strong>PHP Version :</strong> <?= phpversion() ?></p>
                    <p><strong>Serveur :</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu' ?></p>
                    <p><strong>Base de données :</strong> SQLite3</p>
                    <p><strong>Utilisateur :</strong> <?= htmlspecialchars($_SESSION['username'] ?? 'Non connecté') ?></p>
                    <p><strong>Heure serveur :</strong> <?= date('H:i:s') ?></p>
                    <p>
                        <strong>Broker MQTT :</strong>
                        <span class="badge <?= $footerMqttOnline ? 'bg-success' : 'bg-danger' ?>">
                            <?= $footerMqttOnline ? 'En ligne' : 'Hors ligne' ?>
                        </span>
                        <code style="font-size:.8rem;"><?= MQTT_HOST ?>:<?= MQTT_PORT ?></code>
                    </p>
                    <p>
                        <strong>WebSocket MQTT :</strong>
                        <code style="font-size:.8rem;">ws://<?= MQTT_HOST ?>:<?= MQTT_WS_PORT ?></code>
                    </p>
                    <p>
                        <strong>ESP32 :</strong>
                        <span id="modalEsp32Status" class="badge bg-secondary">Vérification…</span>
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<style>
.footer { box-shadow: 0 -4px 15px rgba(13,71,161,.2); margin-top: auto; }
.footer-stats, .footer-links, .footer-status { line-height: 1.6; }
.footer a { text-decoration: none; transition: color .3s; }
.footer a:hover { color: #2196f3 !important; }
.footer-actions .btn { border-radius: 20px; padding: 5px 15px; font-size: .8rem; transition: all .3s; }
.footer-actions .btn:hover { transform: translateY(-2px); }
.system-info p { margin-bottom: .5rem; padding: .3rem 0; border-bottom: 1px solid #eee; }
.system-info p:last-child { border-bottom: none; }
@media(max-width:768px){
    .footer .col-md-4 { margin-bottom: 1.5rem; text-align: center; }
    .footer .text-end { text-align: center !important; margin-top: 1rem; }
}
</style>

<script>
// ── Mise à jour du footer via le client MQTT global (window.siteMqtt) ──
// Le client est initialisé dans header.php — pas besoin d'en créer un second.

function updateFooterMqttStatus(online) {
    const el = document.getElementById('footerMqttText');
    if (!el) return;
    el.textContent = online ? 'Connecté' : 'Déconnecté';
    el.className   = online ? 'text-success' : 'text-warning';
}

function updateFooterEsp32Status(online) {
    const el = document.getElementById('footerEsp32Text');
    const mo = document.getElementById('modalEsp32Status');
    if (el) {
        el.textContent = online ? 'En ligne' : 'Hors-ligne';
        el.className   = online ? 'text-success' : 'text-warning';
    }
    if (mo) {
        mo.textContent = online ? 'En ligne' : 'Hors-ligne';
        mo.className   = 'badge ' + (online ? 'bg-success' : 'bg-danger');
    }
}

// Écouter les évènements MQTT diffusés par header.php
window.addEventListener('mqtt:message', (e) => {
    const { topic, data } = e.detail;

    // Statut broker (connexion WebSocket)
    if (topic === '<?= MQTT_TOPIC_STATUS ?>') {
        if (data.online !== undefined) updateFooterEsp32Status(data.online);
        if (data.pong)                 updateFooterEsp32Status(true);
    }
});

// Synchroniser le badge footer avec l'état de connexion du client global
document.addEventListener('DOMContentLoaded', () => {
    // Attendre que siteMqtt soit initialisé (header.php)
    const check = setInterval(() => {
        if (window.siteMqtt) {
            clearInterval(check);
            updateFooterMqttStatus(window.siteMqttConnected);

            window.siteMqtt.on('connect',    () => updateFooterMqttStatus(true));
            window.siteMqtt.on('disconnect', () => updateFooterMqttStatus(false));
            window.siteMqtt.on('reconnect',  () => updateFooterMqttStatus(false));
        }
    }, 200);
});

function refreshData() {
    const toast = document.createElement('div');
    toast.className = 'alert alert-info position-fixed top-0 end-0 m-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = '<i class="fas fa-sync fa-spin"></i> Actualisation…';
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); location.reload(); }, 1000);
}

function showSystemInfo() {
    new bootstrap.Modal(document.getElementById('systemInfoModal')).show();
}
</script>
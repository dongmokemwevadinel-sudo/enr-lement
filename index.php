<?php
// index.php - Communication MQTT avec ESP32
require_once __DIR__ . '/config.php';

// Toutes les constantes MQTT sont definies dans config.php - pas de redefinition ici.
// MQTT_TOPIC_CONFIRM est le seul topic absent de config.php
if (!defined('MQTT_TOPIC_CONFIRM')) {
    define('MQTT_TOPIC_CONFIRM', 'bioaccess/esp32/enroll/confirm');
}

// Les fonctions mqttPublish(), mqttIsOnline() et esp32SendCommand()
// sont definies dans config.php.

$esp32Online = mqttIsOnline(); // Le broker est-il joignable ?
$wifiStatus = $esp32Online ? 'connected' : 'disconnected';
$wifiStatusText = $esp32Online ? 'Broker MQTT Connecté' : 'Broker MQTT Hors-ligne';

// Statistiques
$total_employees = $db->querySingle("SELECT COUNT(*) FROM employees") ?: 0;
$total_points = $db->querySingle("SELECT COUNT(*) FROM pointages") ?: 0;
$total_points_today = $db->querySingle("SELECT COUNT(*) FROM pointages WHERE date(datetime) = date('now')") ?: 0;
$last_sync = $db->querySingle("SELECT datetime FROM pointages ORDER BY datetime DESC LIMIT 1");
$last_sync_formatted = $last_sync ? date('d/m/Y H:i', strtotime($last_sync)) : 'Jamais';

// Récupérer les employés
$employees_result = $db->query("SELECT id, nom, prenom, fingerprint_id, poste FROM employees ORDER BY nom, prenom");
$employees = [];
$employees_with_fp = [];
while ($row = $employees_result->fetchArray(SQLITE3_ASSOC)) {
    $employees[] = $row;
    if ($row['fingerprint_id']) {
        $employees_with_fp[] = $row;
    }
}

// Récupérer les IDs d'empreintes déjà utilisés
$used_ids = [];
$result = $db->query("SELECT fingerprint_id FROM employees WHERE fingerprint_id IS NOT NULL");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $used_ids[] = intval($row['fingerprint_id']);
}

// Récupérer les 5 derniers pointages
$recent_points = $db->query("
    SELECT p.*, e.nom, e.prenom 
    FROM pointages p 
    LEFT JOIN employees e ON p.employee_id = e.id 
    ORDER BY p.datetime DESC 
    LIMIT 5
");

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $error_message = "Erreur de sécurité CSRF";
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'connect_wifi':
                $res = mqttPublish(MQTT_TOPIC_CMD, 'PING');
                if ($res['success']) {
                    $success_message = "Commande PING publiée sur MQTT (topic: " . MQTT_TOPIC_CMD . ")";
                    logActivity("Ping MQTT publié", $_SESSION['user_id'] ?? null);
                } else {
                    $error_message = "Échec de publication MQTT : " . $res['error'];
                }
                break;

            case 'enroll_fingerprint':
                // ⚠️ Ce case n'est plus utilisé.
                // L'enrôlement est entièrement géré en asynchrone via fetch() → api_controller.php?action=enroll_start
                // Fallback si JS désactivé :
                $error_message = "JavaScript requis pour l'enrôlement biométrique.";
                break;

            case 'delete_fingerprint':
                if (isset($_POST['delete_id'])) {
                    $delete_id = intval($_POST['delete_id']);
                    
                    if ($delete_id >= 1 && $delete_id <= 127) {
                        // ÉTAPE 1: Vérifier que l'ID existe en base
                        $check_stmt = $db->prepare("SELECT id FROM employees WHERE fingerprint_id = ?");
                        $check_stmt->bindValue(1, $delete_id, SQLITE3_INTEGER);
                        $check_result = $check_stmt->execute();
                        $employee = $check_result->fetchArray(SQLITE3_ASSOC);
                        
                        if (!$employee) {
                            $error_message = "Aucun employé trouvé avec cet ID d'empreinte";
                            break;
                        }
                        
                        // ÉTAPE 2: Envoyer la commande de suppression via MQTT (texte brut "DEL N")
                        $del_res = esp32SendCommand("DEL $delete_id");
                        
                        if ($del_res['success']) {
                            // ÉTAPE 3: Si succès, supprimer en base
                            $stmt = $db->prepare("UPDATE employees SET fingerprint_id = NULL WHERE fingerprint_id = ?");
                            $stmt->bindValue(1, $delete_id, SQLITE3_INTEGER);
                            
                            if ($stmt->execute()) {
                                $success_message = "Empreinte supprimée avec succès";
                                logActivity("Suppression empreinte ID $delete_id", $_SESSION['user_id'] ?? null);
                                
                                // Mettre à jour la liste des IDs utilisés
                                $key = array_search($delete_id, $used_ids);
                                if ($key !== false) {
                                    unset($used_ids[$key]);
                                    $used_ids = array_values($used_ids);
                                }
                            } else {
                                $error_message = "Erreur lors de la suppression en base";
                            }
                        } else {
                            $error_message = "Échec de la suppression sur l'ESP32";
                        }
                    } else {
                        $error_message = "ID d'empreinte invalide";
                    }
                }
                break;
        }
    }
}

// Calculer le prochain ID disponible pour l'affichage
$next_id = 1;
while (in_array($next_id, $used_ids) && $next_id <= 127) {
    $next_id++;
}
$next_id_available = ($next_id <= 127) ? $next_id : null;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BioAccess Pro - Système de Gestion Biométrique</title>
    
    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/images/tagus-drone.webp"/>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #4cc9f0;
            --success: #06d6a0;
            --warning: #ffb703;
            --danger: #ef476f;
            --dark: #2b2d42;
            --light: #f8f9fa;
            --gray: #8d99ae;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated background */
        .circles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .circles li {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            animation: animate 25s linear infinite;
            bottom: -150px;
            border-radius: 50%;
        }
        
        .circles li:nth-child(1) {
            left: 25%;
            width: 80px;
            height: 80px;
            animation-delay: 0s;
        }
        
        .circles li:nth-child(2) {
            left: 10%;
            width: 20px;
            height: 20px;
            animation-delay: 2s;
            animation-duration: 12s;
        }
        
        .circles li:nth-child(3) {
            left: 70%;
            width: 20px;
            height: 20px;
            animation-delay: 4s;
        }
        
        .circles li:nth-child(4) {
            left: 40%;
            width: 60px;
            height: 60px;
            animation-delay: 0s;
            animation-duration: 18s;
        }
        
        .circles li:nth-child(5) {
            left: 65%;
            width: 20px;
            height: 20px;
            animation-delay: 0s;
        }
        
        .circles li:nth-child(6) {
            left: 75%;
            width: 110px;
            height: 110px;
            animation-delay: 3s;
        }
        
        .circles li:nth-child(7) {
            left: 35%;
            width: 150px;
            height: 150px;
            animation-delay: 7s;
        }
        
        .circles li:nth-child(8) {
            left: 50%;
            width: 25px;
            height: 25px;
            animation-delay: 15s;
            animation-duration: 45s;
        }
        
        .circles li:nth-child(9) {
            left: 20%;
            width: 15px;
            height: 15px;
            animation-delay: 2s;
            animation-duration: 35s;
        }
        
        .circles li:nth-child(10) {
            left: 85%;
            width: 150px;
            height: 150px;
            animation-delay: 0s;
            animation-duration: 11s;
        }
        
        @keyframes animate {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 50%;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }
        
        /* Main content */
        .main-wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Glassmorphism cards */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .glass-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
        }
        
        /* Navigation */
        .navbar-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-link {
            color: var(--dark) !important;
            font-weight: 500;
            padding: 0.7rem 1.2rem !important;
            border-radius: 30px;
            transition: all 0.3s ease;
            margin: 0 5px;
        }
        
        .nav-link:hover {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white !important;
            transform: translateY(-2px);
        }
        
        .nav-link i {
            margin-right: 8px;
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white !important;
        }
        
        /* Status badge */
        .status-badge {
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(255, 255, 255, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
            }
        }
        
        .status-badge.connected {
            background: linear-gradient(135deg, #06d6a0 0%, #059669 100%);
            color: white;
        }
        
        .status-badge.disconnected {
            background: linear-gradient(135deg, #ef476f 0%, #dc2626 100%);
            color: white;
        }
        
        /* Stats cards */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            opacity: 0.05;
            transform: rotate(45deg);
            transition: all 0.5s ease;
        }
        
        .stat-card:hover::before {
            transform: rotate(45deg) translate(10%, 10%);
        }
        
        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(67, 97, 238, 0.2);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-dark) 100%);
            color: white;
            position: relative;
            z-index: 1;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .stat-label {
            color: var(--gray);
            font-weight: 500;
            font-size: 15px;
            position: relative;
            z-index: 1;
        }
        
        /* Feature cards */
        .feature-card {
            background: white;
            border-radius: 30px;
            padding: 35px 30px;
            height: 100%;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(30%, 30%);
            transition: all 0.5s ease;
        }
        
        .feature-card:hover::after {
            transform: translate(20%, 20%) scale(1.5);
        }
        
        .feature-card:hover {
            transform: translateY(-15px) scale(1.02);
            box-shadow: 0 30px 60px rgba(67, 97, 238, 0.3);
        }
        
        .feature-icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }
        
        .feature-icon-wrapper i {
            font-size: 40px;
            color: white;
        }
        
        .feature-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        
        .feature-description {
            color: var(--gray);
            margin-bottom: 25px;
            line-height: 1.7;
            font-size: 15px;
            position: relative;
            z-index: 1;
        }
        
        /* Buttons */
        .btn-modern {
            padding: 12px 28px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
            z-index: -1;
        }
        
        .btn-modern:hover::before {
            left: 100%;
        }
        
        .btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .btn-primary-modern {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        
        .btn-success-modern {
            background: linear-gradient(135deg, #06d6a0 0%, #059669 100%);
            color: white;
        }
        
        .btn-warning-modern {
            background: linear-gradient(135deg, #ffb703 0%, #f57c00 100%);
            color: white;
        }
        
        .btn-danger-modern {
            background: linear-gradient(135deg, #ef476f 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-outline-modern {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline-modern:hover {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-color: transparent;
        }
        
        /* Log container */
        .log-container {
            background: var(--dark);
            border-radius: 20px;
            padding: 25px;
            max-height: 350px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.3);
        }
        
        .log-entry {
            padding: 10px 15px;
            margin-bottom: 8px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            color: #e5e7eb;
            border-left: 4px solid transparent;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .log-entry.info {
            border-left-color: var(--primary);
        }
        
        .log-entry.success {
            border-left-color: var(--success);
        }
        
        .log-entry.warning {
            border-left-color: var(--warning);
        }
        
        .log-entry.error {
            border-left-color: var(--danger);
        }
        
        .log-timestamp {
            color: var(--gray);
            margin-right: 15px;
            font-size: 12px;
        }
        
        /* Recent activities */
        .activity-item {
            padding: 15px;
            border-radius: 15px;
            background: rgba(67, 97, 238, 0.05);
            margin-bottom: 10px;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .activity-item:hover {
            background: rgba(67, 97, 238, 0.1);
            transform: translateX(5px);
        }
        
        .activity-item.entry {
            border-left-color: var(--success);
        }
        
        .activity-item.exit {
            border-left-color: var(--warning);
        }
        
        .activity-time {
            font-size: 13px;
            color: var(--gray);
        }
        
        .activity-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        /* Modal */
        .modal-content {
            border-radius: 30px;
            border: none;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 0;
            padding: 25px 30px;
            border: none;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
        }
        
        .form-control, .form-select {
            padding: 14px 18px;
            border-radius: 15px;
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
            font-size: 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
            transform: translateY(-2px);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Alert container */
        .alert-container {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 9999;
            min-width: 380px;
        }
        
        .alert-modern {
            border-radius: 15px;
            padding: 18px 22px;
            border: none;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            animation: slideInRight 0.4s ease;
            backdrop-filter: blur(10px);
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #06d6a0 0%, #059669 100%);
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ef476f 0%, #dc2626 100%);
            color: white;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #ffb703 0%, #f57c00 100%);
            color: white;
        }
        
        /* Footer */
        .footer-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px 0;
            margin-top: 50px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Progress bar */
        .progress-modern {
            height: 10px;
            border-radius: 10px;
            background: #e5e7eb;
            overflow: hidden;
        }
        
        .progress-bar-modern {
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
            position: relative;
            overflow: hidden;
        }
        
        .progress-bar-modern::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100%);
            }
        }
        
        /* Badges */
        .badge-modern {
            padding: 8px 15px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 13px;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .navbar-modern {
                padding: 1rem;
            }
            
            .stat-card {
                margin-bottom: 20px;
            }
            
            .feature-card {
                padding: 25px;
            }
            
            .alert-container {
                min-width: 90%;
                right: 5%;
            }
            
            .feature-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

<!-- Animated Background -->
<ul class="circles">
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
    <li></li>
</ul>

<div class="main-wrapper">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-modern fixed-top animate__animated animate__fadeInDown">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-fingerprint me-2"></i>
                BioAccess<span style="font-weight: 300;">Pro</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-house-door"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presence.php">
                            <i class="bi bi-clock-history"></i> Présences
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-graph-up"></i> Rapports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="gestion_empreintes.php">
                            <i class="bi bi-fingerprint"></i> Empreintes
                        </a>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Status ESP32 -->
                    <div class="status-badge <?php echo $wifiStatus; ?>" id="wifiStatus">
                        <i class="bi bi-wifi"></i>
                        <span><?php echo $wifiStatusText; ?></span>
                    </div>
                    
                    <!-- User menu -->
                    <div class="dropdown">
                        <button class="btn btn-outline-modern btn-modern dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-2"></i>
                            <?php echo $_SESSION['username'] ?? 'Admin'; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Paramètres</a></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Alert Container -->
    <div class="alert-container">
        <?php if (isset($success_message)): ?>
        <div class="alert alert-modern alert-success alert-dismissible fade show animate__animated animate__shakeX">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
            <strong>Succès !</strong> <?php echo $success_message; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-modern alert-danger alert-dismissible fade show animate__animated animate__shakeX">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <strong>Erreur !</strong> <?php echo $error_message; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($warning_message)): ?>
        <div class="alert alert-modern alert-warning alert-dismissible fade show animate__animated animate__shakeX">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <strong>Attention !</strong> <?php echo $warning_message; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content -->
    <div class="container" style="margin-top: 120px; margin-bottom: 50px;">
        <!-- Header -->
        <div class="text-center mb-5 animate__animated animate__fadeIn">
            <h1 class="display-3 fw-bold text-white mb-3" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.3);">
                Système de Gestion des Accès
            </h1>
            <p class="lead text-white-50 fs-4">
                <i class="bi bi-shield-check me-2"></i>
                Solution biométrique professionnelle nouvelle génération
            </p>
            <div class="d-flex justify-content-center gap-3 mt-4">
                <span class="badge-modern">
                    <i class="bi bi-people me-1"></i><?php echo $total_employees; ?> employés
                </span>
                <span class="badge-modern">
                    <i class="bi bi-fingerprint me-1"></i><?php echo count($employees_with_fp); ?> empreintes
                </span>
                <span class="badge-modern">
                    <i class="bi bi-clock me-1"></i>Dernier: <?php echo $last_sync_formatted; ?>
                </span>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-4 animate__animated animate__fadeInLeft">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_employees; ?></div>
                    <div class="stat-label">Employés enregistrés</div>
                    <small class="text-muted">+12% ce mois</small>
                </div>
            </div>
            <div class="col-md-4 animate__animated animate__fadeInUp">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-fingerprint"></i>
                    </div>
                    <div class="stat-value"><?php echo count($employees_with_fp); ?></div>
                    <div class="stat-label">Empreintes actives</div>
                    <small class="text-muted">Capacité: 127 max</small>
                </div>
            </div>
            <div class="col-md-4 animate__animated animate__fadeInRight">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-fill"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_points_today; ?></div>
                    <div class="stat-label">Pointages aujourd'hui</div>
                    <small class="text-muted">Dernier: <?php echo $last_sync_formatted; ?></small>
                </div>
            </div>
        </div>
        
        <!-- Feature Cards -->
        <div class="row g-4">
            <!-- WiFi Connection Card -->
            <div class="col-lg-4 animate__animated animate__fadeInLeft">
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-wifi"></i>
                    </div>
                    <h3 class="feature-title">Connexion ESP32</h3>
                    <p class="feature-description">
                        Communication MQTT temps réel avec le module biométrique via WiFi
                    </p>
                    
                    <div class="d-flex gap-2 mb-4">
                        <span class="badge-modern">
                            <i class="bi bi-hdd-network me-1"></i><?php echo MQTT_HOST; ?>
                        </span>
                        <span class="badge-modern">
                            <i class="bi bi-plug me-1"></i>MQTT :<?php echo MQTT_PORT; ?>
                        </span>
                        <span class="badge-modern">
                            <i class="bi bi-broadcast me-1"></i>WS :<?php echo MQTT_WS_PORT; ?>
                        </span>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="connect_wifi">
                            <button type="submit" class="btn btn-primary-modern btn-modern w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>
                                Tester la connexion
                            </button>
                        </form>
                        
                        <button class="btn btn-outline-modern btn-modern" onclick="pingESP32()">
                            <i class="bi bi-send me-2"></i>
                            Ping ESP32
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Fingerprint Management Card -->
            <div class="col-lg-4 animate__animated animate__fadeInUp">
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-fingerprint"></i>
                    </div>
                    <h3 class="feature-title">Gestion des Empreintes</h3>
                    <p class="feature-description">
                        Enrôlement et suppression des empreintes digitales des employés
                    </p>
                    
                    <div class="progress-modern mb-4">
                        <div class="progress-bar-modern" role="progressbar" 
                             style="width: <?php echo (count($employees_with_fp) / 127) * 100; ?>%"
                             aria-valuenow="<?php echo count($employees_with_fp); ?>" 
                             aria-valuemin="0" aria-valuemax="127">
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-success-modern btn-modern" data-bs-toggle="modal" data-bs-target="#enrollModal" <?php echo !$next_id_available ? 'disabled' : ''; ?>>
                            <i class="bi bi-plus-circle me-2"></i>
                            Nouvelle empreinte
                            <?php if ($next_id_available): ?>
                            <span class="badge bg-light text-dark ms-2">ID <?php echo $next_id_available; ?></span>
                            <?php else: ?>
                            <span class="badge bg-danger ms-2">Complet</span>
                            <?php endif; ?>
                        </button>
                        
                        <div class="d-flex gap-2">
                            <a href="gestion_empreintes.php" class="btn btn-outline-modern btn-modern flex-grow-1">
                                <i class="bi bi-list-ul me-2"></i>
                                Liste
                            </a>
                            
                            <button type="button" class="btn btn-danger-modern btn-modern flex-grow-1" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="bi bi-trash me-2"></i>
                                Supprimer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Analytics Card -->
            <div class="col-lg-4 animate__animated animate__fadeInRight">
                <div class="feature-card">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-graph-up"></i>
                    </div>
                    <h3 class="feature-title">Analytics & Rapports</h3>
                    <p class="feature-description">
                        Analyse approfondie des données de présence et rapports hebdomadaires
                    </p>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Présence aujourd'hui</span>
                            <span class="fw-bold text-primary">
                                <?php 
                                $present_today = $db->querySingle("SELECT COUNT(DISTINCT employee_id) FROM pointages WHERE date(datetime) = date('now')");
                                echo $present_today . '/' . $total_employees;
                                ?>
                            </span>
                        </div>
                        <div class="progress-modern">
                            <?php $presence_percent = $total_employees > 0 ? ($present_today / $total_employees) * 100 : 0; ?>
                            <div class="progress-bar-modern" style="width: <?php echo $presence_percent; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="reports.php" class="btn btn-warning-modern btn-modern">
                            <i class="bi bi-file-pdf me-2"></i>
                            Rapport hebdomadaire
                        </a>
                        
                        <a href="presence.php" class="btn btn-outline-modern btn-modern">
                            <i class="bi bi-info-circle me-2"></i>
                            Statut détaillé
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Activity Log and Recent Activities -->
        <div class="row g-4 mt-4">
            <!-- Activity Log -->
            <div class="col-lg-8 animate__animated animate__fadeInLeft">
                <div class="glass-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">
                            <i class="bi bi-terminal me-2" style="color: var(--primary);"></i>
                            Journal d'activité
                        </h4>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-modern btn-modern btn-sm" onclick="fetchESP32Status()">
                                <i class="bi bi-arrow-repeat me-1"></i>
                                Rafraîchir
                            </button>
                            <button class="btn btn-outline-secondary btn-modern btn-sm" onclick="clearLogs()">
                                <i class="bi bi-eraser me-1"></i>
                                Effacer
                            </button>
                        </div>
                    </div>
                    
                    <div class="log-container" id="logContainer">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-wifi-off fs-1"></i>
                            <p class="mt-2">En attente de connexion...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activities -->
            <div class="col-lg-4 animate__animated animate__fadeInRight">
                <div class="glass-card p-4">
                    <h4 class="fw-bold mb-4">
                        <i class="bi bi-clock-history me-2" style="color: var(--primary);"></i>
                        Derniers pointages
                    </h4>
                    
                    <div class="recent-activities">
                        <?php if ($recent_points && $recent_points->numColumns() > 0): ?>
                            <?php while ($point = $recent_points->fetchArray(SQLITE3_ASSOC)): ?>
                            <?php $isEntry = ($point['type_pointage'] === 'ENTREE'); ?>
                            <div class="activity-item <?php echo $isEntry ? 'entry' : 'exit'; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="activity-name">
                                            <?php echo htmlspecialchars(($point['prenom'] ?? '') . ' ' . ($point['nom'] ?? 'Inconnu')); ?>
                                        </div>
                                        <div class="activity-time">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('H:i:s', strtotime($point['datetime'])); ?>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo $isEntry ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                        <?php echo $isEntry ? 'Entrée' : 'Sortie'; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">Aucun pointage récent</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="presence.php" class="btn btn-outline-modern btn-modern btn-sm">
                            Voir tous les pointages
                            <i class="bi bi-arrow-right ms-2"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer-modern">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">
                        <i class="bi bi-c-circle me-1"></i>
                        <?php echo date('Y'); ?> BioAccess Pro. Version 2.0
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">
                        <span class="text-muted me-3">
                            <i class="bi bi-shield-check me-1"></i>
                            Système sécurisé
                        </span>
                        <span class="text-muted">
                            <i class="bi bi-battery-charging me-1"></i>
                            Online
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </footer>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL ENRÔLEMENT ASYNCHRONE
     Flux :
       Étape 0 – Formulaire (admin saisit les infos)
       Étape 1 – Envoi au serveur (enroll_start) → employé créé + enroll_queue
       Étape 2 – ESP32 reçoit commande au prochain poll (max 3 s), passe en mode ENROLL
       Étape 3 – L'admin pose le doigt (scan 1)
       Étape 4 – L'admin retire et repose le doigt (scan 2)
       Étape 5 – ESP32 confirme via enroll_confirm → status 'done'
       Étape 6 – Succès affiché, capteur repasse en mode VERIFY automatiquement
═════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="enrollModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-fingerprint me-2"></i>
                    Enrôlement d'une nouvelle empreinte
                </h5>
                <button type="button" class="btn-close btn-close-white" id="enrollCloseBtn" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- ── STEP 0 : Formulaire ──────────────────────────────── -->
                <div id="enrollStep0">
                    <div class="text-center mb-4">
                        <div class="feature-icon-wrapper mx-auto" style="width: 90px; height: 90px;">
                            <i class="bi bi-fingerprint" style="font-size: 44px;"></i>
                        </div>
                        <p class="text-muted mt-2">Remplissez les informations de l'employé, puis cliquez sur <strong>Démarrer</strong>.</p>
                    </div>

                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ID automatique :</strong> Le système attribuera le prochain ID disponible
                        (<?php echo $next_id_available ?: 'Aucun disponible'; ?>).
                    </div>

                    <div class="row g-3" id="enrollForm">
                        <div class="col-md-6">
                            <label class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="e_nom" placeholder="Dupont" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prénom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="e_prenom" placeholder="Jean" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Poste</label>
                            <input type="text" class="form-control" id="e_poste" placeholder="Technicien">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="e_email" placeholder="jean@exemple.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" id="e_telephone" placeholder="+237 6XX XXX XXX">
                        </div>
                    </div>

                    <div class="alert alert-warning mt-4 mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Procédure :</strong> Après avoir cliqué sur <em>Démarrer</em>, posez le doigt
                        sur le capteur <strong>deux fois</strong> lorsque l'ESP32 vous le demandera.
                    </div>
                </div>

                <!-- ── STEP 1 : Attente ESP32 ───────────────────────────── -->
                <div id="enrollStep1" style="display:none;" class="text-center py-4">
                    <div class="spinner-border text-primary mb-3" style="width:3.5rem;height:3.5rem;" role="status"></div>
                    <h5 class="mb-1">Commande envoyée au serveur…</h5>
                    <p class="text-muted" id="enrollStep1Msg">L'ESP32 va passer en mode enregistrement dans quelques secondes.</p>
                    <small class="text-muted">Le capteur se prépare (polling max 3 s)</small>
                </div>

                <!-- ── STEP 2 : Scan doigt ──────────────────────────────── -->
                <div id="enrollStep2" style="display:none;" class="text-center py-4">
                    <div class="mb-3" style="font-size:4rem;" id="enrollFpIcon">🖐️</div>
                    <h5 class="mb-1" id="enrollScanTitle">Posez votre doigt sur le capteur</h5>
                    <p class="text-muted" id="enrollScanMsg">Scan 1 / 2 — maintenez le doigt appuyé fermement</p>
                    <div class="progress-modern mx-auto mt-3" style="max-width:300px;">
                        <div class="progress-bar-modern" id="enrollProgressBar" style="width:0%;transition:width .5s;"></div>
                    </div>
                    <small class="text-muted mt-2 d-block" id="enrollCountdown"></small>
                </div>

                <!-- ── STEP 3 : Succès ─────────────────────────────────── -->
                <div id="enrollStep3" style="display:none;" class="text-center py-4">
                    <div style="font-size:4rem;" class="mb-3">✅</div>
                    <h4 class="text-success mb-2">Enrôlement réussi !</h4>
                    <p class="text-muted" id="enrollSuccessMsg">L'empreinte a été enregistrée sur l'ESP32.</p>
                    <p class="text-muted small">Le capteur est repassé automatiquement en mode <strong>vérification</strong>.</p>
                </div>

                <!-- ── STEP Erreur ─────────────────────────────────────── -->
                <div id="enrollStepError" style="display:none;" class="text-center py-4">
                    <div style="font-size:4rem;" class="mb-3">❌</div>
                    <h4 class="text-danger mb-2">Enrôlement échoué</h4>
                    <p class="text-muted" id="enrollErrorMsg">Une erreur est survenue. Réessayez.</p>
                </div>

            </div><!-- /modal-body -->
            <div class="modal-footer" id="enrollFooter">
                <button type="button" class="btn btn-outline-modern btn-modern" data-bs-dismiss="modal" id="enrollCancelBtn">
                    <i class="bi bi-x-circle me-2"></i>Annuler
                </button>
                <button type="button" class="btn btn-primary-modern btn-modern" id="enrollStartBtn"
                        <?php echo !$next_id_available ? 'disabled' : ''; ?>>
                    <i class="bi bi-play-circle me-2"></i>Démarrer l'enrôlement
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger">
                <h5 class="modal-title text-white">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Supprimer une empreinte
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="delete_fingerprint">
                    
                    <div class="text-center mb-4">
                        <i class="bi bi-trash3" style="font-size: 50px; color: var(--danger);"></i>
                        <p class="mt-3">Êtes-vous sûr de vouloir supprimer cette empreinte ?</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Sélectionnez l'employé</label>
                        <select class="form-select" name="delete_id" required>
                            <option value="">Choisir un employé...</option>
                            <?php foreach ($employees_with_fp as $emp): ?>
                            <option value="<?php echo $emp['fingerprint_id']; ?>">
                                ID <?php echo $emp['fingerprint_id']; ?> - 
                                <?php echo htmlspecialchars($emp['prenom'] . ' ' . $emp['nom']); ?>
                                <?php if (!empty($emp['poste'])): ?>(<?php echo $emp['poste']; ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Attention !</strong> L'empreinte sera d'abord supprimée de l'ESP32, puis de la base de données.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-modern btn-modern" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Annuler
                    </button>
                    <button type="submit" class="btn btn-danger-modern btn-modern">
                        <i class="bi bi-trash me-2"></i>Confirmer la suppression
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- MQTT.js — communication temps réel via WebSocket avec le broker Mosquitto -->
<script src="https://unpkg.com/mqtt@5.3.4/dist/mqtt.min.js"></script>
<script>
// ═══════════════════════════════════════════════════════════════════════════
//  CONFIGURATION MQTT WebSocket (correspond aux constantes PHP)
// ═══════════════════════════════════════════════════════════════════════════

// Dans la fonction mqttInit() du JavaScript :
const MQTT_TOPIC_CMD     = 'esp32/command';
const MQTT_TOPIC_STATUS  = 'esp32/status';
const MQTT_TOPIC_ENROLL  = 'esp32/enroll/command';
const MQTT_TOPIC_CONFIRM = 'esp32/enroll/confirm';
const MQTT_TOPIC_EVENTS  = 'bioaccess/events';
let mqttClient    = null;
let mqttConnected = false;
// Map<correlationId, {resolve, reject, timer}> pour les réponses attendues
const pendingResponses = new Map();

/**
 * Initialise le client MQTT WebSocket et branche les handlers.
 * Appelé au DOMContentLoaded.
 */
function mqttInit() {
    mqttClient = mqtt.connect(MQTT_WS_URL, {
        clientId: 'bioaccess_web_' + Math.random().toString(36).slice(2, 8),
        clean: true,
        reconnectPeriod: 3000,
    });
    mqttClient.subscribe([
        MQTT_TOPIC_STATUS, 
        MQTT_TOPIC_CONFIRM,  // Ajout important !
        MQTT_TOPIC_EVENTS + '/#'
    ]);

    mqttClient.on('connect', () => {
        mqttConnected = true;
        addLog('📡 MQTT WebSocket connecté au broker', 'success');
        updateWifiStatus(true);
        mqttClient.subscribe([MQTT_TOPIC_STATUS, MQTT_TOPIC_EVENTS + '/#']);
    });

    mqttClient.on('reconnect', () => addLog('🔄 Reconnexion MQTT…', 'warning'));
    mqttClient.on('disconnect', () => { mqttConnected = false; updateWifiStatus(false); });
    mqttClient.on('error', err => addLog('❌ Erreur MQTT : ' + err.message, 'error'));

    mqttClient.on('message', (topic, raw) => {
        let data = {};
        try { data = JSON.parse(raw.toString()); } catch { data = { raw: raw.toString() }; }

        if (topic === MQTT_TOPIC_STATUS)          handleEsp32Status(data);
        else if (topic === MQTT_TOPIC_CONFIRM)    handleEnrollEvent(data);  // ICI !
        else if (topic.startsWith(MQTT_TOPIC_EVENTS)) handleBioEvent(topic, data);
        
        // Résoudre un pending correlationId...
        if (data.correlation_id && pendingResponses.has(data.correlation_id)) {
            const { resolve, timer } = pendingResponses.get(data.correlation_id);
            clearTimeout(timer);
            pendingResponses.delete(data.correlation_id);
            resolve(data);
        }
    });
}

/**
 * Publie une commande MQTT (QoS 1).
 * Si timeout > 0, retourne une Promise qui se résout quand l'ESP32 répond
 * avec le même correlation_id.
 */
function mqttPublish(topic, payload, timeout = 0) {
    if (!mqttConnected) {
        addLog('⚠️ MQTT non connecté — impossible de publier', 'error');
        return Promise.reject(new Error('MQTT not connected'));
    }
    const correlationId = timeout > 0 ? Math.random().toString(36).slice(2) : undefined;
    const msg = JSON.stringify(correlationId ? { ...payload, correlation_id: correlationId } : payload);
    mqttClient.publish(topic, msg, { qos: 1 });
    if (!correlationId) return Promise.resolve({ success: true });

    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            pendingResponses.delete(correlationId);
            reject(new Error('Timeout en attente de réponse MQTT'));
        }, timeout);
        pendingResponses.set(correlationId, { resolve, reject, timer });
    });
}

// Gestionnaire : statut ESP32 (bioaccess/esp32/status)
function handleEsp32Status(data) {
    if (data.pong)              addLog('✅ PONG reçu de l'ESP32', 'success');
    if (data.online !== undefined) updateWifiStatus(data.online);
}

// Gestionnaire : évènements biométriques (bioaccess/events/*)
function handleBioEvent(topic, data) {
    if (topic.endsWith('/enroll'))   handleEnrollEvent(data);   // voir section enrôlement
    else if (topic.endsWith('/pointage'))
        addLog(`🖐️ Pointage : ${data.prenom || ''} ${data.nom || ''} — ${data.datetime || ''}`, 'success');
}

// ═══════════════════════════════════════════════════════════════════════════
//  JOURNAL D'ACTIVITÉ
// ═══════════════════════════════════════════════════════════════════════════
const logContainer   = document.getElementById('logContainer');
const wifiStatusEl   = document.getElementById('wifiStatus');

function addLog(message, type = 'info') {
    const ts  = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `log-entry ${type}`;
    div.innerHTML = `<span class="log-timestamp">[${ts}]</span> <span class="log-message">${message}</span>`;
    logContainer.appendChild(div);
    logContainer.scrollTop = logContainer.scrollHeight;
}

function clearLogs() {
    logContainer.innerHTML = '';
    addLog('Journal effacé', 'info');
}

// ═══════════════════════════════════════════════════════════════════════════
//  STATUT ESP32
// ═══════════════════════════════════════════════════════════════════════════
function updateWifiStatus(online) {
    if (online) {
        wifiStatusEl.className = 'status-badge connected';
        wifiStatusEl.innerHTML = '<i class="bi bi-wifi"></i><span>MQTT Connecté</span>';
    } else {
        wifiStatusEl.className = 'status-badge disconnected';
        wifiStatusEl.innerHTML = '<i class="bi bi-wifi-off"></i><span>MQTT Hors-ligne</span>';
    }
}

/** Publie un PING via MQTT et attend le PONG de l'ESP32 (5 s max). */
async function pingESP32() {
    addLog('📡 Envoi PING via MQTT…', 'info');
    try {
        // On souscrit temporairement au topic status pour recevoir le PONG
        await mqttPublish(MQTT_TOPIC_CMD, { cmd: 'PING' }, 5000);
        // Si on arrive ici sans timeout c'est que l'ESP32 a répondu
        addLog('✅ PONG reçu — ESP32 en ligne', 'success');
        updateWifiStatus(true);
    } catch (e) {
        addLog('❌ Pas de réponse MQTT : ' + e.message, 'error');
        updateWifiStatus(false);
    }
}

/** Publie une demande de statut via MQTT. */
async function fetchESP32Status() {
    addLog('📊 Demande de statut ESP32 via MQTT…', 'info');
    try {
        await mqttPublish(MQTT_TOPIC_CMD, { cmd: 'STATUS' });
        addLog('📤 Commande STATUS publiée (réponse en temps réel)', 'success');
    } catch (e) {
        addLog('❌ ' + e.message, 'error');
    }
}

// Aucun polling HTTP — le statut arrive en push via MQTT (handleEsp32Status)

// ═══════════════════════════════════════════════════════════════════════════
//  ENRÔLEMENT ASYNCHRONE — FLUX MQTT
//  1. Admin remplit le formulaire → clique "Démarrer"
//  2. fetch() POST → api_controller.php?action=enroll_start
//     → Serveur insère employé + enroll_queue (status=pending)
//     → Publie MQTT bioaccess/esp32/enroll { cmd:'ENROLL', queue_id, fingerprint_id }
//  3. ESP32 reçoit le message MQTT → passe en mode ENROLL sur le capteur
//  4. Admin pose le doigt (scan 1) → retire → repose (scan 2)
//  5. ESP32 publie bioaccess/events/enroll { queue_id, status:'done'|'failed' }
//     → Le front-end reçoit en push et met à jour l'UI instantanément
//  6. Le serveur met à jour la base via api_controller.php?action=enroll_confirm
// ═══════════════════════════════════════════════════════════════════════════

const CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';

// Références UI
const enrollModal    = document.getElementById('enrollModal');
const bsEnrollModal  = new bootstrap.Modal(enrollModal);

const steps = {
    form:    document.getElementById('enrollStep0'),
    waiting: document.getElementById('enrollStep1'),
    scan:    document.getElementById('enrollStep2'),
    success: document.getElementById('enrollStep3'),
    error:   document.getElementById('enrollStepError'),
};
const footer      = document.getElementById('enrollFooter');
const startBtn    = document.getElementById('enrollStartBtn');
const cancelBtn   = document.getElementById('enrollCancelBtn');
const closeBtn    = document.getElementById('enrollCloseBtn');

// Variables d'état enrôlement (plus de polling, on écoute MQTT)
let enrollQueueId     = null;
let enrollStartTime   = null;
let enrollUiTimer     = null;   // setInterval pour la barre de progression UI

function showStep(name) {
    Object.values(steps).forEach(el => el.style.display = 'none');
    steps[name].style.display = '';
}

function setFooter(mode) {
    // mode: 'form' | 'waiting' | 'done'
    if (mode === 'form') {
        footer.innerHTML = `
            <button type="button" class="btn btn-outline-modern btn-modern" data-bs-dismiss="modal" id="enrollCancelBtn">
                <i class="bi bi-x-circle me-2"></i>Annuler
            </button>
            <button type="button" class="btn btn-primary-modern btn-modern" id="enrollStartBtn"
                    <?php echo !$next_id_available ? 'disabled' : ''; ?>>
                <i class="bi bi-play-circle me-2"></i>Démarrer l'enrôlement
            </button>`;
        document.getElementById('enrollStartBtn').addEventListener('click', startEnroll);
    } else if (mode === 'waiting') {
        footer.innerHTML = `
            <button type="button" class="btn btn-danger-modern btn-modern" id="enrollAbortBtn">
                <i class="bi bi-stop-circle me-2"></i>Annuler l'enrôlement
            </button>`;
        document.getElementById('enrollAbortBtn').addEventListener('click', abortEnroll);
    } else {
        footer.innerHTML = `
            <button type="button" class="btn btn-outline-modern btn-modern" data-bs-dismiss="modal">
                <i class="bi bi-x-circle me-2"></i>Fermer
            </button>`;
    }
}

// Réinitialiser le modal quand il est ouvert
enrollModal.addEventListener('show.bs.modal', () => {
    showStep('form');
    setFooter('form');
    document.getElementById('enrollProgressBar').style.width = '0%';
    document.getElementById('enrollCountdown').textContent = '';
});

// Nettoyer le polling si on ferme le modal
enrollModal.addEventListener('hide.bs.modal', () => {
    stopPolling();
});

async function startEnroll() {
    const nom       = document.getElementById('e_nom').value.trim();
    const prenom    = document.getElementById('e_prenom').value.trim();
    const poste     = document.getElementById('e_poste').value.trim();
    const email     = document.getElementById('e_email').value.trim();
    const telephone = document.getElementById('e_telephone').value.trim();

    if (!nom || !prenom) {
        alert('Le nom et le prénom sont obligatoires.');
        return;
    }

    // ── Étape 1 : afficher spinner ────────────────────────────────────────
    showStep('waiting');
    setFooter('waiting');
    addLog(`🔄 Enrôlement de ${prenom} ${nom} en cours…`, 'info');

    try {
        // ── Étape 2 : appel API HTTP pour créer l'employé ─────────────────
        // (L'API publie ensuite la commande MQTT vers l'ESP32)
        const resp = await fetch('api_controller.php?action=enroll_start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, nom, prenom, poste, email, telephone })
        });

        const data = await resp.json();

        if (!data.success) {
            showEnrollError(data.message || 'Erreur lors de l'enrôlement');
            return;
        }

        enrollQueueId   = data.queue_id;
        enrollStartTime = Date.now();
        addLog(`✅ Employé créé (queue #${enrollQueueId}). Commande MQTT envoyée à l'ESP32…`, 'success');
        document.getElementById('enrollStep1Msg').textContent =
            `Employé créé. L'ESP32 reçoit la commande MQTT et passera en mode enregistrement (ID ${data.fingerprint_id || 'auto'}).`;

        // ── Étape 3 : attendre la réponse push MQTT (handleEnrollEvent) ───
        // Timeout de sécurité : 3 minutes
        enrollUiTimer = setTimeout(() => {
            stopEnrollUi();
            showEnrollError('Délai d'attente dépassé (3 min). Recommencez l'opération.');
        }, 3 * 60 * 1000);

    } catch (e) {
        showEnrollError('Erreur réseau : ' + e.message);
    }
}

/**
 * Appelé par handleBioEvent() quand un message arrive sur bioaccess/events/enroll.
 * L'ESP32 publie des mises à jour de statut en temps réel via MQTT.
 */
function handleEnrollEvent(data) {
    // Ignorer les évènements d'un autre enrôlement
    if (enrollQueueId && data.queue_id && data.queue_id != enrollQueueId) return;

    const elapsed  = enrollStartTime ? (Date.now() - enrollStartTime) / 1000 : 0;
    const progress = Math.min((elapsed / 30) * 100, 95);

    const status = data.status;

    if (status === 'processing') {
        showStep('scan');
        document.getElementById('enrollProgressBar').style.width = progress + '%';
        document.getElementById('enrollCountdown').textContent = `Temps écoulé : ${Math.round(elapsed)} s`;

        if (elapsed < 8) {
            document.getElementById('enrollScanTitle').textContent = '🖐️  Posez votre doigt sur le capteur';
            document.getElementById('enrollScanMsg').textContent   = 'Scan 1/2 — maintenez le doigt appuyé fermement';
            document.getElementById('enrollFpIcon').textContent    = '🖐️';
        } else if (elapsed < 16) {
            document.getElementById('enrollScanTitle').textContent = '✋  Retirez le doigt';
            document.getElementById('enrollScanMsg').textContent   = 'Puis reposez-le pour le scan 2/2';
            document.getElementById('enrollFpIcon').textContent    = '✋';
        } else {
            document.getElementById('enrollScanTitle').textContent = '🖐️  Posez à nouveau le doigt';
            document.getElementById('enrollScanMsg').textContent   = 'Scan 2/2 — finalisez l'empreinte';
            document.getElementById('enrollFpIcon').textContent    = '🖐️';
        }
        addLog('🖐️ ESP32 en mode enregistrement — posez le doigt', 'info');

    } else if (status === 'done') {
        stopEnrollUi();
        document.getElementById('enrollProgressBar').style.width = '100%';
        document.getElementById('enrollSuccessMsg').textContent  =
            'L'empreinte a été enregistrée sur l'ESP32 et associée à l'employé.';
        showStep('success');
        setFooter('done');
        addLog('🎉 Enrôlement terminé avec succès (MQTT) !', 'success');
        setTimeout(() => location.reload(), 3000);

    } else if (status === 'failed') {
        stopEnrollUi();
        showEnrollError('L'ESP32 a signalé un échec. Réessayez en plaçant correctement le doigt.');
        addLog('❌ Enrôlement échoué (ESP32 → MQTT)', 'error');
    }
}

function stopEnrollUi() {
    if (enrollUiTimer) { clearTimeout(enrollUiTimer); enrollUiTimer = null; }
    enrollQueueId   = null;
    enrollStartTime = null;
}

// Alias gardé pour compatibilité (ne fait plus rien d'utile)
function startPolling(queueId) { /* remplacé par MQTT push */ }
function stopPolling()         { stopEnrollUi(); }

function showEnrollError(msg) {
    stopPolling();
    document.getElementById('enrollErrorMsg').textContent = msg;
    showStep('error');
    setFooter('done');
    addLog('❌ ' + msg, 'error');
}

async function abortEnroll() {
    if (!confirm('Annuler l'enrôlement en cours ? L'employé créé sera conservé sans empreinte.')) return;
    stopPolling();
    showStep('form');
    setFooter('form');
    addLog('⚠️ Enrôlement annulé par l'utilisateur', 'warning');
}

// ═══════════════════════════════════════════════════════════════════════════
//  INITIALISATION
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    logContainer.innerHTML = '';
    addLog('🚀 BioAccess Pro initialisé', 'success');
    addLog('📡 Serveur : <?php echo $_SERVER["HTTP_HOST"] ?? "localhost"; ?>', 'info');

    // ── Connexion MQTT WebSocket ──────────────────────────────────────────
    addLog('🔌 Connexion au broker MQTT (<?php echo MQTT_HOST; ?>:<?php echo MQTT_WS_PORT; ?>)…', 'info');
    mqttInit();   // connexion temps réel — remplace le polling HTTP

    <?php if (!$esp32Online): ?>
    addLog('⚠️ Broker MQTT non joignable côté serveur — vérifiez Mosquitto', 'warning');
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
    addLog('✅ <?php echo addslashes($success_message); ?>', 'success');
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
    addLog('❌ <?php echo addslashes($error_message); ?>', 'error');
    <?php endif; ?>
    <?php if (isset($warning_message)): ?>
    addLog('⚠️ <?php echo addslashes($warning_message); ?>', 'warning');
    <?php endif; ?>

    // Animations IntersectionObserver pour les cartes
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate__animated', 'animate__fadeInUp');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('.stat-card, .feature-card').forEach(c => observer.observe(c));
});
</script>
</body>
</html>
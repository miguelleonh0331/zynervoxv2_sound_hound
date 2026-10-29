<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/AgentSession.php';
require_once __DIR__ . '/../../includes/AgentActions.php';
require_once __DIR__ . '/../../config/Config.php';

use Includes\Auth;
use Includes\Database;
use Includes\AgentSession;
use Includes\AgentActions;
use Config\Config;

Auth::checkAccess(1);

$db = Database::getInstance();
$server_ip = Config::get('VARserver_ip');
$agentSession = new AgentSession($db, $server_ip);
$agentActions = new AgentActions($db, $server_ip);

$user = $_SESSION['user'];
$campaigns = $agentSession->getAvailableCampaigns($user);
$dispositions = [];
if (isset($_SESSION['current_campaign'])) {
    $dispositions = $agentActions->getDispositions($_SESSION['current_campaign']);
}
$error = '';
$session_conf = $_SESSION['conf_exten'] ?? null;

// Manejo de Inicio de Sesión
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campaign_id'])) {
    try {
        $extension = $_SESSION['user']; // Usamos el ID de usuario como extensión según nuestra lógica previa
        
        // Obtener el password del teléfono
        $phone_stmt = $db->prepare("SELECT pass FROM phones WHERE extension = ?");
        $phone_stmt->execute([$extension]);
        $phone_pass = $phone_stmt->fetchColumn();
        $_SESSION['phone_pass'] = $phone_pass;

        $session_conf = $agentSession->startSession($user, $_POST['campaign_id'], $extension);
        $_SESSION['conf_exten'] = $session_conf;
        $_SESSION['current_campaign'] = $_POST['campaign_id'];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Manejo de Cierre de Sesión
if (isset($_GET['action']) && $_GET['action'] === 'logout_session') {
    $agentSession->stopSession($user);
    unset($_SESSION['conf_exten']);
    unset($_SESSION['current_campaign']);
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>VOX SPHERE | Agente</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #6366f1; 
            --primary-hover: #4f46e5;
            --bg: #030712; 
            --card-bg: rgba(31, 41, 55, 0.4);
            --glass-border: rgba(255, 255, 255, 0.1);
        }
        body { 
            font-family: 'Outfit', sans-serif; 
            background: radial-gradient(circle at top right, #1e1b4b, var(--bg)); 
            color: #f3f4f6; 
            margin: 0; 
            min-height: 100vh;
        }
        .container { padding: 2rem; max-width: 1000px; margin: 0 auto; }
        
        /* Glassmorphism Classes */
        .glass {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
        }

        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .user-info h1 { margin: 0; font-size: 1.5rem; font-weight: 600; letter-spacing: -0.025em; }
        .user-info p { margin: 0; color: #94a3b8; font-size: 0.875rem; }

        .session-card { padding: 3rem; text-align: center; max-width: 500px; margin: 4rem auto; }
        .select-campaign {
            background: rgba(0,0,0,0.2);
            border: 1px solid var(--glass-border);
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            width: 100%;
            margin: 1.5rem 0;
            font-size: 1rem;
        }

        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            font-size: 1rem;
        }
        .btn:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(34, 197, 94, 0.1);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .alert {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .active-session {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .info-box { padding: 1.5rem; }
        .info-box h3 { margin-top: 0; color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .info-box p { margin: 0.5rem 0 0 0; font-size: 1.25rem; font-weight: 600; }

        .btn-stop { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.2); margin-top: 1rem; }
        .btn-stop:hover { background: #ef4444; color: white; }

        /* CIRCULAR CONTROLS */
        .controls-row { display: flex; justify-content: center; gap: 1.5rem; margin-top: 2rem; }
        .btn-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: white;
            font-size: 1.5rem;
        }
        .btn-circle.hangup { background: #ef4444; box-shadow: 0 0 15px rgba(239, 68, 68, 0.4); }
        .btn-circle.pause { background: #f59e0b; box-shadow: 0 0 15px rgba(245, 158, 11, 0.4); }
        .btn-circle.dial { background: #10b981; box-shadow: 0 0 15px rgba(16, 185, 129, 0.4); }
        .btn-circle:hover { transform: scale(1.1); }
        .btn-circle:disabled { opacity: 0.3; cursor: not-allowed; transform: none !important; }

        /* LEAD PANEL */
        .lead-panel {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            text-align: left;
            margin-top: 1rem;
        }
        .lead-field {
            background: rgba(0,0,0,0.2);
            padding: 0.75rem;
            border-radius: 12px;
            border: 1px solid var(--glass-border);
        }
        .lead-field label { display: block; font-size: 0.65rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .lead-field span { font-size: 0.95rem; font-weight: 600; }

        .dispo-select {
            width: 100%;
            background: rgba(0,0,0,0.4);
            border: 1px solid var(--glass-border);
            color: white;
            padding: 10px;
            border-radius: 8px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header glass">
            <div class="user-info">
                 <p>SISTEMA VOX SPHERE</p>
                 <h1><?php echo $_SESSION['full_name']; ?></h1>
            </div>
            <div style="text-align: right;">
                <span class="status-badge">
                    <span style="width: 8px; height: 8px; background: #4ade80; border-radius: 50%; margin-right: 8px;"></span>
                    En Línea
                </span>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.75rem; color: #64748b;">IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
            </div>
        </header>

        <?php if (!$session_conf): ?>
            <div class="session-card glass">
                <h2 style="margin: 0;">Iniciar Operación</h2>
                <p style="color: #94a3b8;">Selecciona la campaña para comenzar a recibir llamadas.</p>
                
                <?php if ($error): ?>
                    <div class="alert"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <select name="campaign_id" class="select-campaign" required>
                        <option value="">Seleccionar Campaña...</option>
                        <?php foreach ($campaigns as $camp): ?>
                            <option value="<?php echo $camp['campaign_id']; ?>">
                                <?php echo $camp['campaign_id'] . " - " . $camp['campaign_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Entrar a Cabina</button>
                    <a href="../../logout.php" class="btn" style="background: rgba(255,255,255,0.05); color: #94a3b8; margin-top: 1rem; text-decoration: none; display: inline-block; width: auto; padding: 0.75rem 2rem;">Salir del Sistema</a>
                </form>
            </div>
        <?php else: ?>
            <div class="active-session">
                <div class="glass info-box">
                    <h3>Sesión Activa</h3>
                    <p style="color: #4ade80;">CONECTADO</p>
                    
                    <!-- Estado del Teléfono -->
                    <div style="margin-top: 2rem; display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 12px; border: 1px solid var(--glass-border);">
                         <span id="status-dot" style="width: 10px; height: 10px; background: grey; border-radius: 50%; box-shadow: 0 0 10px currentColor;"></span>
                         <span id="phone-status" style="font-size: 0.875rem; font-weight: 600;">Iniciando teléfono...</span>
                    </div>

                    <div style="margin-top: 2rem;">
                        <h3>Campaña</h3>
                        <p><?php echo $_SESSION['current_campaign']; ?></p>
                    </div>
                    <div style="margin-top: 2rem;">
                        <h3>Conferencia</h3>
                        <p><?php echo $session_conf; ?></p>
                    </div>
                    <a href="?action=logout_session" class="btn btn-stop">Cerrar Sesión</a>
                </div>

                <div class="glass info-box">
                    <h3>Atención al Cliente</h3>
                    
                    <div id="lead-display">
                        <div style="height: 150px; display: flex; align-items: center; justify-content: center; color: #4b5563; border: 2px dashed rgba(255,255,255,0.05); border-radius: 12px; margin-top: 1rem;">
                            Esperando llamada entrante...
                        </div>
                    </div>

                    <div id="lead-details" style="display: none;">
                        <div class="lead-panel">
                            <div class="lead-field"><label>Nombre</label><span id="lead-name">-</span></div>
                            <div class="lead-field"><label>Teléfono</label><span id="lead-phone">-</span></div>
                            <div class="lead-field"><label>Ciudad</label><span id="lead-city">-</span></div>
                            <div class="lead-field"><label>Lead ID</label><span id="lead-id">-</span></div>
                        </div>
                        <select id="dispo-select" class="dispo-select" onchange="saveDisposition()">
                            <option value="">-- Seleccionar Tipificación --</option>
                            <?php foreach ($dispositions as $d): ?>
                                <option value="<?php echo $d['status']; ?>"><?php echo $d['status_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="controls-row">
                         <button id="btn-pause" onclick="togglePause()" class="btn-circle pause" title="Pausar/Activar">⏸</button>
                         <button id="btn-dial" onclick="manualDial()" class="btn-circle dial" title="Marcar Manual">📞</button>
                         <button id="btn-hangup" onclick="hangupCall()" class="btn-circle hangup" title="Colgar" disabled>✖</button>
                    </div>

                    <script>
                        function togglePause() {
                            const btn = document.getElementById('btn-pause');
                            const isPaused = btn.classList.contains('pause');
                            fetch('index.php?action=toggle_pause&status=' + (isPaused ? 'PAUSED' : 'READY'))
                            .then(() => {
                                btn.classList.toggle('pause');
                                btn.innerHTML = btn.classList.contains('pause') ? '⏸' : '▶';
                            });
                        }

                        function hangupCall() {
                            fetch('index.php?action=hangup')
                            .then(() => {
                                document.getElementById('btn-hangup').disabled = true;
                                VOXPhone.hangup();
                            });
                        }

                        function manualDial() {
                            const phone = prompt("Ingrese número a marcar:");
                            if (phone) {
                                fetch('index.php?action=manual_dial&phone=' + phone);
                            }
                        }

                        function saveDisposition() {
                            const status = document.getElementById('dispo-select').value;
                            const leadId = document.getElementById('lead-id').innerText;
                            if (!status) return;

                            fetch('index.php?action=save_dispo&status=' + status + '&lead_id=' + leadId)
                            .then(() => {
                                alert("Tipificación guardada correctamente.");
                                document.getElementById('lead-details').style.display = 'none';
                                document.getElementById('lead-display').style.display = 'block';
                            });
                        }

                        // Polling para datos de Lead
                        setInterval(() => {
                            fetch('index.php?action=get_lead')
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.lead_id) {
                                    document.getElementById('lead-display').style.display = 'none';
                                    document.getElementById('lead-details').style.display = 'block';
                                    document.getElementById('lead-name').innerText = data.first_name + ' ' + data.last_name;
                                    document.getElementById('lead-phone').innerText = data.phone_number;
                                    document.getElementById('lead-city').innerText = data.city;
                                    document.getElementById('lead-id').innerText = data.lead_id;
                                    document.getElementById('btn-hangup').disabled = false;
                                } else {
                                    document.getElementById('lead-display').style.display = 'block';
                                    document.getElementById('lead-details').style.display = 'none';
                                }
                            });
                        }, 3000);

                        // Heartbeat cada 10 segundos
                        setInterval(() => {
                            fetch('index.php?action=heartbeat');
                        }, 10000);
                    </script>
                </div>
            </div>
<?php 
// Manejo del Heartbeat y Trigger de Llamada
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'heartbeat') {
        $agentSession->heartbeat($user);
        exit;
    }
    if ($_GET['action'] === 'trigger_call') {
        $agentSession->triggerCall($user, $user, $_SESSION['conf_exten']);
        exit;
    }
    if ($_GET['action'] === 'toggle_pause') {
        $agentActions->togglePause($user, $_GET['status']);
        exit;
    }
    if ($_GET['action'] === 'hangup') {
        $agentActions->hangupCall($user);
        exit;
    }
    if ($_GET['action'] === 'get_lead') {
        $data = $agentActions->getCurrentLeadData($user);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    if ($_GET['action'] === 'save_dispo') {
        $agentActions->setDisposition($user, $_GET['lead_id'], $_GET['status']);
        exit;
    }
}
?>
        <?php endif; ?>
    </div>

    <!-- Webphone Scripts -->
    <script src="js/sip.min.js"></script>
    <script src="js/vici_phone.js"></script>
    
    <?php if ($session_conf): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const config = {
                user: "<?php echo $_SESSION['user']; ?>",
                password: "<?php echo $_SESSION['phone_pass']; ?>",
                serverIp: "<?php echo $server_ip; ?>",
                wssUrl: "wss://<?php echo $server_ip; ?>:8089/ws",
                displayName: "<?php echo $_SESSION['full_name']; ?>"
            };
            
            // Acceder a las clases de SIP.js desde el objeto global
            window.UserAgent = SIP.UserAgent;
            window.Registerer = SIP.Registerer;
            window.RegistererState = SIP.RegistererState;
            window.Transport = SIP.Web.Transport;

            VOXPhone.initialize(config);
        });
    </script>
    <?php endif; ?>
</body>
</html>

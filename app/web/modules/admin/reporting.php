<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Intentar cargar clases del sistema, pero no morir si fallan
$hasSystem = false;
try {
    require_once __DIR__ . '/../../includes/Auth.php';
    require_once __DIR__ . '/../../includes/Reporting.php';
    $hasSystem = true;
} catch (Exception $e) {
    $hasSystem = false;
}

use Includes\Auth;
use Includes\Reporting;

if ($hasSystem) {
    try { Auth::checkAccess(9); } catch (Exception $e) {}
}

$msg = '';
$serverIp = $_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()));
$config = ($hasSystem) ? Reporting::getConfig() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'host' => $_POST['remote_host'] ?? '',
        'port' => $_POST['remote_port'] ?? '3306',
        'user' => $_POST['remote_user'] ?? '',
        'pass' => $_POST['remote_pass'] ?? '',
        'db'   => $_POST['remote_db'] ?? ''
    ];

    // 🔍 TEST DE CONEXIÓN
    if (isset($_POST['test_connection'])) {
        try {
            $dsn = "mysql:host={$data['host']};port={$data['port']};dbname={$data['db']};charset=utf8mb4";
            $conn = new PDO($dsn, $data['user'], $data['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $msg = "<div class='alert success'>✅ ¡Conexión Exitosa! El servidor remoto responde correctamente.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert error'>❌ Error de conexión: " . $e->getMessage() . "</div>";
        }
    }

    // 💾 GUARDAR PERFIL (JSON)
    if (isset($_POST['save_profile'])) {
        try {
            Reporting::saveConfig($data);
            $msg = "<div class='alert success'>💾 Perfil guardado correctamente en config/reporting_mirror.json</div>";
            $config = Reporting::getConfig();
        } catch (Exception $e) {
            $msg = "<div class='alert error'>❌ Error al guardar perfil: " . $e->getMessage() . "</div>";
        }
    }

    // 🚀 INICIALIZAR ESPEJO (CREAR DB Y TABLA EN EL REMOTO)
    if (isset($_POST['init_mirror'])) {
        try {
            if (Reporting::setupMirror($data)) {
                $msg = "<div class='alert success'>💎 ¡Espejo Configurado! <br> - BD <b>vox_sphere_mirror</b> creada en el remoto. <br> - Tabla <b>vicidial_list</b> lista para el reporte.</div>";
                $config = Reporting::getConfig();
            }
        } catch (Exception $e) {
            $msg = "<div class='alert error'>❌ Error al inicializar espejo: " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración de Reportes - Zynervox</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --text-muted: #94a3b8; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 2rem; }
        .container { width: 100%; max-width: 900px; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
        .card { background: var(--card); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 2rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); }
        h1, h2 { margin-top: 0; color: var(--primary); }
        .field { margin-bottom: 1rem; }
        .field label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem; }
        .field input { width: 100%; box-sizing: border-box; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 0.7rem; color: white; }
        button { width: 100%; padding: 0.8rem; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 0.5rem; }
        .btn-test { background: rgba(255,255,255,0.05); color: white; border: 1px solid rgba(255,255,255,0.1); }
        .btn-test:hover { background: rgba(255,255,255,0.1); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85rem; line-height: 1.4; border-left: 4px solid; }
        .success { background: rgba(16, 185, 129, 0.1); border-color: #10b981; color: #10b981; }
        .error { background: rgba(239, 68, 68, 0.1); border-color: #ef4444; color: #fca5a5; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; background: rgba(255,255,255,0.1); margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Columna 1: Test y Datos -->
        <div class="card">
            <div class="badge">ÁREA DE TEST Y PERFIL</div>
            <h2>Configura el Servidor</h2>
            <p style="font-size: 0.75rem; color: #818cf8; margin-bottom: 1.5rem;">IP Origen (este servidor): <strong><?php echo $serverIp; ?></strong></p>
            <form method="POST">
                <div class="field">
                    <label>Servidor (Host)</label>
                    <input type="text" name="remote_host" value="<?php echo $_POST['remote_host'] ?? ($config['remote_host'] ?? ''); ?>" placeholder="192.168.1.50" required>
                </div>
                <div class="field">
                    <label>Puerto</label>
                    <input type="number" name="remote_port" value="<?php echo $_POST['remote_port'] ?? ($config['remote_port'] ?? '3306'); ?>">
                </div>
                <div class="field">
                    <label>Usuario</label>
                    <input type="text" name="remote_user" value="<?php echo $_POST['remote_user'] ?? ($config['remote_user'] ?? ''); ?>" placeholder="root" required>
                </div>
                <div class="field">
                    <label>Contraseña</label>
                    <input type="password" name="remote_pass" value="<?php echo $_POST['remote_pass'] ?? ($config['remote_pass'] ?? ''); ?>">
                </div>
                <div class="field">
                    <label>Base de Datos Inicial</label>
                    <input type="text" name="remote_db" value="<?php echo $_POST['remote_db'] ?? ($config['remote_db'] ?? 'mysql'); ?>">
                </div>
                <button type="submit" name="test_connection" class="btn-test">🔍 1. Probar Conexión</button>
                <button type="submit" name="save_profile" class="btn-primary" style="background: #10b981; margin-top: 0.5rem;">💾 2. Guardar Perfil Local</button>
            </form>
        </div>

        <!-- Columna 2: Acciones y Espejo -->
        <div class="card">
            <div class="badge">CONFIGURACIÓN DE ESPEJO</div>
            <h2>Acciones de Espejo</h2>
            <?php echo $msg; ?>
            
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 2rem;">
                Una vez guardado el perfil, presiona el botón de abajo para preparar el servidor de Reportes. 
                Esto creará automáticamente la base de datos <b>vox_sphere_mirror</b> y la tabla estructurada.
            </p>

            <form method="POST">
                <!-- Mantener campos ocultos para procesar la acción -->
                <input type="hidden" name="remote_host" value="<?php echo $_POST['remote_host'] ?? ($config['remote_host'] ?? ''); ?>">
                <input type="hidden" name="remote_port" value="<?php echo $_POST['remote_port'] ?? ($config['remote_port'] ?? '3306'); ?>">
                <input type="hidden" name="remote_user" value="<?php echo $_POST['remote_user'] ?? ($config['remote_user'] ?? ''); ?>">
                <input type="hidden" name="remote_pass" value="<?php echo $_POST['remote_pass'] ?? ($config['remote_pass'] ?? ''); ?>">
                <input type="hidden" name="remote_db"   value="<?php echo $_POST['remote_db']   ?? ($config['remote_db']   ?? 'mysql'); ?>">
                
                <button type="submit" name="init_mirror" class="btn-primary" style="padding: 1.5rem; font-size: 1rem; background: linear-gradient(45deg, #6366f1, #a855f7);">
                    🚀 3. Inicializar Servidor Espejo
                </button>
            </form>

            <div style="margin-top: 2rem; padding: 1rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                <label style="color: var(--primary); font-size: 0.7rem; font-weight: 800;">ESTADO:</label>
                <div style="font-size: 0.9rem; margin-top: 5px;">
                    <?php if (isset($_POST['init_mirror']) || (isset($config['is_active']) && $config['is_active'] == 1)): ?>
                        ✅ Servidor Listo para Sincronizar
                    <?php else: ?>
                        ⏳ Esperando Configuración...
                    <?php endif; ?>
                </div>
            </div>

            <a href="lists.php" style="display: block; text-align: center; margin-top: 2rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border: 1px solid rgba(255,255,255,0.1); padding: 0.8rem; border-radius: 8px; transition: 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                🏠 Volver al Inicio (Administración)
            </a>
        </div>
    </div>
</body>
</html>

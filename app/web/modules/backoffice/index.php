<?php
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

Auth::checkAccess(4); // Requiere nivel 4 mínimo
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Backoffice - Vicidial</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root { --accent: #ec4899; --bg: #0f172a; --card: #1e293b; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: white; margin: 0; padding: 0; }
        .container { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .role-badge { background: var(--accent); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .card { background: var(--card); border: 1px solid rgba(255,255,255,0.05); padding: 2rem; border-radius: 20px; margin-top: 2rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); }
        .btn-logout { background: rgba(239, 68, 68, 0.1); color: #fca5a5; padding: 0.5rem 1.25rem; border-radius: 10px; text-decoration: none; font-size: 0.875rem; border: 1px solid rgba(239, 68, 68, 0.2); transition: all 0.3s; }
        .btn-logout:hover { background: #ef4444; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <span class="role-badge">Nivel 4</span>
                <h1 style="margin: 0.5rem 0 0 0;">Panel Backoffice</h1>
                <p style="color: #94a3b8; margin: 0.25rem 0 0 0;">Bienvenido, <?php echo $_SESSION['full_name']; ?></p>
            </div>
            <a href="../../logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
        <div class="card">
            <h2 style="color: var(--accent);">Acceso Confirmado</h2>
            <p>Estás en la carpeta <code>/modules/backoffice/</code>. Este módulo está configurado para analítica avanzada y exportación de datos.</p>
        </div>
    </div>
</body>
</html>

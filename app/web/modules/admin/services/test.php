<?php
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios / Test - Zynervox</title>
    <link rel="stylesheet" href="../layout.css">
</head>
<body>

    <?php require_once __DIR__ . '/../sidebar.php'; renderSidebar('test', '../../../'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Servicios / Test</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">En construcción.</p>
            </div>
        </header>

        <div class="card">
            <p style="font-size: 0.8rem; color: var(--text-muted);">Esta sección todavía no tiene funcionalidad. Pendiente de definir.</p>
        </div>
    </main>

</body>
</html>

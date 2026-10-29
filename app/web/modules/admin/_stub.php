<?php
// Plantilla compartida para secciones del sidebar que todavia no tienen
// funcionalidad real. Cada pagina stub define $pageTitle y $activeKey y
// hace require de este archivo. Ver ROADMAP.md para el detalle de que falta
// construir en cada una.
require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
</head>
<body>

    <?php renderSidebar($activeKey); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;"><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Sección en construcción</p>
            </div>
        </header>

        <div class="card" style="text-align: center; padding: 3rem 2rem;">
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Esta sección todavía no tiene funcionalidad implementada.<br>
                Ver <code>ROADMAP.md</code> en la raíz del proyecto para el detalle de lo pendiente.
            </p>
        </div>
    </main>

</body>
</html>

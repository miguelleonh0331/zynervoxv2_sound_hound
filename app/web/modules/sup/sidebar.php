<?php
// Sidebar restringido para GTR (nivel 7) y Supervisor (nivel 8). Reutiliza
// las mismas paginas/backend de modules/admin (misma tabla, mismo Includes\*),
// solo que con un menu acotado a lo que este rol puede ver. Por ahora GTR y
// Supervisor tienen exactamente el mismo acceso (a diferenciar mas adelante).
require_once __DIR__ . '/../admin/sidebar.php'; // reutiliza _svgIcon()

function renderSupSidebar($activePage = 'campaigns') {
    $items = [
        ['key' => 'campaigns', 'href' => '../admin/campaigns.php', 'icon' => 'campaigns', 'label' => 'Campaigns (Campañas)'],
        ['key' => 'users',     'href' => '../admin/users.php',     'icon' => 'users',     'label' => 'Users (Usuarios)'],
        ['key' => 'reports',   'href' => '../admin/reports.php',   'icon' => 'chart',     'label' => 'Reportes'],
        ['key' => 'filters',   'href' => '../admin/filters.php',   'icon' => 'filters',   'label' => 'Filters'],
        ['key' => 'monitor',   'href' => '../admin/monitor.php',   'icon' => 'quality',   'label' => 'Monitor'],
        ['key' => 'whatsapp',  'href' => '../admin/whatsapp.php',  'icon' => 'whatsapp',  'label' => 'WhatsApp'],
        ['key' => 'campaign_monitor', 'href' => '../admin/campaign_monitor.php', 'icon' => 'campaign_monitor', 'label' => 'Monitor Campañas'],
    ];
    $roleLabel = ($_SESSION['user_level'] ?? 0) == 7 ? 'GTR' : 'Supervisor';
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <span>ZYNERVOX</span>
    </div>
    <div class="nav-section">
        <h3 class="nav-title"><?php echo $roleLabel; ?></h3>
        <?php foreach ($items as $it): ?>
        <a href="<?php echo $it['href']; ?>" class="nav-item <?php echo $activePage === $it['key'] ? 'active' : ''; ?>">
            <?php echo _svgIcon($it['icon']); ?> <?php echo $it['label']; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <a href="../../logout.php" class="nav-item logout-link" style="margin-top: auto; color: #ef4444;">
        <?php echo _svgIcon('logout'); ?> Cerrar Sesión
    </a>
</aside>
<?php
}
?>

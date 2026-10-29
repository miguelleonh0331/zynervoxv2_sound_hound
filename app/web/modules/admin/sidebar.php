<?php
require_once __DIR__ . '/../../includes/ServerInfo.php';

// Iconos en linea (estilo Feather), heredan color via currentColor -> se ven
// bien tanto en reposo (gris) como activo/hover (naranja) sin CSS extra.
function _svgIcon($name) {
    $icons = [
        'reports'   => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'campaigns' => '<path d="M3 11l18-5v12L3 14v-3z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>',
        'lists'     => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'quality'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>',
        'scripts'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
        'filters'   => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>',
        'inbound'   => '<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>',
        'usergroups'=> '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line>',
        'remote'    => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
        'admin'     => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H2a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 3.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H8a1.65 1.65 0 0 0 1-1.51V2a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H22a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'wizard'    => '<path d="M12 2l1.5 4.5L18 8l-4.5 1.5L12 14l-1.5-4.5L6 8l4.5-1.5z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"></path>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line>',
        'phones'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>',
        'chart'     => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'bot'       => '<rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line>',
        'monitor'   => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>',
        'campaign_monitor' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>',
        'checklist' => '<path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
        'whatsapp'  => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"></path>',
        'farm'      => '<path d="M3 21V10l9-7 9 7v11"></path><path d="M7 21v-7h10v7"></path><path d="M7 10h10"></path>',
        'stt'       => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="22"></line>',
        'services'  => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
    ];
    $body = $icons[$name] ?? '';
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

// $rootPrefix = cuantos "../" hacen falta para llegar a la raiz de Zynervox
// desde el script que esta incluyendo este sidebar. Los items de abajo estan
// escritos como rutas relativas A LA RAIZ (ej. 'modules/admin/users.php'),
// asi el mismo array sirve sin importar la profundidad del que lo llama:
// modules/admin/*.php necesita '../../', bot_ivr/*.php necesita '../', etc.
// (bug real: antes los hrefs eran relativos a modules/admin/ a secas, y al
// reusar este sidebar desde bot_ivr/ -un nivel mas arriba- los links
// quedaban rotos: apuntaban a bot_ivr/campaigns.php en vez de
// modules/admin/campaigns.php.)
function renderSidebar($activePage = 'home', $rootPrefix = '../../') {
    // "built" = false => la pagina todavia es un stub (_stub.php), se muestra
    // tachada en el menu para que se note a simple vista que falta construir.
    $items = [
        ['key' => 'home',        'href' => 'modules/admin/index.php',        'icon' => 'reports',    'label' => 'Dashboard (Inicio)', 'built' => true],
        ['key' => 'reports',     'href' => 'modules/admin/reports.php',      'icon' => 'chart',      'label' => 'Reportes',           'built' => true],
        ['key' => 'monitor',     'href' => 'modules/admin/monitor.php',      'icon' => 'monitor',    'label' => 'Monitor',            'built' => true],
        ['key' => 'campaign_monitor', 'href' => 'modules/admin/campaign_monitor.php', 'icon' => 'campaign_monitor', 'label' => 'Monitor Campañas', 'built' => true],
        ['key' => 'users',       'href' => 'modules/admin/users.php',        'icon' => 'users',      'label' => 'Users (Usuarios)',   'built' => true],
        ['key' => 'campaigns',   'href' => 'modules/admin/campaigns.php',    'icon' => 'campaigns',  'label' => 'Campaigns (Campañas)', 'built' => true],
        ['key' => 'dialer_agent','href' => 'modules/admin/dialer_agent.php', 'icon' => 'phones',     'label' => 'Panel de agente',    'built' => true],
        ['key' => 'lists',       'href' => 'modules/admin/lists.php',        'icon' => 'lists',      'label' => 'Lists (Bases de Datos)', 'built' => true],
        ['key' => 'quality',     'href' => 'modules/admin/quality.php',      'icon' => 'quality',    'label' => 'Quality Control (Calidad)', 'built' => false],
        ['key' => 'scripts',     'href' => 'modules/admin/scripts.php',      'icon' => 'scripts',    'label' => 'Scripts (Guiones)',  'built' => false],
        ['key' => 'filters',     'href' => 'modules/admin/filters.php',      'icon' => 'filters',    'label' => 'Filters',            'built' => true],
        ['key' => 'inbound',     'href' => 'modules/admin/inbound.php',      'icon' => 'inbound',    'label' => 'Inbound (Entrantes / DIDs)', 'built' => false],
        ['key' => 'bot_ivr',     'href' => 'bot_ivr/index.php',              'icon' => 'bot',        'label' => 'Bot IVR',            'built' => true],
        ['key' => 'ivr_builder', 'href' => 'ivr_builder/index.php',          'icon' => 'bot',        'label' => 'IVR Builder',        'built' => true],
        ['key' => 'whatsapp',    'href' => 'modules/admin/whatsapp.php',     'icon' => 'whatsapp',   'label' => 'WhatsApp',           'built' => true],
        ['key' => 'farm',        'href' => 'modules/admin/farm.php',         'icon' => 'farm',       'label' => 'Farm',               'built' => true],
        ['key' => 'stt_providers','href' => 'modules/admin/stt_providers.php','icon' => 'stt',       'label' => 'Stt Providers',      'built' => true],
        ['key' => 'zynerdesk',   'href' => 'modules/admin/zynerdesk.php',    'icon' => 'remote',     'label' => 'Zynerdesk',          'built' => true],
        ['key' => 'usergroups',  'href' => 'modules/admin/usergroups.php',   'icon' => 'usergroups', 'label' => 'User Groups (Grupos)', 'built' => true],
        ['key' => 'remote',      'href' => 'modules/admin/remoteagents.php', 'icon' => 'remote',     'label' => 'Agents GSM',         'built' => true],
        ['key' => 'vpn',          'href' => 'modules/vpn/index.php',          'icon' => 'remote',     'label' => 'Vpn',                'built' => true],
        ['key' => 'phones',      'href' => 'modules/admin/phones.php',       'icon' => 'phones',     'label' => 'Anexos/Teléfonos',   'built' => true],
        ['key' => 'admin',       'href' => 'modules/admin/carriers.php',     'icon' => 'admin',      'label' => 'Troncales SIP',      'built' => true],
        ['key' => 'dialplan',    'href' => 'modules/admin/dialplan.php',     'icon' => 'scripts',    'label' => 'Dialplan',           'built' => true],
    ];

    // "Dev" es un checklist interno de roadmap, visible SOLO para el
    // usuario 6666 (pedido explicito). Otros admins nivel 9 ni siquiera ven
    // el item en el menu, ademas de que la pagina se bloquea por su cuenta.
    if (($_SESSION['user'] ?? '') === '6666') {
        $items[] = ['key' => 'dev', 'href' => 'modules/admin/dev_checklist.php', 'icon' => 'checklist', 'label' => 'Dev (Checklist)', 'built' => true];
    }
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <span><a href="<?php echo $rootPrefix; ?>modules/admin/index.php" class="sidebar-logo-link">ZYNERVOX</a></span>
    </div>

    <div class="nav-section">
        <h3 class="nav-title">Herramientas</h3>
        <a href="<?php echo $rootPrefix; ?>modules/admin/wizard.php" class="nav-item <?php echo $activePage === 'wizard' ? 'active' : ''; ?>">
            <?php echo _svgIcon('wizard'); ?> Asistente (Wizard)
        </a>
    </div>

    <div class="nav-section">
        <h3 class="nav-title">Administración</h3>
        <?php foreach ($items as $it): ?>
        <a href="<?php echo $rootPrefix . $it['href']; ?>" class="nav-item <?php echo $activePage === $it['key'] ? 'active' : ''; ?>"
            <?php if (!$it['built']): ?>style="text-decoration: line-through; opacity: 0.55;" title="Aún no construido"<?php endif; ?>>
            <?php echo _svgIcon($it['icon']); ?> <?php echo $it['label']; ?>
        </a>
        <?php endforeach; ?>

        <?php
        // "Servicios" agrupa integraciones externas empaquetadas en Docker,
        // separadas de los módulos propios de arriba. Hoy solo trae Zypad;
        // el array deja espacio para futuras sin tocar el <details>.
        $servicios = [
            ['key' => 'zypad', 'href' => 'modules/admin/services/zypad.php', 'icon' => 'stt', 'label' => 'Zypad'],
            ['key' => 'zynerdesk_control', 'href' => 'modules/admin/services/zynerdesk_control.php', 'icon' => 'remote', 'label' => 'Zynerdesk'],
            ['key' => 'database', 'href' => 'modules/admin/services/database.php', 'icon' => 'services', 'label' => 'Base de datos'],
            ['key' => 'pbx', 'href' => 'modules/admin/services/pbx.php', 'icon' => 'services', 'label' => 'PBX'],
            ['key' => 'test', 'href' => 'modules/admin/services/test.php', 'icon' => 'checklist', 'label' => 'Test'],
        ];
        $serviciosOpen = in_array($activePage, array_column($servicios, 'key'), true);
        ?>
        <details class="nav-group"<?php echo $serviciosOpen ? ' open' : ''; ?>>
            <summary class="nav-item"><?php echo _svgIcon('services'); ?> Servicios</summary>
            <?php foreach ($servicios as $sv): ?>
            <a href="<?php echo $rootPrefix . $sv['href']; ?>" class="nav-item nav-subitem <?php echo $activePage === $sv['key'] ? 'active' : ''; ?>">
                <?php echo _svgIcon($sv['icon']); ?> <?php echo $sv['label']; ?>
            </a>
            <?php endforeach; ?>
        </details>
    </div>

    <a href="<?php echo $rootPrefix; ?>logout.php" class="nav-item logout-link" style="margin-top: auto; color: #ef4444;">
        <?php echo _svgIcon('logout'); ?> Cerrar Sesión
    </a>

    <?php
    // Identificador de servidor: hostname + IP LAN se calculan solos (no se
    // piden al usuario) para saber de un vistazo en cual Zynervox esta
    // parado. La nota de abajo es texto libre, se guarda LOCAL a este
    // servidor (ver includes/ServerInfo.php) via api_server_info.php.
    $serverHost = \Includes\ServerInfo::hostname();
    $serverIp = \Includes\ServerInfo::lanIp();
    $serverNotes = \Includes\ServerInfo::getNotes();
    ?>
    <div class="server-info">
        <div class="server-info-id" title="Hostname / IP LAN de este servidor (calculado, no editable)">
            <span class="server-info-host"><?php echo htmlspecialchars($serverHost); ?></span>
            <span class="server-info-ip"><?php echo htmlspecialchars($serverIp); ?></span>
        </div>
        <textarea id="serverInfoNotes" class="server-info-notes" maxlength="2000" placeholder="Notas de este servidor (ej. producción, lab de pruebas...)"><?php echo htmlspecialchars($serverNotes); ?></textarea>
        <div class="server-info-actions">
            <button type="button" id="serverInfoSaveBtn" class="server-info-save">Guardar</button>
            <span id="serverInfoStatus" class="server-info-status"></span>
        </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('serverInfoSaveBtn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var ta = document.getElementById('serverInfoNotes');
            var status = document.getElementById('serverInfoStatus');
            status.textContent = 'Guardando...';
            fetch('<?php echo $rootPrefix; ?>modules/admin/api_server_info.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notes: ta.value })
            })
                .then(function (r) { return r.json(); })
                .then(function () {
                    status.textContent = 'Guardado ✓';
                    setTimeout(function () { status.textContent = ''; }, 2000);
                })
                .catch(function () {
                    status.textContent = 'Error al guardar';
                });
        });
    })();
    </script>
</aside>
<?php
}
?>

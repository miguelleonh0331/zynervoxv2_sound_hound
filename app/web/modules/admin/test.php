<?php
declare(strict_types=1);
// Pantalla «Test»: pruebas rápidas con un solo número. Crea una lista de prueba (1 contacto, 1 canal, detenida) y la inicia con la acción start de siempre.
// La lógica es del servicio (bot_cfg_test_create / bot_cfg_test_recent); aquí solo hay pantalla, CSRF y nivel 9. Sin SQL propio.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

function test_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$isApi = isset($_GET['data']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isApi) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) test_json(401, ['ok' => false, 'error' => 'Sesión requerida.']);
    try {
        $db = bot_ivr_repository();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
            if (empty($_SESSION['test_csrf']) || !hash_equals($_SESSION['test_csrf'], $token)) test_json(403, ['ok' => false, 'error' => 'Sesión inválida. Recarga la página.']);
            if ((string)($_POST['action'] ?? '') !== 'create') test_json(400, ['ok' => false, 'error' => 'Acción inválida.']);
            $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$campaignId) test_json(422, ['ok' => false, 'error' => 'Elige una campaña.']);
            try {
                $list = bot_cfg_test_create($db, $campaignId, (string)($_POST['phone'] ?? ''), (string)$_SESSION['user']);
            } catch (RuntimeException $e) {
                test_json(422, ['ok' => false, 'error' => $e->getMessage()]);
            }
            test_json(200, ['ok' => true, 'list' => $list]);
        }
        $campaigns = [];
        foreach (bot_cfg_list($db) as $c) if ((int)$c['active'] === 1) $campaigns[] = ['campaign_id' => (int)$c['campaign_id'], 'name' => $c['name']];
        test_json(200, ['ok' => true, 'campaigns' => $campaigns, 'recent' => bot_cfg_test_recent($db, 20)]);
    } catch (Throwable $e) {
        test_json(503, ['ok' => false, 'error' => 'No se pudo consultar el servicio.']);
    }
}

Auth::checkAccess(9);
if (empty($_SESSION['test_csrf'])) $_SESSION['test_csrf'] = bin2hex(random_bytes(32));
if (empty($_SESSION['dashboard_csrf'])) $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32)); // la acción start es la de dashboard_api.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem; align-items: end; }
        .cfg-grid label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .cfg-grid input, .cfg-grid select { width: 100%; box-sizing: border-box; }
        .card + .card { margin-top: 1rem; }
        .msg-err { color: #dc2626; margin: 0.5rem 0; } .msg-ok { color: #059669; margin: 0.5rem 0; }
        .created { border-left: 4px solid #10b981; } .created .row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; margin-top: 0.4rem; }
        .muted { color: var(--text-muted); font-size: 0.78rem; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; user-select: all; }
        .st { font-size: 0.7rem; padding: 1px 6px; background: var(--glass); } .st.en_curso { background: #064e3b; color: #6ee7b7; }
        button:disabled { opacity: .45; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php renderSidebar('test'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Test</h1>
                <p class="muted">Prueba rápida con un solo número: se crea una lista de prueba (1 número, 1 canal) y tú decides cuándo llamar.</p>
            </div>
        </header>

        <form class="card" id="form" autocomplete="off">
            <h2>Crear lista de prueba</h2>
            <div class="cfg-grid">
                <label>Campaña <select id="campaign" required><option value="">— elegir —</option></select></label>
                <label>Número <input id="phone" inputmode="tel" maxlength="30" placeholder="9 dígitos, sin 51, p. ej. 999999999" required></label>
                <button type="submit" class="btn-primary" id="create">Crear lista de prueba</button>
            </div>
            <p class="muted">Crear la lista no llama a nadie. Solo se llama cuando pulsas «Iniciar» y confirmas.</p>
            <div id="msg"></div>
        </form>

        <div class="card created" id="created" hidden></div>

        <div class="card">
            <h2>Últimas pruebas <span class="muted" id="updated"></span></h2>
            <div style="overflow-x: auto;"><table class="table-compact" id="recent"><thead><tr><th>Hora (Lima)</th><th>Campaña</th><th>Número</th><th>Estado</th><th>Resultado</th><th></th></tr></thead><tbody></tbody></table></div>
        </div>
    </main>
    <script>
    (function () {
        var TEST_CSRF = <?php echo json_encode($_SESSION['test_csrf']); ?>, DASH_CSRF = <?php echo json_encode($_SESSION['dashboard_csrf']); ?>;
        var campaignsById = {}, busy = false;
        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function msg(text, cls) { var m = document.getElementById('msg'); m.textContent = ''; if (text) m.appendChild(el('p', cls || 'msg-err', text)); }
        function lima(utc) { if (!utc) return ''; var d = new Date(String(utc).replace(' ', 'T') + 'Z'); return isNaN(d) ? utc : new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', dateStyle: 'short', timeStyle: 'medium' }).format(d); }
        var STATE = { sin_iniciar: 'Sin iniciar', en_curso: 'En curso', terminada: 'Terminada', detenida: 'Detenida' };

        function startList(campaignId, listId, phone, campaign) {
            if (!window.confirm('Se llamará de verdad al número ' + phone + ' desde la campaña «' + campaign + '». ¿Continuar?')) return Promise.resolve(false);
            var body = new URLSearchParams({ action: 'start', campaign_id: campaignId, list_id: listId, csrf: DASH_CSRF });
            return fetch('dashboard_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': DASH_CSRF }, body: body })
                .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'No se pudo iniciar.'); msg('Llamada iniciada.', 'msg-ok'); load(); return true; })
                .catch(function (e) { msg(e.message); return false; });
        }

        function showCreated(l, phone) {
            var box = document.getElementById('created'); box.hidden = false; box.textContent = '';
            box.appendChild(el('h2', '', 'Lista creada'));
            var campaign = (campaignsById[l.campaign_id] || {}).name || ('#' + l.campaign_id);
            var row = el('div', 'row'); row.appendChild(el('b', '', l.name)); row.appendChild(el('span', '', 'Campaña: ' + campaign)); var n = el('span', 'num', 'Número: ' + phone); row.appendChild(n);
            var a = el('a', 'btn-action', 'Ver en Reportes'); a.href = 'reports.php?campaign_id=' + encodeURIComponent(l.campaign_id) + '&list_id=' + encodeURIComponent(l.list_id); row.appendChild(a);
            var go = el('button', 'btn-primary', 'Iniciar'); go.type = 'button';
            go.addEventListener('click', function () { go.disabled = true; startList(l.campaign_id, l.list_id, phone, campaign).then(function (ok) { if (!ok) go.disabled = false; }); });
            row.appendChild(go); box.appendChild(row);
        }

        function render(d) {
            var sel = document.getElementById('campaign'), keep = sel.value; campaignsById = {};
            if (sel.options.length - 1 !== d.campaigns.length) { sel.textContent = ''; var o0 = el('option', '', '— elegir —'); o0.value = ''; sel.appendChild(o0); d.campaigns.forEach(function (c) { var o = el('option', '', c.name + ' (#' + c.campaign_id + ')'); o.value = c.campaign_id; sel.appendChild(o); }); sel.value = keep; }
            d.campaigns.forEach(function (c) { campaignsById[c.campaign_id] = c; });
            var tb = document.querySelector('#recent tbody'); tb.textContent = '';
            if (!d.recent.length) { var tr = el('tr'), td = el('td', 'muted', 'Todavía no hay pruebas.'); td.colSpan = 6; tr.appendChild(td); tb.appendChild(tr); }
            d.recent.forEach(function (r) {
                var tr = el('tr'); tr.appendChild(el('td', '', lima(r.created_at))); tr.appendChild(el('td', '', r.campaign));
                tr.appendChild(el('td', 'num', r.phone));
                var st = el('td'); st.appendChild(el('span', 'st ' + r.state, STATE[r.state] || r.state)); tr.appendChild(st);
                tr.appendChild(el('td', '', r.estado_label ? r.estado_label + (r.resultado ? ' · ' + r.resultado : '') : (r.resultado || (r.state === 'sin_iniciar' ? 'Aún no se ha llamado' : '—'))));
                var act = el('td'); var a = el('a', 'btn-action', 'Reportes'); a.href = 'reports.php?campaign_id=' + encodeURIComponent(r.campaign_id) + '&list_id=' + encodeURIComponent(r.list_id); act.appendChild(a);
                if (r.state === 'sin_iniciar') { var go = el('button', 'btn-action', 'Iniciar'); go.type = 'button'; go.style.marginLeft = '0.3rem'; go.addEventListener('click', function () { go.disabled = true; startList(r.campaign_id, r.list_id, r.phone, r.campaign).then(function (ok) { if (!ok) go.disabled = false; }); }); act.appendChild(go); }
                tr.appendChild(act); tb.appendChild(tr);
            });
            document.getElementById('updated').textContent = '· ' + new Date().toLocaleTimeString();
        }

        function load() {
            return fetch('test.php?data=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(render).catch(function (e) { msg('No se pudo actualizar: ' + e.message); });
        }

        document.getElementById('form').addEventListener('submit', function (e) {
            e.preventDefault(); if (busy) return; busy = true; document.getElementById('create').disabled = true; msg('');
            var phone = document.getElementById('phone').value.trim();
            var body = new URLSearchParams({ action: 'create', campaign_id: document.getElementById('campaign').value, phone: phone, csrf: TEST_CSRF });
            fetch('test.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': TEST_CSRF }, body: body }).then(function (r) { return r.json(); })
                .then(function (j) { if (!j.ok) throw new Error(j.error || 'No se pudo crear la lista.'); showCreated(j.list, phone); document.getElementById('phone').value = ''; load(); })
                .catch(function (e2) { msg(e2.message); }).then(function () { busy = false; document.getElementById('create').disabled = false; });
        });

        load(); setInterval(function () { if (!document.hidden) load(); }, 5000);
    })();
    </script>
</body>
</html>

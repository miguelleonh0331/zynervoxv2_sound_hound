<?php
declare(strict_types=1);
// Pantalla «Blacklist»: listas globales y regular, buscador de números. Toda la lógica es del servicio (bot_bl_*, con auditoría);
// aquí solo hay pantalla, CSRF y nivel 9. Sin SQL propio.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

function bl_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$isApi = isset($_GET['data']) || isset($_GET['search']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isApi) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) bl_json(401, ['ok' => false, 'error' => 'Sesión requerida.']);
    $actor = (string)$_SESSION['user'];
    try {
        $db = bot_ivr_repository();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
            if (empty($_SESSION['blacklist_csrf']) || !hash_equals($_SESSION['blacklist_csrf'], $token)) bl_json(403, ['ok' => false, 'error' => 'Sesión inválida. Recarga la página.']);
            $action = (string)($_POST['action'] ?? '');
            $scope = (string)($_POST['scope'] ?? '');
            $target = (int)($_POST['target'] ?? 0);
            $phone = (string)($_POST['phone'] ?? '');
            $confirm = ($_POST['confirm'] ?? '') === '1';
            try {
                switch ($action) {
                    case 'list_create': $r = ['list_id' => bot_bl_list_create($db, (string)($_POST['name'] ?? ''), $actor)]; break;
                    case 'list_rename': bot_bl_list_rename($db, $target, (string)($_POST['name'] ?? ''), $actor); $r = []; break;
                    case 'add': $r = ['phone' => bot_bl_add($db, $scope, $target, $phone, (string)($_POST['reason'] ?? ''), $actor)]; break;
                    case 'remove': $r = ['removed' => bot_bl_remove($db, $scope, $target, $phone, $actor, $confirm)]; break;
                    case 'clear_regular': $r = ['cleared' => bot_bl_clear_regular($db, $target, $actor, $confirm)]; break;
                    default: bl_json(400, ['ok' => false, 'error' => 'Acción inválida.']);
                }
            } catch (RuntimeException $e) {
                bl_json(422, ['ok' => false, 'error' => $e->getMessage()]);
            }
            bl_json(200, ['ok' => true] + $r);
        }
        if (isset($_GET['search'])) {
            try { bl_json(200, ['ok' => true, 'rows' => bot_bl_search($db, (string)$_GET['search']), 'audit' => bot_bl_audit($db, 20, (string)$_GET['search'])]); }
            catch (RuntimeException $e) { bl_json(422, ['ok' => false, 'error' => $e->getMessage()]); }
        }
        $campaigns = [];
        foreach (bot_cfg_list($db) as $c) $campaigns[] = ['campaign_id' => (int)$c['campaign_id'], 'name' => $c['name']];
        bl_json(200, ['ok' => true, 'lists' => bot_bl_lists($db), 'campaigns' => $campaigns, 'audit' => bot_bl_audit($db, 20)]);
    } catch (Throwable $e) {
        bl_json(503, ['ok' => false, 'error' => 'No se pudo consultar el servicio.']);
    }
}

Auth::checkAccess(9);
if (empty($_SESSION['blacklist_csrf'])) $_SESSION['blacklist_csrf'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacklist - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; align-items: end; }
        .cfg-grid label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .cfg-grid input, .cfg-grid select { width: 100%; box-sizing: border-box; }
        .card + .card { margin-top: 1rem; }
        .msg-err { color: #dc2626; margin: 0.5rem 0; } .msg-ok { color: #059669; margin: 0.5rem 0; }
        .muted { color: var(--text-muted); font-size: 0.78rem; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; user-select: all; }
        .tag { font-size: 0.7rem; padding: 1px 6px; background: var(--glass); } .tag.global { background: #1e3a8a; color: #bfdbfe; }
        .btn-danger { background: #b91c1c; color: #fff; border: 0; padding: 0.25rem 0.6rem; cursor: pointer; }
        .note { border-left: 4px solid #6b7280; }
    </style>
</head>
<body>
    <?php renderSidebar('blacklist'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Blacklist</h1>
                <p class="muted">Números a los que no se debe llamar. Listas globales (por cliente, p. ej. BCP) y lista regular (por campaña o todas).</p>
            </div>
        </header>
        <p class="muted note card">No hay carga masiva en esta pantalla: las cargas de muchos números se hacen directamente en la base de datos. Los cambios en una lista global se aplican al cargar una base: si añades números después, vuelve a cargar la base.</p>
        <div id="msg"></div>

        <section class="card">
            <h2>Buscar un número</h2>
            <form id="fsearch" class="cfg-grid" autocomplete="off">
                <label>Número <input id="q" inputmode="tel" maxlength="30" placeholder="9 dígitos, sin 51" required></label>
                <button type="submit" class="btn-primary">Buscar</button>
            </form>
            <div id="result" style="margin-top:.75rem"></div>
        </section>

        <section class="card">
            <h2>Añadir un número</h2>
            <form id="fadd" class="cfg-grid" autocomplete="off">
                <label>Lista <select id="a_list" required></select></label>
                <label>Número <input id="a_phone" inputmode="tel" maxlength="30" placeholder="9 dígitos, sin 51" required></label>
                <label>Motivo (opcional) <input id="a_reason" maxlength="160"></label>
                <button type="submit" class="btn-primary">Añadir</button>
            </form>
        </section>

        <section class="card">
            <h2>Listas globales</h2>
            <form id="fcreate" class="cfg-grid" autocomplete="off">
                <label>Nueva lista global <input id="n_name" maxlength="80" placeholder="Ej.: BCP" required></label>
                <button type="submit" class="btn-primary">Crear</button>
            </form>
            <div style="overflow-x:auto;margin-top:.75rem"><table class="table-compact" id="tglobal"><thead><tr><th>Nombre</th><th>Números</th><th>Campañas que la usan</th><th>Creada por</th><th></th></tr></thead><tbody></tbody></table></div>
            <p class="muted">Una lista global no se puede borrar ni vaciar: solo se retiran números uno a uno desde el buscador.</p>
        </section>

        <section class="card">
            <h2>Lista regular</h2>
            <p class="muted">Aplica a la campaña elegida o a todas. Vaciarla solo borra las filas de ese ámbito.</p>
            <form id="fclear" class="cfg-grid">
                <label>Ámbito <select id="c_camp"></select></label>
                <button type="submit" class="btn-danger">Vaciar lista regular</button>
            </form>
        </section>

        <section class="card">
            <h2>Últimos cambios</h2>
            <div style="overflow-x:auto;"><table class="table-compact" id="taudit"><thead><tr><th>Hora (Lima)</th><th>Usuario</th><th>Acción</th><th>Lista</th><th>Número</th><th>Detalle</th></tr></thead><tbody></tbody></table></div>
        </section>
    </main>
    <script>
    (function () {
        var CSRF = <?php echo json_encode($_SESSION['blacklist_csrf']); ?>;
        var state = { lists: [], campaigns: [] }, lastQuery = '';
        var ACTION = { add: 'Añadió', remove: 'Retiró', clear: 'Vació', list_create: 'Creó lista', list_rename: 'Renombró lista', assign: 'Asignó lista' };
        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function msg(text, cls) { var m = document.getElementById('msg'); m.textContent = ''; if (text) m.appendChild(el('p', cls || 'msg-err', text)); }
        function lima(utc) { if (!utc) return ''; var d = new Date(String(utc).replace(' ', 'T') + 'Z'); return isNaN(d) ? utc : new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', dateStyle: 'short', timeStyle: 'short' }).format(d); }
        function get(url) { return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); }); }
        function post(data) {
            data.csrf = CSRF;
            return fetch('blacklist.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: new URLSearchParams(data) })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo completar.'); return j; }); });
        }
        function campName(id) { if (Number(id) === 0) return 'Todas las campañas'; var c = state.campaigns.filter(function (x) { return x.campaign_id === Number(id); })[0]; return c ? c.name : '#' + id; }

        function renderAudit(rows) {
            var tb = document.querySelector('#taudit tbody'); tb.textContent = '';
            if (!rows.length) { var tr = el('tr'), td = el('td', 'muted', 'Sin cambios registrados.'); td.colSpan = 6; tr.appendChild(td); tb.appendChild(tr); }
            rows.forEach(function (a) {
                var tr = el('tr'); tr.appendChild(el('td', '', lima(a.at))); tr.appendChild(el('td', '', a.actor)); tr.appendChild(el('td', '', ACTION[a.action] || a.action));
                tr.appendChild(el('td', '', a.list_name || (a.campaign_id !== null && a.campaign_id !== undefined ? campName(a.campaign_id) : '')));
                tr.appendChild(el('td', 'num', a.phone || '')); tr.appendChild(el('td', 'muted', a.detail || (a.rows_affected ? a.rows_affected + ' filas' : ''))); tb.appendChild(tr);
            });
        }

        function renderLists() {
            var tb = document.querySelector('#tglobal tbody'); tb.textContent = '';
            if (!state.lists.length) { var tr0 = el('tr'), td0 = el('td', 'muted', 'Todavía no hay listas globales.'); td0.colSpan = 5; tr0.appendChild(td0); tb.appendChild(tr0); }
            state.lists.forEach(function (l) {
                var tr = el('tr'); tr.appendChild(el('td', '', l.name)); tr.appendChild(el('td', 'num', String(l.numbers))); tr.appendChild(el('td', '', String(l.campaigns))); tr.appendChild(el('td', '', l.created_by));
                var act = el('td'), b = el('button', 'btn-action', 'Renombrar'); b.type = 'button';
                b.addEventListener('click', function () { var n = window.prompt('Nuevo nombre de la lista:', l.name); if (n === null || n.trim() === '' || n.trim() === l.name) return; post({ action: 'list_rename', target: l.list_id, name: n }).then(function () { msg('Lista renombrada.', 'msg-ok'); return load(); }).catch(function (e) { msg(e.message); }); });
                act.appendChild(b); tr.appendChild(act); tb.appendChild(tr);
            });
            var sel = document.getElementById('a_list'), keep = sel.value; sel.textContent = '';
            state.lists.forEach(function (l) { var o = el('option', '', 'Global: ' + l.name); o.value = 'global:' + l.list_id; sel.appendChild(o); });
            var or = el('option', '', 'Regular: todas las campañas'); or.value = 'regular:0'; sel.appendChild(or);
            state.campaigns.forEach(function (c) { var o = el('option', '', 'Regular: ' + c.name); o.value = 'regular:' + c.campaign_id; sel.appendChild(o); });
            if (keep) sel.value = keep;
            var cs = document.getElementById('c_camp'), keepC = cs.value; cs.textContent = '';
            var o0 = el('option', '', 'Solo la de «Todas las campañas»'); o0.value = '0'; cs.appendChild(o0);
            state.campaigns.forEach(function (c) { var o = el('option', '', c.name); o.value = c.campaign_id; cs.appendChild(o); });
            if (keepC) cs.value = keepC;
        }

        function renderResult(rows, q) {
            var box = document.getElementById('result'); box.textContent = '';
            if (!rows.length) { box.appendChild(el('p', 'muted', 'El número ' + q + ' no está en ninguna lista.')); return; }
            var t = el('table', 'table-compact'), hd = el('thead'), hr = el('tr');
            ['Tipo', 'Lista', 'Motivo', 'Desde (Lima)', 'Quién', ''].forEach(function (h) { hr.appendChild(el('th', '', h)); }); hd.appendChild(hr); t.appendChild(hd);
            var tb = el('tbody');
            rows.forEach(function (r) {
                var tr = el('tr'), tp = el('td'); tp.appendChild(el('span', 'tag ' + r.scope, r.scope === 'global' ? 'Global' : 'Regular')); tr.appendChild(tp);
                tr.appendChild(el('td', '', r.list_name)); tr.appendChild(el('td', '', r.reason || '—')); tr.appendChild(el('td', '', lima(r.created_at))); tr.appendChild(el('td', '', r.created_by || ''));
                var act = el('td'), b = el('button', 'btn-danger', 'Retirar'); b.type = 'button';
                b.addEventListener('click', function () {
                    var txt = r.scope === 'global'
                        ? 'Vas a retirar el número ' + q + ' de la lista GLOBAL «' + r.list_name + '». Las campañas que la usan podrán volver a llamarle al cargar una base. ¿Retirarlo?'
                        : 'Retirar el número ' + q + ' de la lista regular («' + r.list_name + '»). ¿Continuar?';
                    if (!window.confirm(txt)) return;
                    b.disabled = true;
                    post({ action: 'remove', scope: r.scope, target: r.scope === 'global' ? r.list_id : (r.campaign_id || 0), phone: q, confirm: '1' })
                        .then(function (j) { msg(j.removed ? 'Número retirado.' : 'El número ya no estaba en esa lista.', 'msg-ok'); return search(q).then(load); })
                        .catch(function (e) { msg(e.message); b.disabled = false; });
                });
                act.appendChild(b); tr.appendChild(act); tb.appendChild(tr);
            });
            t.appendChild(tb); var w = el('div'); w.style.overflowX = 'auto'; w.appendChild(t); box.appendChild(w);
        }

        function search(q) {
            lastQuery = q;
            return get('blacklist.php?search=' + encodeURIComponent(q)).then(function (j) { renderResult(j.rows, q); }).catch(function (e) { document.getElementById('result').textContent = ''; msg(e.message); });
        }
        function load() {
            return get('blacklist.php?data=1').then(function (j) { state.lists = j.lists; state.campaigns = j.campaigns; renderLists(); renderAudit(j.audit); })
                .catch(function (e) { msg('No se pudo actualizar: ' + e.message); });
        }

        document.getElementById('fsearch').addEventListener('submit', function (e) { e.preventDefault(); msg(''); search(document.getElementById('q').value.trim()); });
        document.getElementById('fadd').addEventListener('submit', function (e) {
            e.preventDefault(); msg(''); var p = document.getElementById('a_list').value.split(':'), phone = document.getElementById('a_phone').value.trim();
            post({ action: 'add', scope: p[0], target: p[1], phone: phone, reason: document.getElementById('a_reason').value })
                .then(function (j) { msg('Número ' + j.phone + ' añadido.', 'msg-ok'); document.getElementById('a_phone').value = ''; document.getElementById('a_reason').value = ''; return load(); })
                .catch(function (e2) { msg(e2.message); });
        });
        document.getElementById('fcreate').addEventListener('submit', function (e) {
            e.preventDefault(); msg('');
            post({ action: 'list_create', name: document.getElementById('n_name').value })
                .then(function () { msg('Lista creada.', 'msg-ok'); document.getElementById('n_name').value = ''; return load(); })
                .catch(function (e2) { msg(e2.message); });
        });
        document.getElementById('fclear').addEventListener('submit', function (e) {
            e.preventDefault(); msg(''); var id = document.getElementById('c_camp').value, name = campName(id);
            if (!window.confirm((id === '0' ? 'Vas a VACIAR los números de la lista regular marcados para TODAS las campañas (las listas propias de cada campaña no se tocan). ¿Continuar?' : 'Vas a VACIAR la lista regular de «' + name + '». Se borran todos sus números. ¿Continuar?'))) return;
            post({ action: 'clear_regular', target: id, confirm: '1' })
                .then(function (j) { msg('Lista regular vaciada: ' + j.cleared + ' números.', 'msg-ok'); return load(); })
                .catch(function (e2) { msg(e2.message); });
        });
        load();
    })();
    </script>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

Auth::checkAccess(9);
if (empty($_SESSION['dashboard_csrf'])) $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .metric { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; }
        .metric-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .metric-value { font-size: 1.5rem; font-weight: 700; margin: 0.25rem 0; }
        .metric-sub { font-size: 0.75rem; color: var(--text-muted); }
        .bar { height: 6px; background: var(--glass); border-radius: 3px; overflow: hidden; margin-top: 0.4rem; }
        .bar > span { display: block; height: 100%; background: #10b981; }
        .bar > span.warn { background: #f59e0b; } .bar > span.crit { background: #ef4444; }
        .services { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .svc { display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.7rem; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); font-size: 0.8rem; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: #9ca3af; }
        .dot.on { background: #10b981; } .dot.off { background: #ef4444; }
        .camp { margin-bottom: 1rem; }
        .camp h2 { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: baseline; }
        .tag { font-size: 0.65rem; padding: 1px 6px; border-radius: 2px; background: var(--glass); color: var(--text-muted); text-transform: uppercase; }
        .tag.run { background: #064e3b; color: #6ee7b7; }
        .lists { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 0.75rem; }
        .list-card { border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; background: var(--bg-card); }
        .list-head { display: flex; justify-content: space-between; gap: 0.5rem; align-items: baseline; flex-wrap: wrap; }
        .list-name { font-weight: 700; }
        .reason { font-size: 0.78rem; margin: 0.4rem 0; color: #fbbf24; }
        .reason.ok { color: #10b981; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.35rem 0.5rem; margin: 0.6rem 0; }
        .stat { font-size: 0.72rem; color: var(--text-muted); }
        .stat strong { display: block; font-size: 1rem; color: var(--text); }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .actions button:disabled { opacity: 0.45; cursor: not-allowed; }
        .dash-error { color: #fca5a5; margin-bottom: 1rem; }
        .dash-msg { color: #10b981; margin-bottom: 1rem; }
        @media (max-width: 600px) { .stats { grid-template-columns: repeat(2, 1fr); } .lists { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php renderSidebar('home'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Dashboard</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Servidor, servicios y listas · se actualiza cada 5 segundos <span id="updated"></span></p>
            </div>
        </header>
        <div id="notice"></div>
        <div id="status"></div>
        <section class="dash-grid" id="server" aria-label="Servidor"></section>
        <section class="services" id="services" aria-label="Servicios"></section>
        <section id="campaigns" aria-label="Campañas y listas"></section>
    </main>
    <script>
    (function () {
        var CSRF = <?php echo json_encode($_SESSION['dashboard_csrf']); ?>;
        var API = 'dashboard_api.php';
        var busy = false, timer = null;
        var LABELS = [['contacts', 'Contactos'], ['not_called', 'Sin llamar'], ['calls', 'Llamadas'], ['human', 'Humano'], ['machine', 'Contestadora'], ['not_sure', 'No seguro'], ['unavailable', 'No disponible'], ['no_answer', 'Sin respuesta'], ['failed', 'Fallidas']];

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function gb(b) { return b == null ? '' : (b / 1073741824).toFixed(1) + ' GB'; }
        function metric(label, pct, sub) {
            var m = el('div', 'metric'); m.appendChild(el('div', 'metric-label', label));
            m.appendChild(el('div', 'metric-value', pct == null ? '—' : pct + ' %'));
            if (sub) m.appendChild(el('div', 'metric-sub', sub));
            var bar = el('div', 'bar'), fill = el('span', pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : '');
            fill.style.width = (pct == null ? 0 : Math.min(100, pct)) + '%'; bar.appendChild(fill); m.appendChild(bar); return m;
        }
        function showNotice(text, cls, id) { var n = document.getElementById(id || 'notice'); n.textContent = ''; if (text) n.appendChild(el('p', cls, text)); }

        function post(action, c, l) {
            var body = new URLSearchParams({ action: action, campaign_id: c, list_id: l, csrf: CSRF });
            return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: body }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) throw new Error(j.error || 'No se pudo completar la acción.');
            });
        }
        function act(action, c, l, confirmText) {
            if (confirmText && !window.confirm(confirmText)) return;
            showNotice('', '');
            post(action, c, l).then(refresh).catch(function (e) { showNotice(e.message, 'dash-error'); refresh(); });
        }

        function listCard(c, l) {
            var card = el('div', 'list-card');
            var head = el('div', 'list-head');
            head.appendChild(el('span', 'list-name', l.name + ' (#' + l.list_id + ')'));
            var tags = el('span'); tags.appendChild(el('span', 'tag' + (l.started ? ' run' : ''), l.started ? 'Marcando' : 'Detenida'));
            tags.appendChild(document.createTextNode(' ')); tags.appendChild(el('span', 'tag', l.active ? 'Activa' : 'Inactiva')); head.appendChild(tags);
            card.appendChild(head);
            var why = l.reason || (l.started ? 'Marcando' : '');
            if (why) card.appendChild(el('div', 'reason' + (l.reason ? '' : ' ok'), why));
            var pb = el('div', 'bar'), pf = el('span'); pf.style.width = l.progress_pct + '%'; pb.appendChild(pf); card.appendChild(pb);
            card.appendChild(el('div', 'metric-sub', 'Avance ' + l.progress_pct + ' % · Canales ' + l.channels + ' · En curso ' + l.open_calls));
            var st = el('div', 'stats');
            LABELS.forEach(function (p) { var s = el('div', 'stat', p[1]); s.insertBefore(el('strong', '', String(l.totals[p[0]] || 0)), s.firstChild); st.appendChild(s); });
            card.appendChild(st);
            var ac = el('div', 'actions');
            var tog = el('button', 'btn-action', l.active ? 'Desactivar' : 'Activar');
            tog.type = 'button'; tog.addEventListener('click', function () { act(l.active ? 'deactivate' : 'activate', c.campaign_id, l.list_id, l.active && l.started ? 'Se desactivará la lista «' + l.name + '» y se detendrá. ¿Continuar?' : ''); });
            ac.appendChild(tog);
            var go = el('button', 'btn-action', l.started ? 'Detener' : 'Iniciar'); go.type = 'button';
            if (l.started) go.addEventListener('click', function () { act('stop', c.campaign_id, l.list_id, ''); });
            else {
                if (!l.active) { go.disabled = true; go.title = 'Lista inactiva: actívala primero'; }
                go.addEventListener('click', function () { act('start', c.campaign_id, l.list_id, 'Al iniciar, el marcador empezará a llamar de verdad a los contactos de la lista «' + l.name + '». ¿Continuar?'); });
            }
            ac.appendChild(go); card.appendChild(ac);
            return card;
        }

        function render(d) {
            var s = d.server, sv = document.getElementById('server'); sv.textContent = '';
            sv.appendChild(metric('CPU', s.cpu_pct, ''));
            sv.appendChild(metric('Memoria', s.ram_pct, s.ram_total ? gb(s.ram_used) + ' de ' + gb(s.ram_total) : ''));
            sv.appendChild(metric('Disco', s.disk_pct, s.disk_total ? gb(s.disk_used) + ' de ' + gb(s.disk_total) : ''));
            if (d.dashboard) {
                var srv = d.dashboard.server, m = el('div', 'metric'); m.appendChild(el('div', 'metric-label', 'Llamadas en curso'));
                m.appendChild(el('div', 'metric-value', String(srv.open_calls))); m.appendChild(el('div', 'metric-sub', 'Máximo del servidor: ' + srv.max_channels)); sv.appendChild(m);
            }
            var sc = document.getElementById('services'); sc.textContent = '';
            (d.services || []).forEach(function (x) {
                var b = el('div', 'svc'); b.appendChild(el('span', 'dot ' + (x.on === null ? '' : x.on ? 'on' : 'off')));
                b.appendChild(el('span', '', x.label + ': ' + (x.on === null ? 'sin datos' : x.on ? 'encendido' : 'apagado'))); sc.appendChild(b);
            });
            var cs = document.getElementById('campaigns'); cs.textContent = '';
            if (!d.dashboard) { showNotice(d.dashboard_error || 'No se pudo leer el estado de las campañas.', 'dash-error', 'status'); return; }
            if (!d.dashboard.campaigns.length) cs.appendChild(el('p', 'metric-sub', 'Aún no hay campañas.'));
            d.dashboard.campaigns.forEach(function (c) {
                var box = el('div', 'card camp'), h = el('h2', '', c.name); h.appendChild(el('span', 'tag' + (c.started ? ' run' : ''), c.started ? 'En marcha' : 'Detenida'));
                if (!c.active) h.appendChild(el('span', 'tag', 'Inactiva')); box.appendChild(h);
                var ls = el('div', 'lists'); c.lists.forEach(function (l) { ls.appendChild(listCard(c, l)); });
                if (!c.lists.length) box.appendChild(el('p', 'metric-sub', 'Sin listas.')); else box.appendChild(ls);
                cs.appendChild(box);
            });
            var u = document.getElementById('updated'); u.textContent = '· ' + new Date().toLocaleTimeString();
        }

        function refresh() {
            if (busy) return; busy = true; clearTimeout(timer);
            fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (d) { showNotice('', '', 'status'); render(d); })
                .catch(function (e) { showNotice('No se pudo actualizar el panel: ' + e.message, 'dash-error', 'status'); })
                .then(function () { busy = false; timer = setTimeout(refresh, 5000); });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
        refresh();
    })();
    </script>
</body>
</html>

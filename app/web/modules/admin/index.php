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
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .gauge { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; text-align: center; }
        .gauge svg { width: 100%; max-width: 170px; height: auto; }
        .gauge .g-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .gauge .g-value { font-size: 1.4rem; font-weight: 700; margin-top: -0.4rem; }
        .gauge .g-sub { font-size: 0.72rem; color: var(--text-muted); }
        .services { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .svc { display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.7rem; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); font-size: 0.8rem; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: #9ca3af; }
        .dot.on { background: #10b981; } .dot.off { background: #ef4444; }
        .camp { margin-bottom: 1rem; }
        .camp h2 { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: baseline; }
        .tag { font-size: 0.65rem; padding: 1px 6px; border-radius: 2px; background: var(--glass); color: var(--text-muted); text-transform: uppercase; }
        .tag.run { background: #064e3b; color: #6ee7b7; }
        .lists { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 0.75rem; }
        .list-card { border: 1px solid var(--border); border-left: 5px solid #6b7280; border-radius: 2px; padding: 0.75rem; background: var(--bg-card); }
        .list-card.live { border-left-color: #10b981; }
        .list-head { display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .list-name { font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .pulse { width: 10px; height: 10px; border-radius: 50%; background: #6b7280; flex: none; }
        .live .pulse { background: #10b981; animation: pulse 1.4s ease-out infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.7); } 100% { box-shadow: 0 0 0 10px rgba(16,185,129,0); } }
        .temp { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin: 0.6rem 0; padding: 0.5rem 0.7rem; border-radius: 2px; color: #fff; background: #4b5563; }
        .temp .t-name { font-size: 1.15rem; font-weight: 800; letter-spacing: 0.03em; }
        .temp .t-advice { font-size: 0.8rem; font-weight: 700; background: rgba(0,0,0,.25); padding: 2px 8px; border-radius: 2px; }
        .temp.hot { background: #dc2626; } .temp.warm { background: #d97706; } .temp.cold { background: #2563eb; }
        .reason { font-size: 0.78rem; margin: 0.3rem 0; color: #fbbf24; }
        .reason.ok { color: #10b981; }
        .bar { height: 8px; background: var(--glass); border-radius: 4px; overflow: hidden; margin: 0.25rem 0; display: flex; }
        .bar > span { display: block; height: 100%; background: #10b981; }
        .bar.progress > span { background: #3b82f6; }
        .live .bar.progress > span { background-image: linear-gradient(45deg, rgba(255,255,255,.25) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.25) 50%, rgba(255,255,255,.25) 75%, transparent 75%); background-size: 16px 16px; animation: move 1s linear infinite; }
        @keyframes move { to { background-position: 16px 0; } }
        .rates { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin: 0.5rem 0; }
        .rate { font-size: 0.72rem; color: var(--text-muted); }
        .rate strong { font-size: 1.15rem; color: var(--text); }
        .legend { display: flex; flex-wrap: wrap; gap: 0.5rem; font-size: 0.68rem; color: var(--text-muted); }
        .legend i { display: inline-block; width: 9px; height: 9px; margin-right: 3px; border-radius: 2px; }
        .pace { font-size: 0.75rem; color: var(--text-muted); margin: 0.4rem 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.6rem; }
        .actions button:disabled { opacity: 0.45; cursor: not-allowed; }
        .foot { font-size: 0.68rem; color: var(--text-muted); margin-top: 0.8rem; }
        .dash-error { color: #fca5a5; margin-bottom: 1rem; }
        @media (max-width: 600px) { .lists { grid-template-columns: 1fr; } }
        @media (prefers-reduced-motion: reduce) { .live .pulse, .live .bar.progress > span { animation: none; } }
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
        var COLORS = { human: '#10b981', machine: '#6366f1', not_sure: '#a78bfa', unavailable: '#f59e0b', no_answer: '#9ca3af', failed: '#ef4444' };
        var NAMES = { human: 'Humano', machine: 'Contestadora', not_sure: 'No seguro', unavailable: 'No disponible', no_answer: 'Sin respuesta', failed: 'Fallidas' };
        var KEYS = ['human', 'machine', 'not_sure', 'unavailable', 'no_answer', 'failed'];
        var TEMP = { 'Caliente': 'hot', 'Tibia': 'warm', 'Fría': 'cold' };
        var SVGNS = 'http://www.w3.org/2000/svg';

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function gb(b) { return b == null ? '' : (b / 1073741824).toFixed(1) + ' GB'; }
        function showNotice(text, cls, id) { var n = document.getElementById(id || 'notice'); n.textContent = ''; if (text) n.appendChild(el('p', cls, text)); }

        // Medidor semicircular: verde < 75 %, ámbar < 90 %, rojo desde 90 %.
        function gauge(label, pct, valueText, sub) {
            var box = el('div', 'gauge'); box.appendChild(el('div', 'g-label', label));
            var svg = document.createElementNS(SVGNS, 'svg'); svg.setAttribute('viewBox', '0 0 120 70'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', label + ' ' + valueText);
            function arc(frac, color) {
                var a = Math.PI * (1 - frac), x = 60 + 50 * Math.cos(a), y = 62 - 50 * Math.sin(a);
                var p = document.createElementNS(SVGNS, 'path'); p.setAttribute('d', 'M10 62 A50 50 0 ' + (frac > 0.5 ? 1 : 0) + ' 1 ' + x.toFixed(2) + ' ' + y.toFixed(2));
                p.setAttribute('fill', 'none'); p.setAttribute('stroke', color); p.setAttribute('stroke-width', '10'); p.setAttribute('stroke-linecap', 'round'); return p;
            }
            svg.appendChild(arc(0.999, '#2d3748'));
            if (pct != null && pct > 0) svg.appendChild(arc(Math.min(0.999, pct / 100), pct >= 90 ? '#ef4444' : pct >= 75 ? '#f59e0b' : '#10b981'));
            box.appendChild(svg); box.appendChild(el('div', 'g-value', valueText)); if (sub) box.appendChild(el('div', 'g-sub', sub)); return box;
        }

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

        function stackBar(t) {
            var bar = el('div', 'bar'), total = Math.max(1, t.calls);
            KEYS.forEach(function (k) { var v = t[k] || 0; if (v) { var s = el('span'); s.style.width = (100 * v / total) + '%'; s.style.background = COLORS[k]; s.title = NAMES[k] + ': ' + v; bar.appendChild(s); } });
            return bar;
        }

        function listCard(c, l) {
            var ins = l.insight || {}, rates = ins.rates_pct || {}, pace = ins.pace || {}, live = l.started && !l.reason;
            var card = el('div', 'list-card' + (live ? ' live' : ''));
            var head = el('div', 'list-head'), nm = el('span', 'list-name'); nm.appendChild(el('span', 'pulse')); nm.appendChild(document.createTextNode(l.name + ' (#' + l.list_id + ')')); head.appendChild(nm);
            var tags = el('span'); tags.appendChild(el('span', 'tag' + (l.started ? ' run' : ''), live ? 'Discando' : l.started ? 'Esperando' : 'Detenida'));
            tags.appendChild(document.createTextNode(' ')); tags.appendChild(el('span', 'tag', l.active ? 'Activa' : 'Inactiva')); head.appendChild(tags); card.appendChild(head);

            var temp = el('div', 'temp ' + (TEMP[ins.temperature] || ''));
            temp.appendChild(el('span', 't-name', ins.temperature || 'Sin datos')); temp.appendChild(el('span', 't-advice', ins.recommendation || 'Seguir')); card.appendChild(temp);

            var why = l.reason || (l.started ? 'Marcando' : '');
            if (why) card.appendChild(el('div', 'reason' + (l.reason ? '' : ' ok'), why));

            var rt = el('div', 'rates');
            [['Contacto', rates.contact], ['Humano', rates.human]].forEach(function (x) { var d = el('div', 'rate', x[0]); d.insertBefore(el('strong', '', (x[1] == null ? 0 : x[1]) + ' %'), d.firstChild); d.insertBefore(el('br'), d.childNodes[1]); rt.appendChild(d); });
            card.appendChild(rt);

            card.appendChild(el('div', 'rate', 'Resultados (' + l.totals.calls + ' llamadas)')); card.appendChild(stackBar(l.totals));
            var lg = el('div', 'legend'); KEYS.forEach(function (k) { var s = el('span'); var i = el('i'); i.style.background = COLORS[k]; s.appendChild(i); s.appendChild(document.createTextNode(NAMES[k] + ' ' + (l.totals[k] || 0))); lg.appendChild(s); }); card.appendChild(lg);

            card.appendChild(el('div', 'pace', 'Ritmo: ' + (pace.calls_last_15min || 0) + ' llamadas en 15 min (' + (pace.calls_per_min || 0) + '/min) · Canales ' + l.open_calls + ' / ' + l.channels));
            var pb = el('div', 'bar progress'), pf = el('span'); pf.style.width = l.progress_pct + '%'; pb.appendChild(pf); card.appendChild(pb);
            card.appendChild(el('div', 'rate', 'Avance ' + l.progress_pct + ' % · ' + l.totals.not_called + ' de ' + l.totals.contacts + ' contactos sin llamar'));

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
            if (ins.thresholds_provisional && ins.thresholds) card.appendChild(el('div', 'foot', 'Umbrales provisionales: Caliente ≥ ' + ins.thresholds.hot_contact_pct + ' % de contacto, Tibia ≥ ' + ins.thresholds.warm_contact_pct + ' %, Fría por debajo; con menos de ' + ins.thresholds.min_calls + ' llamadas, Sin datos.'));
            return card;
        }

        function render(d) {
            var s = d.server, sv = document.getElementById('server'); sv.textContent = '';
            sv.appendChild(gauge('CPU', s.cpu_pct, s.cpu_pct == null ? '—' : s.cpu_pct + ' %', ''));
            sv.appendChild(gauge('Memoria', s.ram_pct, s.ram_pct == null ? '—' : s.ram_pct + ' %', s.ram_total ? gb(s.ram_used) + ' de ' + gb(s.ram_total) : ''));
            sv.appendChild(gauge('Disco', s.disk_pct, s.disk_pct == null ? '—' : s.disk_pct + ' %', s.disk_total ? gb(s.disk_used) + ' de ' + gb(s.disk_total) : ''));
            if (d.dashboard) {
                var srv = d.dashboard.server;
                sv.appendChild(gauge('Llamadas en curso', srv.max_channels ? Math.round(100 * srv.open_calls / srv.max_channels) : 0, srv.open_calls + ' / ' + srv.max_channels, 'canales del servidor'));
            }
            var sc = document.getElementById('services'); sc.textContent = '';
            (d.services || []).forEach(function (x) {
                var b = el('div', 'svc'); b.appendChild(el('span', 'dot ' + (x.on === null ? '' : x.on ? 'on' : 'off')));
                b.appendChild(el('span', '', x.label + ': ' + (x.on === null ? 'sin datos' : x.on ? 'encendido' : 'apagado'))); sc.appendChild(b);
            });
            var cs = document.getElementById('campaigns'); cs.textContent = '';
            if (!d.dashboard) { showNotice(d.dashboard_error || 'No se pudo leer el estado de las campañas.', 'dash-error', 'status'); return; }
            if (!d.dashboard.campaigns.length) cs.appendChild(el('p', 'g-sub', 'Aún no hay campañas.'));
            d.dashboard.campaigns.forEach(function (c) {
                var box = el('div', 'card camp'), h = el('h2', '', c.name); h.appendChild(el('span', 'tag' + (c.started ? ' run' : ''), c.started ? 'En marcha' : 'Detenida'));
                if (!c.active) h.appendChild(el('span', 'tag', 'Inactiva')); box.appendChild(h);
                var ls = el('div', 'lists'); c.lists.forEach(function (l) { ls.appendChild(listCard(c, l)); });
                if (!c.lists.length) box.appendChild(el('p', 'g-sub', 'Sin listas.')); else box.appendChild(ls);
                cs.appendChild(box);
            });
            document.getElementById('updated').textContent = '· ' + new Date().toLocaleTimeString();
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

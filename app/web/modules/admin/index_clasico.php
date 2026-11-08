<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

Auth::checkAccess(9); // respaldo del Dashboard anterior: solo Administrador, fuera del menú
$permNotice = \Includes\Permissions::takeNotice();
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
        .tile { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.9rem; }
        .tile .t-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .tile .t-value { font-size: 2rem; font-weight: 800; line-height: 1.15; margin: 0.25rem 0; color: #10b981; }
        .tile .t-value.neutral { color: var(--text); }
        .tile .t-value.warn { color: #f59e0b; } .tile .t-value.crit { color: #ef4444; }
        .tile .t-sub { font-size: 0.74rem; color: var(--text-muted); }
        .g-sub { font-size: 0.72rem; color: var(--text-muted); }
        .gauges { flex-basis: 100%; display: flex; justify-content: space-around; align-items: flex-end; gap: 0.5rem; } .gcol { text-align: center; min-width: 0; } .gcol .cap { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #4b5563; margin-bottom: 0.15rem; } .gcol.mini .needle-text { font-size: 0.72rem; }
        .needle-wrap { flex-basis: 100%; text-align: center; margin-top: 0.1rem; line-height: 0; } .needle-wrap .needle-text { line-height: 1.3; margin-top: 0.2rem; } .needle-wrap canvas { max-width: 100%; height: auto !important; }
        .needle-text { text-align: center; font-size: 0.8rem; font-weight: 600; }
        .services { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .svc { display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.7rem; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); font-size: 0.8rem; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: #9ca3af; }
        .dot.on { background: #10b981; } .dot.off { background: #ef4444; }
        #campaigns { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0.75rem; align-items: start; }
        #campaigns > p { grid-column: 1 / -1; }
        .camp { margin: 0; min-width: 0; }
        .camp h2 { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: baseline; }
        .tag { font-size: 0.65rem; padding: 1px 6px; border-radius: 2px; background: var(--glass); color: var(--text-muted); text-transform: uppercase; }
        .tag.run { background: #064e3b; color: #6ee7b7; }
        .lists { display: flex; flex-direction: column; gap: 0.75rem; }
        .list-card { min-width: 0; border: 1px solid var(--border); border-left: 5px solid #6b7280; border-radius: 2px; padding: 0.75rem; background: var(--bg-card); }
        .list-card.live { border-left-color: #10b981; }
        .list-head { display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .list-name { font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .pulse { width: 10px; height: 10px; border-radius: 50%; background: #6b7280; flex: none; }
        .live .pulse { background: #10b981; animation: pulse 1.4s ease-out infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.7); } 100% { box-shadow: 0 0 0 10px rgba(16,185,129,0); } }
        .temp { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.4rem; margin: 0.6rem 0; padding: 0.5rem 0.7rem; border-radius: 2px; color: var(--text); background: #fff; border: 1px solid #d1d5db; }
        .temp .t-name { font-size: 1.15rem; font-weight: 800; letter-spacing: 0.03em; color: #4b5563; }
        .temp .t-advice { font-size: 0.8rem; font-weight: 700; padding: 1px 8px; border-radius: 2px; border: 1px solid currentColor; color: #4b5563; }
        .temp.hot .t-name, .temp.hot .t-advice { color: #15803d; } .temp.warm .t-name, .temp.warm .t-advice { color: #b45309; } .temp.cold .t-name, .temp.cold .t-advice { color: #1d4ed8; }
        .reason { font-size: 0.78rem; margin: 0.3rem 0; color: #fbbf24; }
        .reason.ok { color: #10b981; }
        .bar { height: 8px; background: var(--glass); border-radius: 4px; overflow: hidden; margin: 0.25rem 0; display: flex; }
        .bar > span { display: block; height: 100%; background: #10b981; }
        .bar.progress > span { background: #3b82f6; }
        .live .bar.progress > span { background-image: linear-gradient(45deg, rgba(255,255,255,.25) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.25) 50%, rgba(255,255,255,.25) 75%, transparent 75%); background-size: 16px 16px; animation: move 1s linear infinite; }
        @keyframes move { to { background-position: 16px 0; } }
        .rate { font-size: 0.72rem; color: var(--text-muted); }
        .rate strong { color: var(--text); }
        .dist-title { font-size: 0.8rem; font-weight: 700; margin: 0.6rem 0 0.25rem; }
        .bar.dist { height: 14px; border-radius: 3px; }
        .dist-list { list-style: none; margin: 0.4rem 0; padding: 0; font-size: 0.78rem; }
        .dist-list li { display: grid; grid-template-columns: 12px 1fr auto auto; gap: 0.4rem; align-items: center; padding: 1px 0; }
        .dist-list i { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }
        .dist-list .n { font-weight: 700; text-align: right; min-width: 2.5rem; } .dist-list .p { color: var(--text-muted); text-align: right; min-width: 3.4rem; }
        .dist-list li.zero { opacity: 0.55; }
        .legend { display: flex; flex-wrap: wrap; gap: 0.5rem; font-size: 0.68rem; color: var(--text-muted); }
        .legend i { display: inline-block; width: 9px; height: 9px; margin-right: 3px; border-radius: 2px; }
        .pace { font-size: 0.75rem; color: var(--text-muted); margin: 0.4rem 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.6rem; }
        .actions button:disabled { opacity: 0.45; cursor: not-allowed; }
        .foot { font-size: 0.68rem; color: var(--text-muted); margin-top: 0.8rem; }
        .dash-error { color: #fca5a5; margin-bottom: 1rem; }
        .bar > span { transition: width 0.8s ease; }
        .live .needle-wrap canvas { border-radius: 50%; animation: halo 2.2s ease-out infinite; }
        @keyframes halo { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.5); } 100% { box-shadow: 0 0 0 14px rgba(16,185,129,0); } }
        .live .tag.run { animation: tagbeat 1.6s ease-in-out infinite; }
        @keyframes tagbeat { 0%, 100% { opacity: 1; } 50% { opacity: .55; } }
        .dist-list li { padding: 2px 4px; border-left: 4px solid transparent; border-radius: 2px; }
        .dist-list li.hot { background: #ecfdf5; border-left-color: #10b981; font-weight: 700; animation: hotbeat 2s ease-in-out infinite; }
        @keyframes hotbeat { 0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,.0); } 50% { box-shadow: 0 0 0 3px rgba(16,185,129,.35); } }
        .chan { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; font-size: 0.78rem; margin: 0.4rem 0; }
        .chan select { width: auto; min-width: 4.5rem; padding: 2px 6px; font-weight: 700; }
        @media (prefers-reduced-motion: reduce) { .live .pulse, .live .bar.progress > span, .live .needle-wrap canvas, .live .tag.run, .dist-list li.hot { animation: none; } .bar > span { transition: none; } }
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
        <div id="notice"><?php if ($permNotice !== ''): ?><p class="dash-error"><?php echo htmlspecialchars($permNotice); ?></p><?php endif; ?></div>
        <div id="status"></div>
        <section class="dash-grid" id="server" aria-label="Servidor"></section>
        <section class="services" id="services" aria-label="Servicios"></section>
        <section id="campaigns" aria-label="Campañas y listas"></section>
    </main>
    <script src="vendor/gauge.min.js"></script>
    <script src="channels_alert.js"></script>
    <script>
    (function () {
        var CSRF = <?php echo json_encode($_SESSION['dashboard_csrf']); ?>;
        var CAN = <?php echo json_encode(\Includes\Permissions::flags(['dash.start', 'dash.stop', 'dash.start_many', 'dash.set_channels', 'dash.deactivate'])); ?>; // lo que el perfil puede; el servidor lo vuelve a comprobar
        var API = 'dashboard_api.php';
        var busy = false, timer = null;
        // Categorías por contacto, en el orden y con los colores del contrato (Distribución por contacto).
        var CATS = [['humano', 'Humano', '#10b981'], ['casilla_voz', 'Casilla de voz', '#3b82f6'], ['no_seguro', 'No seguro', '#8b5cf6'], ['contestada_sin_amd', 'Contestada sin AMD', '#38bdf8'],
            ['no_disponible', 'No disponible', '#f59e0b'], ['sin_respuesta', 'Sin respuesta', '#6b7280'], ['ocupado', 'Ocupado', '#f97316'], ['errores', 'Errores', '#ef4444'], ['bloqueado', 'Bloqueado', '#111827'],
            ['en_llamada', 'En llamada', '#1e3a8a'], ['sin_llamar', 'Sin llamar', '#d1d5db']];
        var TEMP = { 'Caliente': 'hot', 'Tibia': 'warm', 'Fría': 'cold' };
        var SVGNS = 'http://www.w3.org/2000/svg';
        var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var PREV = {}; // último valor pintado por barra/número: los cambios se animan desde ahí, sin consultas nuevas
        // Barra: crece desde el ancho anterior (0 la primera vez) hasta el real, con la transición CSS.
        function growTo(sp, key, w) {
            var from = REDUCED ? w : (PREV[key] || 0); PREV[key] = w; sp.style.width = from + '%';
            if (from !== w) requestAnimationFrame(function () { requestAnimationFrame(function () { sp.style.width = w + '%'; }); });
        }
        // Número: cuenta suavemente desde el valor anterior (0 la primera vez) hasta el real.
        function countTo(node, key, to) {
            var from = REDUCED ? to : (PREV[key] || 0); PREV[key] = to; node.textContent = String(from);
            if (from === to) return;
            var t0 = null; function step(t) { if (t0 === null) t0 = t; var f = Math.min(1, (t - t0) / 600); node.textContent = String(Math.round(from + (to - from) * f)); if (f < 1) requestAnimationFrame(step); }
            requestAnimationFrame(step);
        }

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function gb(b) { return b == null ? '' : (b / 1073741824).toFixed(1) + ' GB'; }
        function showNotice(text, cls, id) { var n = document.getElementById(id || 'notice'); n.textContent = ''; if (text) n.appendChild(el('p', cls, text)); }

        // Cuadro de telemetría: el valor cambia de color a partir de 75 % (ámbar) y 90 % (rojo) si se pasa pct.
        function tile(label, pct, valueText, sub) {
            var box = el('div', 'tile'); box.appendChild(el('div', 't-label', label));
            box.appendChild(el('div', 't-value ' + (pct == null ? 'neutral' : pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : ''), valueText));
            if (sub) box.appendChild(el('div', 't-sub', sub)); return box;
        }

        // Medidor profesional (canvas-gauges, MIT, local) del % de humanos de la lista.
        // Zonas desde insight.thresholds: 0..warm azul, warm..hot ámbar, hot..2×hot verde (la derecha es mejor).
        // La instancia se conserva por list_id: en cada refresco solo se actualiza el valor, la aguja no vuelve a cero.
        var GAUGES = {};
        function gaugeOptions(ins, noData) {
            var th = ins.thresholds || {}, hot = n0(th.hot_human_pct), warm = n0(th.warm_human_pct), max = 2 * hot;
            var ticks = [0, warm, hot, (hot + max) / 2, max].filter(function (v, i, a) { return a.indexOf(v) === i; }).map(String);
            var hl = noData ? [{ from: 0, to: max, color: '#9ca3af' }] : [{ from: 0, to: warm, color: '#3b82f6' }, { from: warm, to: hot, color: '#f59e0b' }, { from: hot, to: max, color: '#16a34a' }];
            return { max: max, ticks: ticks, hl: hl };
        }
        // id = clave de la instancia (list_id o list_id + '_10'); size en px; text = texto bajo el medidor.
        function needleGauge(id, ins, noData, value, size, text) {
            noData = noData || !n0((ins.thresholds || {}).hot_human_pct); value = noData ? 0 : value;
            var o = gaugeOptions(ins, noData), key = JSON.stringify([o.max, o.ticks, o.hl, noData, size]), listId = id;
            var wrap = el('div', 'needle-wrap'), ent = GAUGES[listId];
            if (typeof RadialGauge === 'undefined') { wrap.appendChild(el('div', 'needle-text', 'No se pudo cargar el medidor.')); }
            else {
                if (ent && ent.key !== key) { try { ent.g.destroy(); } catch (e) {} ent = null; }
                if (!ent) {
                    var canvas = document.createElement('canvas');
                    var g = new RadialGauge({
                        renderTo: canvas, width: size, height: size, units: '% humanos', minValue: 0, maxValue: o.max, value: REDUCED ? value : 0, majorTicks: o.ticks, minorTicks: 5, strokeTicks: true,
                        highlights: o.hl, highlightsWidth: 12, startAngle: 60, ticksAngle: 240, valueBox: false, needle: !noData,
                        colorPlate: '#fdfdfd', colorPlateEnd: '#e6e8ec', colorMajorTicks: '#1f2937', colorMinorTicks: '#4b5563', colorNumbers: '#1f2937', colorUnits: '#4b5563',
                        borders: true, borderShadowWidth: 0, borderOuterWidth: 6, borderMiddleWidth: 3, borderInnerWidth: 2, colorBorderOuter: '#c9ced6', colorBorderOuterEnd: '#59616e', colorBorderMiddle: '#eef0f3', colorBorderMiddleEnd: '#8b93a0', colorBorderInner: '#fafafa', colorBorderInnerEnd: '#d7dbe1',
                        needleType: 'arrow', needleWidth: 3, needleStart: 0, needleEnd: 82, needleCircleSize: 9, needleCircleOuter: true, needleCircleInner: false, needleShadow: true, colorNeedle: '#dc2626', colorNeedleEnd: '#7f1d1d', colorNeedleShadowDown: 'rgba(0,0,0,.45)', colorNeedleCircleOuter: '#d1d5db', colorNeedleCircleOuterEnd: '#374151',
                        fontNumbersSize: 28, fontUnitsSize: 20, animation: !REDUCED, animationDuration: 600, animationRule: 'linear'
                    });
                    g.draw(); ent = GAUGES[listId] = { g: g, key: key, canvas: canvas, value: value };
                    if (!REDUCED && value) setTimeout(function () { g.value = value; }, 80); // al cargar, la aguja sube desde 0 hasta el valor real
                } else if (ent.value !== value) { ent.g.value = value; ent.value = value; }
                ent.seen = true; wrap.appendChild(ent.canvas);
            }
            wrap.appendChild(el('div', 'needle-text', text)); return wrap;
        }
        function sweepGauges() { Object.keys(GAUGES).forEach(function (k) { if (!GAUGES[k].seen) { try { GAUGES[k].g.destroy(); } catch (e) {} delete GAUGES[k]; } else GAUGES[k].seen = false; }); }
        function n0(v) { return Number(v) || 0; }

        function post(action, c, l, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, campaign_id: c, list_id: l, csrf: CSRF }, extra || {}));
            return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: body }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { var er = new Error(j.error || 'No se pudo completar la acción.'); er.channels = j.channels; throw er; }
            });
        }
        function act(action, c, l, confirmText, extra) {
            if (confirmText && !window.confirm(confirmText)) return;
            showNotice('', '');
            post(action, c, l, extra).then(refresh).catch(function (e) { if (e.channels) window.channelsAlert(e.message, e.channels); else showNotice(e.message, 'dash-error'); refresh(); });
        }

        function fpct(v) { return String(v).replace('.', ','); }
        function rpct(v, t) { return t > 0 ? (Math.round(1000 * v / t) / 10).toFixed(1) : '0.0'; } // porcentaje real redondeado, sin ajustar para cuadrar
        // Barra única: el 100 % son los contactos de la lista; cada contacto cuenta una vez, por su último resultado.
        function distBlock(card, d, id, hot) {
            card.appendChild(el('div', 'dist-title', 'Qué pasó con los ' + d.contacts + ' contactos'));
            var bar = el('div', 'bar dist'); bar.setAttribute('role', 'img'); bar.setAttribute('aria-label', 'Contactos por resultado');
            CATS.forEach(function (c) { var v = n0(d.counts[c[0]]); if (v) { var sp = el('span'); growTo(sp, id + ':w:' + c[0], 100 * v / d.contacts); sp.style.background = c[2]; sp.title = c[1] + ': ' + v + ' (' + fpct(rpct(v, d.contacts)) + ' %)'; bar.appendChild(sp); } });
            card.appendChild(bar);
            var ul = el('ul', 'dist-list');
            CATS.forEach(function (c) { var v = n0(d.counts[c[0]]); if (!v) return; var li = el('li', c[0] === 'humano' && hot ? 'hot' : ''), i = el('i'), nn = el('span', 'n'); i.style.background = c[2]; li.appendChild(i); li.appendChild(el('span', '', c[1])); countTo(nn, id + ':n:' + c[0], v); li.appendChild(nn); li.appendChild(el('span', 'p', fpct(rpct(v, d.contacts)) + ' %')); ul.appendChild(li); });
            card.appendChild(ul);
        }

        var srvMax = 0;
        function listCard(c, l) {
            var ins = l.insight || {}, rates = ins.rates_pct || {}, pace = ins.pace || {}, live = l.started && !l.reason;
            var card = el('div', 'list-card' + (live ? ' live' : ''));
            var head = el('div', 'list-head'), nm = el('span', 'list-name'); nm.appendChild(el('span', 'pulse')); nm.appendChild(document.createTextNode(l.name + ' (#' + l.list_id + ')')); head.appendChild(nm);
            var tags = el('span'); tags.appendChild(el('span', 'tag' + (l.started ? ' run' : ''), live ? 'Discando' : l.started ? 'Esperando' : 'Detenida'));
            tags.appendChild(document.createTextNode(' ')); tags.appendChild(el('span', 'tag', l.active ? 'Activa' : 'Inactiva')); head.appendChild(tags); card.appendChild(head);

            var temp = el('div', 'temp ' + (TEMP[ins.temperature] || ''));
            temp.appendChild(el('span', 't-name', ins.temperature || 'Sin datos')); temp.appendChild(el('span', 't-advice', ins.recommendation || 'Seguir'));
            var dist = l.distribution, nd = dist && dist.needle, l10 = l.last10, gs = el('div', 'gauges');
            var big = el('div', 'gcol'); big.appendChild(el('div', 'cap', 'Acumulado'));
            big.appendChild(needleGauge(l.list_id, ins, !nd || nd.no_data, nd ? n0(nd.pct) : 0, 124,
                !nd ? 'Calculando…' : nd.no_data ? 'Sin datos: ' + nd.called + ' de ' + nd.min_called + ' contactos llamados mínimos' : String(n0(nd.pct)).replace('.', ',') + ' % humanos · ' + nd.label)); gs.appendChild(big);
            var mini = el('div', 'gcol mini'); mini.appendChild(el('div', 'cap', 'Últimas ' + (l10 ? n0(l10.window) : 50) + ' llamadas'));
            var nd10 = !l10 || l10.no_data, h10 = l10 ? n0(l10.human) : 0;
            mini.appendChild(needleGauge(l.list_id + '_10', ins, nd10, l10 && l10.calls ? 100 * h10 / l10.calls : 0, 96,
                nd10 ? 'Sin datos' : h10 + (h10 === 1 ? ' humano' : ' humanos') + ' en las últimas ' + n0(l10.window))); gs.appendChild(mini);
            temp.appendChild(gs); card.appendChild(temp);

            var why = l.reason || (l.started ? 'Marcando' : '');
            if (why) card.appendChild(el('div', 'reason' + (l.reason ? '' : ' ok'), why));

            var th = ins.thresholds || {}, hot = !!(nd && !nd.no_data && n0(th.hot_human_pct) && n0(nd.pct) >= n0(th.hot_human_pct));
            if (dist) distBlock(card, dist, l.list_id, hot); else card.appendChild(el('div', 'g-sub', 'Calculando el resumen de contactos…'));

            card.appendChild(el('div', 'pace', 'Intentos: ' + l.totals.calls + ' en total · ' + (pace.calls_last_15min || 0) + ' intentos en 15 min (' + (pace.calls_per_min || 0) + '/min) · Canales ' + l.open_calls + ' / ' + l.channels));
            var called = dist ? dist.contacts - n0(dist.counts.sin_llamar) : 0, av = dist && dist.contacts ? Math.round(1000 * called / dist.contacts) / 10 : l.progress_pct;
            var pb = el('div', 'bar progress'), pf = el('span'); growTo(pf, l.list_id + ':av', av); pb.appendChild(pf); card.appendChild(pb);
            card.appendChild(el('div', 'rate', dist ? 'Avance: ' + called + ' de ' + dist.contacts + ' contactos ya llamados (' + fpct(av) + ' %)' : 'Avance ' + l.progress_pct + ' %'));

            var ch = el('div', 'chan'), sel = el('select'), maxc = Math.max(n0(srvMax), n0(l.channels), 1);
            sel.setAttribute('aria-label', 'Canales asignados a ' + l.name); sel.disabled = !CAN['dash.set_channels'];
            for (var k = 1; k <= maxc; k++) { var op = el('option', '', String(k)); op.value = k; sel.appendChild(op); }
            sel.value = l.channels; sel.addEventListener('change', function () { act('set_channels', c.campaign_id, l.list_id, '', { channels: sel.value }); });
            ch.appendChild(el('span', '', 'Canales asignados')); ch.appendChild(sel); ch.appendChild(el('span', 'g-sub', 'en uso ' + l.open_calls + ' de ' + l.channels + ' (máx. servidor ' + srvMax + ')')); card.appendChild(ch);

            var ac = el('div', 'actions');
            var go = el('button', 'btn-action', l.started ? 'Detener' : 'Iniciar'); go.type = 'button';
            if (l.started) go.addEventListener('click', function () { act('stop', c.campaign_id, l.list_id, ''); });
            else {
                if (!l.active) { go.disabled = true; go.title = 'Lista inactiva: actívala primero'; }
                go.addEventListener('click', function () { act('start', c.campaign_id, l.list_id, 'Al iniciar, el marcador empezará a llamar de verdad a los contactos de la lista «' + l.name + '». ¿Continuar?'); });
            }
            if (l.started ? CAN['dash.stop'] : CAN['dash.start']) ac.appendChild(go);
            var off = el('button', 'btn-action', 'Desactivar'); off.type = 'button';
            off.addEventListener('click', function () { act('deactivate', c.campaign_id, l.list_id, 'Se detiene la lista «' + l.name + '» y sale del Dashboard. Para volver a usarla, actívala en Listas. ¿Continuar?'); });
            if (CAN['dash.deactivate']) ac.appendChild(off);
            card.appendChild(ac);
            if (ins.thresholds_provisional && ins.thresholds) card.appendChild(el('div', 'foot', 'Umbrales provisionales (según % de humanos entre contactos llamados): Caliente ≥ ' + ins.thresholds.hot_human_pct + ' %, Tibia ≥ ' + ins.thresholds.warm_human_pct + ' %, Fría por debajo; con menos de ' + ins.thresholds.min_called_contacts + ' contactos llamados, Sin datos.'));
            return card;
        }

        function render(d) {
            var s = d.server, sv = document.getElementById('server'); sv.textContent = '';
            sv.appendChild(tile('CPU', s.cpu_pct, s.cpu_pct == null ? '—' : s.cpu_pct + ' %', 'uso del procesador'));
            sv.appendChild(tile('Memoria', s.ram_pct, s.ram_pct == null ? '—' : s.ram_pct + ' %', s.ram_total ? gb(s.ram_used) + ' de ' + gb(s.ram_total) : ''));
            sv.appendChild(tile('Disco', s.disk_pct, s.disk_pct == null ? '—' : s.disk_pct + ' %', s.disk_total ? gb(s.disk_used) + ' de ' + gb(s.disk_total) : ''));
            if (d.dashboard) {
                var srv = d.dashboard.server; srvMax = srv.max_channels;
                sv.appendChild(tile('Llamadas en curso', null, srv.open_calls + ' / ' + srv.max_channels, 'canales del servidor'));
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
                var act = c.lists.filter(function (l) { return l.active; });
                var ls = el('div', 'lists'); act.forEach(function (l) { ls.appendChild(listCard(c, l)); });
                if (!act.length) box.appendChild(el('p', 'g-sub', 'Sin listas activas. Actívalas en Listas.')); else box.appendChild(ls);
                cs.appendChild(box);
            });
            sweepGauges();
            document.getElementById('updated').textContent = '· ' + new Date().toLocaleTimeString();
        }

        function refresh() {
            if (busy) return; busy = true; clearTimeout(timer);
            fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (d) { showNotice('', '', 'status'); if (document.activeElement && document.activeElement.tagName === 'SELECT') return; render(d); })
                .catch(function (e) { showNotice('No se pudo actualizar el panel: ' + e.message, 'dash-error', 'status'); })
                .then(function () { busy = false; timer = setTimeout(refresh, 5000); });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
        refresh();
    })();
    </script>
</body>
</html>

<?php
declare(strict_types=1);
// Panel operativo compacto (propuesta para sustituir al Dashboard). Solo listas discando, agrupadas por campaña, 4 columnas.
// Reutiliza dashboard_api.php tal cual (mismos datos y acciones, refresco de 5 s). Sin SQL propio.
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
    <title>Panel operativo - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <script>try { if (localStorage.getItem('v2_hide_menu') === '1') document.documentElement.classList.add('nosb'); } catch (e) {}</script>
    <style>
        html.nosb .sidebar { display: none; } html.nosb .main-content { margin-left: 0; }
        .main-content { padding: 0.5rem 0.75rem; }
        .top { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 1rem; padding: 0.3rem 0.6rem; border: 1px solid var(--border); background: var(--bg-card); font-size: 0.78rem; margin-bottom: 0.4rem; }
        .top .m { display: flex; gap: 0.3rem; align-items: baseline; color: var(--text-muted); } .top .m b { color: var(--text); font-size: 0.9rem; }
        .top .m.warn b { color: #d97706; } .top .m.crit b { color: #dc2626; }
        .top .sv { display: flex; align-items: center; gap: 0.25rem; } .dot { width: 8px; height: 8px; border-radius: 50%; background: #9ca3af; display: inline-block; } .dot.on { background: #10b981; } .dot.off { background: #ef4444; }
        .top .sp { flex: 1; }
        .mini-btn { font-size: 0.72rem; padding: 2px 8px; border-radius: 2px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; }
        .mini-btn:hover { border-color: var(--primary); color: var(--primary); } .mini-btn:disabled { opacity: .45; cursor: not-allowed; }
        .camp-h { display: flex; align-items: center; gap: 0.5rem; margin: 0.35rem 0 0.2rem; padding-bottom: 1px; border-bottom: 1px solid var(--border); font-size: 0.76rem; font-weight: 800; text-transform: uppercase; }
        .tag { font-size: 0.6rem; padding: 0 5px; background: var(--glass); color: var(--text-muted); } .tag.run { background: #064e3b; color: #6ee7b7; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.35rem; }
        .cell { display: flex; flex-direction: column; gap: 0.18rem; min-width: 0; padding: 0.25rem 0.4rem; background: var(--bg-card); border: 1px solid var(--border); border-left: 4px solid #6b7280; border-radius: 2px; }
        .cell.live { border-left-color: #10b981; } .cell.wait { border-left-color: #f59e0b; }
        .cell.hot { background: #ecfdf5; border-color: #6ee7b7; animation: hotbeat 2.2s ease-in-out infinite; }
        .cell.free { border: 1px dashed #cbd5e1; border-left: 1px dashed #cbd5e1; background: transparent; color: #9ca3af; align-items: center; justify-content: center; font-size: 0.72rem; min-height: 110px; }
        @keyframes hotbeat { 0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } 50% { box-shadow: 0 0 0 3px rgba(16,185,129,.35); } }
        .ch { display: flex; align-items: center; gap: 0.3rem; min-width: 0; }
        .ch .pulse { width: 8px; height: 8px; border-radius: 50%; background: #6b7280; flex: none; } .live .pulse { background: #10b981; animation: pulse 1.4s ease-out infinite; } .wait .pulse { background: #f59e0b; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.7); } 100% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } }
        .ch a.nm { font-weight: 800; font-size: 0.8rem; color: var(--text); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; } .ch a.nm:hover { text-decoration: underline; color: var(--primary); }
        .ch .sp { flex: 1; }
        .body { display: flex; align-items: center; gap: 0.35rem; }
        .gz { display: flex; gap: 0.2rem; flex: none; } .g { text-align: center; line-height: 0; } .g .cap { font-size: 0.56rem; text-transform: uppercase; font-weight: 700; color: #4b5563; line-height: 1.15; margin-bottom: 1px; } .g canvas { max-width: 100%; height: auto !important; }
        .info { flex: 1; min-width: 0; font-size: 0.72rem; display: flex; flex-direction: column; gap: 0.12rem; }
        .info .big { font-size: 0.95rem; font-weight: 800; line-height: 1.1; } .info .mut { color: var(--text-muted); }
        .info select { width: auto; min-width: 3.6rem; padding: 0 4px; font-size: 0.72rem; font-weight: 700; }
        .bar { height: 10px; display: flex; background: var(--glass); border-radius: 2px; overflow: hidden; } .bar span { display: block; height: 100%; transition: width .8s ease; }
        .empty { padding: 2rem 1rem; text-align: center; color: var(--text-muted); border: 1px dashed #cbd5e1; margin-top: 0.4rem; }
        #stpanel { margin-bottom: 0.4rem; padding: 0.3rem 0.6rem; border: 1px solid var(--border); background: var(--bg-card); font-size: 0.78rem; } #stpanel[hidden] { display: none; }
        #stpanel .row { display: flex; align-items: center; gap: 0.6rem; padding: 2px 0; border-bottom: 1px solid var(--border); } #stpanel .row:last-child { border-bottom: 0; } #stpanel .row .sp { flex: 1; }
        .dash-error { color: #dc2626; margin: 0.3rem 0; }
        @media (max-width: 1600px) { html:not(.nosb) .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } } /* con el menú abierto y poco ancho, 3 columnas para que se lea */
        @media (max-width: 1600px) { .ch .mini-btn { padding: 0 5px; font-size: 0.66rem; } .ch .tag { display: none; } .ch a.nm { font-size: 0.76rem; } } /* a poco ancho el nombre gana sitio; el estado sigue en el color del borde y en el tooltip */
        @media (prefers-reduced-motion: reduce) { .live .pulse, .cell.hot, .live .g canvas { animation: none; } .bar span { transition: none; } }
        .live .g canvas { border-radius: 50%; animation: halo 2.2s ease-out infinite; }
        @keyframes halo { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.45); } 100% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } }
    </style>
</head>
<body>
    <?php renderSidebar(''); ?>
    <main class="main-content">
        <div class="top" id="top">
            <button type="button" class="mini-btn" id="menubtn">Esconder menú</button>
            <span id="tele" style="display: contents;"></span>
            <span class="sp"></span>
            <span id="svcs" style="display: contents;"></span>
            <button type="button" class="mini-btn" id="stbtn" aria-expanded="false">Listas detenidas (0)</button>
            <span class="m" id="updated"></span>
        </div>
        <div id="notice"></div>
        <div id="stpanel" hidden></div>
        <div id="groups"></div>
    </main>
    <script src="vendor/gauge.min.js"></script>
    <script>
    (function () {
        var CSRF = <?php echo json_encode($_SESSION['dashboard_csrf']); ?>;
        var API = 'dashboard_api.php';
        var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var CATS = [['humano', 'Humano', '#10b981'], ['casilla_voz', 'Casilla de voz', '#3b82f6'], ['no_seguro', 'No seguro', '#8b5cf6'], ['contestada_sin_amd', 'Contestada sin AMD', '#38bdf8'],
            ['no_disponible', 'No disponible', '#f59e0b'], ['sin_respuesta', 'Sin respuesta', '#6b7280'], ['ocupado', 'Ocupado', '#f97316'], ['errores', 'Errores', '#ef4444'], ['bloqueado', 'Bloqueado', '#111827'],
            ['en_llamada', 'En llamada', '#1e3a8a'], ['sin_llamar', 'Sin llamar', '#d1d5db']];
        var COLS = 4, GSIZE = 80;
        var busy = false, timer = null, srvMax = 0, structSig = null, stSig = '', cards = {};

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function n0(v) { return Number(v) || 0; }
        function fx(v) { return String(v).replace('.', ','); }
        function rp(v, t) { return t > 0 ? (Math.round(1000 * v / t) / 10).toFixed(1) : '0.0'; }
        function gb(b) { return b == null ? '' : (b / 1073741824).toFixed(1) + ' GB'; }
        function showNotice(text) { var n = document.getElementById('notice'); n.textContent = ''; if (text) n.appendChild(el('p', 'dash-error', text)); }
        function anySelectFocused() { var a = document.activeElement; return !!(a && a.tagName === 'SELECT' && document.getElementById('groups').contains(a)); }

        // ---- menú lateral: se esconde solo en esta página y se recuerda ----
        var menuBtn = document.getElementById('menubtn');
        function paintMenuBtn() { menuBtn.textContent = document.documentElement.classList.contains('nosb') ? 'Mostrar menú' : 'Esconder menú'; }
        menuBtn.addEventListener('click', function () { var on = document.documentElement.classList.toggle('nosb'); try { localStorage.setItem('v2_hide_menu', on ? '1' : '0'); } catch (e) {} paintMenuBtn(); });
        paintMenuBtn();

        // ---- acciones (mismas de dashboard_api.php) ----
        function post(action, c, l, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, campaign_id: c, list_id: l, csrf: CSRF }, extra || {}));
            return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: body }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'No se pudo completar la acción.'); });
        }
        function act(action, c, l, confirmText, extra) {
            if (confirmText && !window.confirm(confirmText)) return;
            showNotice('');
            post(action, c, l, extra).then(refresh).catch(function (e) { showNotice(e.message); refresh(true); });
        }

        // ---- agujas (canvas-gauges, mismo modelo que el Dashboard); una instancia por aguja, solo se actualiza el valor ----
        function gaugeConf(ins, noData) {
            var th = ins.thresholds || {}, hot = n0(th.hot_human_pct), warm = n0(th.warm_human_pct), max = 2 * hot;
            var ticks = [0, warm, hot, (hot + max) / 2, max].filter(function (v, i, a) { return a.indexOf(v) === i; }).map(String);
            var hl = noData ? [{ from: 0, to: max, color: '#9ca3af' }] : [{ from: 0, to: warm, color: '#3b82f6' }, { from: warm, to: hot, color: '#f59e0b' }, { from: hot, to: max, color: '#16a34a' }];
            return { max: max, ticks: ticks, hl: hl };
        }
        function Gauge(box) {
            var st = null;
            this.set = function (ins, noData, value) {
                if (typeof RadialGauge === 'undefined') return;
                noData = noData || !n0((ins.thresholds || {}).hot_human_pct); value = noData ? 0 : value;
                var o = gaugeConf(ins, noData), key = JSON.stringify([o.max, o.ticks, o.hl, noData]);
                if (st && st.key !== key) { try { st.g.destroy(); } catch (e) {} box.textContent = ''; st = null; }
                if (!st) {
                    var cv = document.createElement('canvas'); box.appendChild(cv);
                    var g = new RadialGauge({
                        renderTo: cv, width: GSIZE, height: GSIZE, units: '', minValue: 0, maxValue: o.max, value: REDUCED ? value : 0, majorTicks: o.ticks.map(function () { return ''; }), minorTicks: 5, strokeTicks: true,
                        highlights: o.hl, highlightsWidth: 12, startAngle: 60, ticksAngle: 240, valueBox: false, needle: !noData,
                        colorPlate: '#fdfdfd', colorPlateEnd: '#e6e8ec', colorMajorTicks: '#1f2937', colorMinorTicks: '#4b5563', colorNumbers: '#1f2937', colorUnits: '#4b5563',
                        borders: true, borderShadowWidth: 0, borderOuterWidth: 6, borderMiddleWidth: 3, borderInnerWidth: 2, colorBorderOuter: '#c9ced6', colorBorderOuterEnd: '#59616e', colorBorderMiddle: '#eef0f3', colorBorderMiddleEnd: '#8b93a0', colorBorderInner: '#fafafa', colorBorderInnerEnd: '#d7dbe1',
                        needleType: 'arrow', needleWidth: 3, needleStart: 0, needleEnd: 82, needleCircleSize: 9, needleCircleOuter: true, needleCircleInner: false, needleShadow: true, colorNeedle: '#dc2626', colorNeedleEnd: '#7f1d1d', colorNeedleShadowDown: 'rgba(0,0,0,.45)', colorNeedleCircleOuter: '#d1d5db', colorNeedleCircleOuterEnd: '#374151',
                        fontNumbersSize: 28, fontUnitsSize: 20, animation: !REDUCED, animationDuration: 600, animationRule: 'linear'
                    });
                    g.draw(); st = { g: g, key: key, value: value };
                    if (!REDUCED && value) setTimeout(function () { g.value = value; }, 80); // al cargar sube desde 0 hasta el valor real
                } else if (st.value !== value) { st.g.value = value; st.value = value; }
            };
            this.destroy = function () { if (st) { try { st.g.destroy(); } catch (e) {} st = null; } };
        }

        // ---- un cuadro por lista: se crea una vez y se actualiza en el sitio ----
        function mkCard(c, l) {
            var r = { id: l.list_id, c: c }, root = el('div', 'cell'); r.root = root;
            var head = el('div', 'ch'); head.appendChild(el('span', 'pulse'));
            r.nm = el('a', 'nm'); head.appendChild(r.nm); r.tag = el('span', 'tag'); head.appendChild(r.tag); head.appendChild(el('span', 'sp'));
            r.stop = el('button', 'mini-btn', 'Detener'); r.stop.type = 'button'; head.appendChild(r.stop);
            r.off = el('button', 'mini-btn', 'Desactivar'); r.off.type = 'button'; head.appendChild(r.off); root.appendChild(head);
            var body = el('div', 'body'), gz = el('div', 'gz'); r.gboxA = el('div', 'g'); r.gboxB = el('div', 'g');
            var capA = el('div', 'cap', 'Acumulado'), capB = el('div', 'cap', 'Últimas 50'); r.capB = capB;
            var ga = el('div'), gbx = el('div'); r.gA = new Gauge(ga); r.gB = new Gauge(gbx);
            r.gboxA.appendChild(capA); r.gboxA.appendChild(ga); r.gboxB.appendChild(capB); r.gboxB.appendChild(gbx); gz.appendChild(r.gboxA); gz.appendChild(r.gboxB); body.appendChild(gz);
            var info = el('div', 'info'); r.big = el('div', 'big'); r.l50 = el('div'); r.adv = el('div', 'mut');
            var chn = el('div'); chn.appendChild(document.createTextNode('Canales ')); r.sel = el('select'); chn.appendChild(r.sel); r.use = el('span', 'mut'); chn.appendChild(r.use);
            info.appendChild(r.big); info.appendChild(r.l50); info.appendChild(r.adv); info.appendChild(chn); body.appendChild(info); root.appendChild(body);
            r.bar = el('div', 'bar'); r.bar.setAttribute('role', 'img'); r.segs = {};
            CATS.forEach(function (k) { var s = el('span'); s.style.background = k[2]; s.style.width = '0%'; r.segs[k[0]] = s; r.bar.appendChild(s); }); root.appendChild(r.bar);
            r.sel.addEventListener('change', function () { act('set_channels', r.c.campaign_id, r.id, '', { channels: r.sel.value }); });
            return r;
        }
        function updateCard(r, c, l) {
            r.c = c; var ins = l.insight || {}, dist = l.distribution, nd = dist && dist.needle, l10 = l.last10, live = l.started && !l.reason;
            r.root.className = 'cell ' + (live ? 'live' : 'wait');
            var th = ins.thresholds || {}, hot = !!(nd && !nd.no_data && n0(th.hot_human_pct) && n0(nd.pct) >= n0(th.hot_human_pct));
            if (hot) r.root.classList.add('hot');
            r.nm.textContent = l.name; r.nm.title = l.name + ' · ' + (live ? 'discando' : (why || 'esperando')) + ' · ver reporte'; r.nm.href = 'reports.php?campaign_id=' + encodeURIComponent(c.campaign_id) + '&list_id=' + encodeURIComponent(l.list_id);
            var why = l.reason || ''; r.tag.textContent = live ? 'Discando' : (/reintentos/i.test(why) ? 'Reintentos' : (why && why.length < 20 ? why : 'Esperando')); r.tag.title = why || 'Marcando'; r.tag.className = 'tag' + (live ? ' run' : '');
            r.stop.onclick = function () { act('stop', r.c.campaign_id, r.id, 'Se detendrá la lista «' + l.name + '». Las llamadas en curso terminan solas. ¿Continuar?'); };
            r.off.onclick = function () { act('deactivate', r.c.campaign_id, r.id, 'Se detiene la lista «' + l.name + '» y sale del panel. Para volver a usarla, actívala en Listas. ¿Continuar?'); };
            var noData = !nd || nd.no_data;
            r.gA.set(ins, noData, nd ? n0(nd.pct) : 0);
            var nd10 = !l10 || l10.no_data, w = l10 ? n0(l10.window) || 50 : 50;
            r.capB.textContent = 'Últimas ' + w;
            r.gB.set(ins, nd10, l10 && l10.calls ? 100 * n0(l10.human) / l10.calls : 0);
            r.big.textContent = !nd ? 'Calculando…' : nd.no_data ? 'Sin datos' : fx(n0(nd.pct)) + ' % humanos';
            r.l50.textContent = nd10 ? 'Últimas ' + w + ': sin datos' : n0(l10.human) + (n0(l10.human) === 1 ? ' humano' : ' humanos') + ' en las últimas ' + w;
            var called = dist ? dist.contacts - n0(dist.counts.sin_llamar) : 0;
            r.adv.textContent = dist ? 'Avance ' + called + ' / ' + dist.contacts : 'Avance ' + n0(l.progress_pct) + ' %';
            if (!anySelectFocused()) {
                var maxc = Math.max(n0(srvMax), n0(l.channels), 1);
                if (r.sel.options.length !== maxc) { r.sel.textContent = ''; for (var k = 1; k <= maxc; k++) { var op = el('option', '', String(k)); op.value = k; r.sel.appendChild(op); } }
                r.sel.value = l.channels;
            }
            r.sel.setAttribute('aria-label', 'Canales asignados a ' + l.name);
            r.use.textContent = ' · en uso ' + n0(l.open_calls) + '/' + n0(l.channels);
            CATS.forEach(function (k) {
                var v = dist ? n0(dist.counts[k[0]]) : 0, s = r.segs[k[0]]; s.style.width = (dist && dist.contacts ? 100 * v / dist.contacts : 0) + '%';
                s.title = dist ? k[1] + ': ' + v + ' (' + fx(rp(v, dist.contacts)) + ' %)' : '';
            });
        }
        function destroyCard(r) { r.gA.destroy(); r.gB.destroy(); }

        // ---- listas detenidas (activas): panel corto con Iniciar ----
        var stBtn = document.getElementById('stbtn'), stPanel = document.getElementById('stpanel');
        stBtn.addEventListener('click', function () { stPanel.hidden = !stPanel.hidden; stBtn.setAttribute('aria-expanded', String(!stPanel.hidden)); });
        function renderStopped(list) {
            stBtn.textContent = 'Listas detenidas (' + list.length + ')';
            var sig = list.map(function (x) { return x.c.campaign_id + ':' + x.l.list_id + ':' + x.l.name; }).join('|');
            if (sig === stSig) return; stSig = sig; stPanel.textContent = '';
            if (!list.length) { stPanel.appendChild(el('span', 'mut', 'No hay listas activas detenidas.')); return; }
            list.forEach(function (x) {
                var row = el('div', 'row'); row.appendChild(el('b', '', x.c.name)); row.appendChild(el('span', '', x.l.name + ' (#' + x.l.list_id + ')')); row.appendChild(el('span', 'sp'));
                var go = el('button', 'mini-btn', 'Iniciar'); go.type = 'button';
                go.addEventListener('click', function () { act('start', x.c.campaign_id, x.l.list_id, 'Al iniciar, el marcador empezará a llamar de verdad a los contactos de la lista «' + x.l.name + '». ¿Continuar?'); });
                row.appendChild(go); stPanel.appendChild(row);
            });
        }

        function telemetry(d) {
            var s = d.server, t = document.getElementById('tele'); t.textContent = '';
            function m(label, pct, text) { var e = el('span', 'm' + (pct == null ? '' : pct >= 90 ? ' crit' : pct >= 75 ? ' warn' : '')); e.appendChild(document.createTextNode(label + ' ')); e.appendChild(el('b', '', text)); t.appendChild(e); }
            m('CPU', s.cpu_pct, s.cpu_pct == null ? '—' : s.cpu_pct + ' %'); m('Memoria', s.ram_pct, s.ram_pct == null ? '—' : s.ram_pct + ' %'); m('Disco', s.disk_pct, s.disk_pct == null ? '—' : s.disk_pct + ' %');
            if (d.dashboard) m('Llamadas en curso', null, d.dashboard.server.open_calls + ' / ' + d.dashboard.server.max_channels);
            var sv = document.getElementById('svcs'); sv.textContent = '';
            (d.services || []).forEach(function (x) { var b = el('span', 'sv'); b.appendChild(el('span', 'dot ' + (x.on === null ? '' : x.on ? 'on' : 'off'))); b.appendChild(document.createTextNode(x.label)); b.title = x.label + ': ' + (x.on === null ? 'sin datos' : x.on ? 'encendido' : 'apagado'); sv.appendChild(b); });
        }

        function render(d) {
            telemetry(d);
            if (!d.dashboard) { showNotice(d.dashboard_error || 'No se pudo leer el estado de las campañas.'); return; }
            srvMax = d.dashboard.server.max_channels;
            var groups = [], stopped = [], seen = {};
            d.dashboard.campaigns.forEach(function (c) {
                var live = c.lists.filter(function (l) { return l.active && l.started; });
                c.lists.forEach(function (l) { if (l.active && !l.started) stopped.push({ c: c, l: l }); });
                if (live.length) groups.push({ c: c, lists: live });
            });
            groups.forEach(function (g) { g.lists.forEach(function (l) { seen[l.list_id] = true; if (!cards[l.list_id]) cards[l.list_id] = mkCard(g.c, l); updateCard(cards[l.list_id], g.c, l); }); });
            Object.keys(cards).forEach(function (id) { if (!seen[id]) { destroyCard(cards[id]); delete cards[id]; } });
            var sig = groups.map(function (g) { return g.c.campaign_id + '[' + g.lists.map(function (l) { return l.list_id; }).join(',') + ']:' + g.c.started; }).join('|');
            var box = document.getElementById('groups');
            if (sig !== structSig) {
                structSig = sig; box.textContent = '';
                if (!groups.length) {
                    var em = el('div', 'empty'); em.appendChild(el('p', '', 'Ninguna lista está discando ahora.')); var b = el('button', 'mini-btn', 'Ver listas detenidas (' + stopped.length + ')'); b.type = 'button';
                    b.addEventListener('click', function () { stPanel.hidden = false; stBtn.setAttribute('aria-expanded', 'true'); }); em.appendChild(b); box.appendChild(em);
                }
                groups.forEach(function (g) {
                    var h = el('div', 'camp-h', g.c.name); h.appendChild(el('span', 'tag' + (g.c.started ? ' run' : ''), g.c.started ? 'En marcha' : 'Detenida')); box.appendChild(h);
                    var grid = el('div', 'grid'); g.lists.forEach(function (l) { grid.appendChild(cards[l.list_id].root); });
                    for (var i = (COLS - g.lists.length % COLS) % COLS; i > 0; i--) grid.appendChild(el('div', 'cell free', 'Libre'));
                    box.appendChild(grid);
                });
            }
            renderStopped(stopped);
            document.getElementById('updated').textContent = new Date().toLocaleTimeString();
        }

        function refresh(force) {
            if (busy) return; busy = true; clearTimeout(timer);
            fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (d) { showNotice(''); if (anySelectFocused() && force !== true) return; render(d); })
                .catch(function (e) { showNotice('No se pudo actualizar el panel: ' + e.message); })
                .then(function () { busy = false; timer = setTimeout(refresh, 5000); });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
        refresh();
    })();
    </script>
</body>
</html>

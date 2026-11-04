<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

Auth::checkAccess(9);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-bottom: 0.5rem; }
        .filters label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .filters input, .filters select { min-width: 9rem; }
        .quick { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1rem; }
        .quick button { font-size: 0.75rem; }
        .sec { margin: 1.4rem 0 0.6rem; font-size: 1rem; font-weight: 700; border-bottom: 2px solid var(--border); padding-bottom: 0.3rem; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .kpi { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; border-top: 3px solid var(--kc, #6b7280); }
        .kpi .l { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .kpi .v { font-size: 1.7rem; font-weight: 700; margin-top: 0.2rem; }
        .kpi .b { font-size: 0.72rem; color: var(--text-muted); }
        .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem; }
        .panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; min-width: 0; }
        .panel h2, .panel h3 { font-size: 0.85rem; margin: 0 0 0.2rem; }
        .panel .sub { font-size: 0.72rem; color: var(--text-muted); margin: 0 0 0.6rem; }
        .wide { grid-column: 1 / -1; }
        .hbar { display: grid; grid-template-columns: 11rem 1fr 11rem; gap: 0.5rem; align-items: center; font-size: 0.78rem; margin: 0.25rem 0; }
        .hbar .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hbar .track { background: var(--glass); height: 14px; border-radius: 2px; overflow: hidden; display: flex; }
        .hbar .track span { height: 100%; }
        .hbar .val { font-size: 0.74rem; color: var(--text-muted); }
        .hbar.small .track span { opacity: 0.35; }
        .tag-small { font-size: 0.62rem; background: #78350f; color: #fde68a; padding: 0 5px; border-radius: 2px; margin-left: 4px; }
        .legend { display: flex; flex-wrap: wrap; gap: 0.6rem; font-size: 0.7rem; color: var(--text-muted); margin-top: 0.5rem; }
        .legend i { display: inline-block; width: 10px; height: 10px; margin-right: 4px; border-radius: 2px; }
        .scroll { overflow-x: auto; }
        .pager { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.6rem; font-size: 0.8rem; }
        .rep-error { color: #fca5a5; margin-bottom: 0.5rem; }
        .notes p { font-size: 0.76rem; color: #fbbf24; margin: 0.15rem 0; }
        .muted { color: var(--text-muted); font-size: 0.75rem; }
        table.matrix { border-collapse: separate; border-spacing: 2px; font-size: 0.68rem; }
        table.matrix th { font-weight: 600; color: var(--text-muted); padding: 2px 4px; text-align: center; }
        table.matrix td { min-width: 34px; height: 30px; text-align: center; border-radius: 2px; color: #fff; }
        table.matrix td.empty { background: var(--glass); color: transparent; }
        table.matrix td.low { opacity: 0.35; }
        @media (max-width: 600px) { .panels { grid-template-columns: 1fr; } .hbar { grid-template-columns: 6.5rem 1fr 6.5rem; } }
    </style>
</head>
<body>
    <?php renderSidebar('reports'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Reportes</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Resultados de llamadas para decidir · horas en Lima · «contacto humano» = el AMD detectó una persona</p>
            </div>
        </header>
        <form class="filters" id="filters">
            <label>Desde <input type="date" name="from"></label>
            <label>Hasta <input type="date" name="to"></label>
            <label>Campaña <select name="campaign_id" id="f_campaign"><option value="">Todas</option></select></label>
            <label>Lista <select name="list_id" id="f_list"><option value="">Todas</option></select></label>
            <label>Estado de la lista <select name="status"><option value="">Todas</option><option value="running">En curso</option><option value="stopped">Detenida</option><option value="finished">Terminada</option><option value="deleted">Eliminada</option></select></label>
            <button type="submit" class="btn-primary">Aplicar</button>
            <a class="btn-action" id="csv" href="#">Exportar CSV</a>
        </form>
        <div class="quick" id="quick"></div>
        <div id="error"></div>
        <div class="notes" id="notes"></div>

        <h2 class="sec">A · Resumen</h2>
        <section class="kpis" id="kpis"></section>
        <section class="panels">
            <div class="panel wide"><h3>Tendencia diaria</h3><p class="sub">Línea: % de llamadas contestadas y % con contacto humano (escala desde 0). Los puntos vacíos tienen muestra pequeña. Abajo, las llamadas de cada día.</p><div id="trend"></div></div>
            <div class="panel"><h3>Comparativa de listas</h3><p class="sub">% de contacto humano sobre las llamadas de cada lista, en el rango elegido. Con «muestra pequeña» no se concluye.</p><div id="cmplist"></div></div>
            <div class="panel"><h3>Comparativa de campañas</h3><p class="sub">Mismo cálculo, sumando las listas de cada campaña.</p><div id="cmpcamp"></div></div>
            <div class="panel wide"><h3>Resumen acumulado por lista</h3><p class="sub">Desde que la lista empezó a marcar (no depende del rango de fechas). Estado y totales de cada lista.</p><div class="scroll" id="sumtable"></div></div>
        </section>

        <h2 class="sec">B · Diagnóstico</h2>
        <section class="panels">
            <div class="panel"><h3>Embudo hasta el contacto</h3><p class="sub">Cada barra es el total de esa etapa; el % es sobre la etapa anterior. No es un embudo estricto: una contestadora no pasa a humano.</p><div id="funnel"></div></div>
            <div class="panel"><h3>Causas SIP por familia</h3><p class="sub">Barras ordenadas por número de llamadas.</p><div id="sip"></div><div class="scroll" id="sipcodes"></div></div>
            <div class="panel"><h3>Salud del AMD</h3><p class="sub">Resultado del AMD y su causa, por separado.</p><div id="amd"></div></div>
            <div class="panel"><h3>Transferencias</h3><p class="sub">Contacto humano detectado y transferencia completada o fallida.</p><div id="xfer"></div></div>
            <div class="panel wide" id="carrierbox" hidden><h3>Por troncal</h3><div class="scroll" id="carrier"></div></div>
        </section>

        <h2 class="sec">C · Tipos de marcación</h2>
        <section class="panels">
            <div class="panel wide"><h3>Solo AMD frente a Zypad</h3>
                <p class="sub">Compara cada tipo con su N. En «sombra», Zypad escucha antes de contestar pero no corta: decide el AMD. «Tiempo ahorrado» es lo que el corte habría adelantado frente al momento de contestar, solo en llamadas donde Zypad oyó una palabra antes de contestar. Sin metas ni umbrales: son datos para comparar.</p>
                <div class="scroll" id="dialmodes"></div></div>
        </section>

        <h2 class="sec">D · Acción</h2>
        <section class="panels">
            <div class="panel wide"><h3>¿A qué hora conviene llamar?</h3><p class="sub">% de llamadas contestadas por día de la semana y hora (Lima). Más oscuro = más contestadas. Las celdas apagadas tienen menos de <span id="minn"></span> llamadas: no concluyas con ellas.</p><div class="scroll" id="matrix"></div></div>
            <div class="panel"><h3>Intentos por contacto</h3><p class="sub">Qué parte de los contactos de cada lista se llamó 1, 2, 3 o 4+ veces (100 % por lista).</p><div id="attempts"></div></div>
            <div class="panel"><h3>¿Vale la pena otro reintento?</h3><p class="sub">% acumulado de contactos alcanzados (contestó) según el número de intento. Si la curva casi no sube, el siguiente reintento rinde poco.</p><div id="cumul"></div></div>
            <div class="panel wide"><h3>Detalle de llamadas</h3><div class="scroll" id="detail"></div>
                <div class="pager"><button type="button" class="btn-action" id="prev">Anterior</button><span id="pageinfo"></span><button type="button" class="btn-action" id="next">Siguiente</button></div></div>
        </section>
    </main>
    <script>
    (function () {
        var API = 'reports_api.php', page = 1, lastPages = 1, optionsLoaded = false, options = [];
        var MIN = 20; // muestra mínima por celda/lista; criterio de MarcadorBuilder
        var C = { human: '#10b981', machine: '#6366f1', not_sure: '#a78bfa', unavailable: '#f59e0b', no_answer: '#9ca3af', failed: '#ef4444', answered: '#3b82f6', other: '#475569' };
        var N = { human: 'Humano', machine: 'Contestadora', not_sure: 'No seguro', unavailable: 'No disponible', no_answer: 'Sin respuesta', failed: 'Fallidas' };
        var KEYS = ['human', 'machine', 'not_sure', 'unavailable', 'no_answer', 'failed'];
        var DAYS = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        var SVGNS = 'http://www.w3.org/2000/svg';
        var form = document.getElementById('filters');
        document.getElementById('minn').textContent = MIN;

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function svg(w, h) { var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 ' + w + ' ' + h); s.setAttribute('width', '100%'); s.setAttribute('role', 'img'); return s; }
        function se(parent, name, attrs, text) { var e = document.createElementNS(SVGNS, name); Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); }); if (text) e.textContent = text; parent.appendChild(e); return e; }
        function n(v) { return Number(v) || 0; }
        function pct(a, d) { return d > 0 ? Math.round(1000 * a / d) / 10 : null; }
        function fp(a, d) { var p = pct(a, d); return p === null ? '—' : String(p).replace('.', ',') + ' % (' + a + ' de ' + d + ')'; }
        function query(extra) {
            var p = new URLSearchParams(new FormData(form));
            Array.from(p.keys()).forEach(function (k) { if (!p.get(k)) p.delete(k); });
            Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
            return p.toString();
        }
        function empty(box, text) { box.textContent = ''; box.appendChild(el('p', 'muted', text || 'Sin datos en el rango.')); }
        function legend(box, keys) {
            var lg = el('div', 'legend');
            keys.forEach(function (k) { var s = el('span'), i = el('i'); i.style.background = C[k]; s.appendChild(i); s.appendChild(document.createTextNode(N[k] || k)); lg.appendChild(s); });
            box.appendChild(lg);
        }
        function table(head, rows) {
            var t = el('table', 'table-compact'), th = el('thead'), tr = el('tr');
            head.forEach(function (h) { tr.appendChild(el('th', '', h)); }); th.appendChild(tr); t.appendChild(th);
            var tb = el('tbody'); rows.forEach(function (r) { var x = el('tr'); r.forEach(function (c) { x.appendChild(el('td', '', c == null ? '' : String(c))); }); tb.appendChild(x); }); t.appendChild(tb); return t;
        }
        // Barras horizontales desde cero: rows = [{name, value (%), n, d, color}]
        function hbars(box, rows, maxv) {
            box.textContent = '';
            if (!rows.length) { empty(box); return; }
            maxv = maxv || Math.max.apply(null, rows.map(function (r) { return r.value || 0; })) || 1;
            rows.forEach(function (r) {
                var line = el('div', 'hbar' + (r.small ? ' small' : ''));
                line.appendChild(el('span', 'name', r.name)); line.firstChild.title = r.name;
                var tr = el('div', 'track'), s = el('span'); s.style.width = ((r.value || 0) / maxv * 100) + '%'; s.style.background = r.color; tr.appendChild(s); line.appendChild(tr);
                var v = el('span', 'val', r.label); if (r.small) v.appendChild(el('span', 'tag-small', 'muestra pequeña')); line.appendChild(v); box.appendChild(line);
            });
        }

        function notes(d) {
            var box = document.getElementById('notes'); box.textContent = '';
            box.appendChild(el('p', '', 'Los resultados del AMD anteriores al 03/11/2026 15:31 (Lima) no son válidos y no se cuentan como humano/contestadora.'));
            if (d.extra && d.extra.sip && d.extra.sip.partial) box.appendChild(el('p', '', 'Hay ' + n(d.extra.sip.partial_calls) + ' llamadas sin código SIP final capturado (las de la primera tanda de pruebas): sus causas aparecen como «sin código» o «provisional».'));
            (d.warnings || []).forEach(function (w) { box.appendChild(el('p', '', w)); });
        }

        function kpis(k) {
            var box = document.getElementById('kpis'); box.textContent = ''; var calls = n(k.calls);
            [['Llamadas', String(calls), 'en el rango', '#3b82f6'],
             ['Contestadas', fp(n(k.answered), calls), 'sobre llamadas', '#3b82f6'],
             ['Contacto humano', fp(n(k.human) + n(k.not_sure), calls), 'humano + no seguro, sobre llamadas', '#10b981'],
             ['Contestadora', fp(n(k.machine), calls), 'sobre llamadas', '#6366f1'],
             ['No disponible', fp(n(k.unavailable), calls), 'sobre llamadas', '#f59e0b'],
             ['Sin respuesta', fp(n(k.no_answer), calls), 'sobre llamadas', '#9ca3af'],
             ['Fallidas', fp(n(k.failed), calls), 'sobre llamadas', '#ef4444'],
             ['Transferencias OK', fp(n(k.xfer_ok), n(k.xfer_ok) + n(k.xfer_fail)), 'sobre transferencias iniciadas', '#10b981']]
            .forEach(function (x) { var c = el('div', 'kpi'); c.style.setProperty('--kc', x[3]); c.appendChild(el('div', 'l', x[0])); c.appendChild(el('div', 'v', x[1].split(' (')[0])); c.appendChild(el('div', 'b', (x[1].indexOf('(') > 0 ? '(' + x[1].split(' (')[1] + ' · ' : '') + x[2])); box.appendChild(c); });
        }

        function trend(days) {
            var box = document.getElementById('trend'); box.textContent = '';
            if (!days || !days.length) { empty(box); return; }
            var W = Math.max(640, days.length * 60), H = 170, L = 38, R = 24, T = 10, B = 24, pw = W - L - R, ph = H - T - B;
            var s = svg(W, H); s.setAttribute('aria-label', 'Tendencia diaria');
            var series = [['answered', C.answered, 'Contestadas'], ['human', C.human, 'Contacto humano']];
            var vals = days.map(function (d) { return { a: pct(n(d.answered), n(d.calls)), h: pct(n(d.human) + n(d.not_sure), n(d.calls)), calls: n(d.calls) }; });
            var maxp = Math.max(10, Math.ceil(Math.max.apply(null, vals.map(function (v) { return Math.max(v.a || 0, v.h || 0); })) / 10) * 10);
            [0, 0.5, 1].forEach(function (f) { var y = T + ph - f * ph; se(s, 'line', { x1: L, x2: W - R, y1: y, y2: y, stroke: '#374151', 'stroke-width': 1 }); se(s, 'text', { x: 2, y: y + 4, 'font-size': 10, fill: '#9ca3af' }, Math.round(f * maxp) + ' %'); });
            var step = pw / Math.max(1, days.length - 1);
            series.forEach(function (sr, si) {
                var pts = [];
                days.forEach(function (d, i) { var v = si === 0 ? vals[i].a : vals[i].h; if (v === null) return; pts.push([L + (days.length === 1 ? pw / 2 : i * step), T + ph - v / maxp * ph, v, i]); });
                if (pts.length > 1) se(s, 'polyline', { points: pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' '), fill: 'none', stroke: sr[1], 'stroke-width': 2 });
                pts.forEach(function (p) { var small = vals[p[3]].calls < MIN; var c = se(s, 'circle', { cx: p[0], cy: p[1], r: 4, fill: small ? '#111827' : sr[1], stroke: sr[1], 'stroke-width': 2 }); var t = document.createElementNS(SVGNS, 'title'); t.textContent = days[p[3]].day_local + ' · ' + sr[2] + ': ' + p[2] + ' % de ' + vals[p[3]].calls + ' llamadas' + (small ? ' (muestra pequeña)' : ''); c.appendChild(t); });
            });
            days.forEach(function (d, i) { if (i % Math.ceil(days.length / 10) === 0) se(s, 'text', { x: L + (days.length === 1 ? pw / 2 : i * step), y: H - 6, 'font-size': 10, fill: '#9ca3af', 'text-anchor': i === days.length - 1 && days.length > 1 ? 'end' : 'middle' }, d.day_local.slice(5)); });
            box.appendChild(s);
            // llamadas por día, columnas desde 0
            var H2 = 90, s2 = svg(W, H2), maxc = Math.max.apply(null, vals.map(function (v) { return v.calls; })) || 1, bw = Math.min(40, pw / days.length - 6);
            se(s2, 'text', { x: 2, y: 12, 'font-size': 10, fill: '#9ca3af' }, 'Llamadas/día (máx. ' + maxc + ')');
            days.forEach(function (d, i) { var h = (H2 - 30) * vals[i].calls / maxc, x = L + (days.length === 1 ? pw / 2 : i * step) - bw / 2; var r = se(s2, 'rect', { x: x, y: H2 - 16 - h, width: bw, height: Math.max(1, h), fill: '#475569' }); var t = document.createElementNS(SVGNS, 'title'); t.textContent = d.day_local + ': ' + vals[i].calls + ' llamadas'; r.appendChild(t); });
            box.appendChild(s2); legend(box, ['answered', 'human']);
        }

        function compare(box, rows) {
            var data = rows.map(function (r) { var calls = n(r.calls), h = n(r.human) + n(r.not_sure); return { name: r.name, value: pct(h, calls) || 0, label: fp(h, calls), small: calls < MIN, color: C.human, calls: calls }; });
            data.sort(function (a, b) { return b.value - a.value; });
            hbars(box, data, Math.max(5, Math.ceil(Math.max.apply(null, data.map(function (d) { return d.value; }).concat([0])) / 5) * 5));
        }
        function comparatives(rep) {
            var lists = rep.by_list.map(function (r) { return Object.assign({}, r, { name: r.list + ' · ' + r.campaign }); });
            if (!lists.length) { empty(document.getElementById('cmplist')); empty(document.getElementById('cmpcamp')); return; }
            compare(document.getElementById('cmplist'), lists);
            var byc = {}; lists.forEach(function (r) { var k = r.campaign; var o = byc[k] = byc[k] || { name: k, calls: 0, human: 0, not_sure: 0 }; o.calls += n(r.calls); o.human += n(r.human); o.not_sure += n(r.not_sure); });
            compare(document.getElementById('cmpcamp'), Object.keys(byc).map(function (k) { return byc[k]; }));
        }
        function sumTable(sum) {
            var box = document.getElementById('sumtable'); box.textContent = '';
            if (!sum || !sum.lists.length) { empty(box, 'Sin listas con llamadas.'); return; }
            box.appendChild(table(['Campaña', 'Lista', 'Estado', 'Contactos', 'Llamadas', 'Contestadas', 'Humano', 'Contestadora', 'Transf. OK', 'Primera llamada', 'Última llamada'],
                sum.lists.map(function (r) { var c = n(r.calls); return [r.campaign, r.list, r.state_label, r.contacts, c, fp(n(r.answered), c), fp(n(r.human) + n(r.not_sure), c), fp(n(r.machine), c), r.xfer_ok, r.first_call, r.last_call]; })));
        }

        function funnel(f) {
            var box = document.getElementById('funnel'); box.textContent = '';
            if (!f || !f.stages || !n(f.stages[0].n)) { empty(box); return; }
            var top = n(f.stages[0].n), cols = ['#3b82f6', '#3b82f6', '#10b981', '#10b981', '#10b981'];
            hbars(box, f.stages.map(function (s, i) { return { name: s.label, value: n(s.n) / top * 100, label: n(s.n) + (s.pct_prev == null ? '' : ' · ' + String(s.pct_prev).replace('.', ',') + ' % de la etapa anterior'), color: cols[i] }; }), 100);
            var l = f.leaks || {}; box.appendChild(el('p', 'muted', 'Fugas: contestadora ' + n(l.machine) + ' · no disponible ' + n(l.unavailable) + ' · sin respuesta ' + n(l.no_answer) + ' · fallidas ' + n(l.failed) + ' · transferencias fallidas ' + n(l.xfer_fail)));
        }
        function sip(sp, calls) {
            var box = document.getElementById('sip'); box.textContent = '';
            if (!sp || !sp.families.length) { empty(box); document.getElementById('sipcodes').textContent = ''; return; }
            var tot = sp.families.reduce(function (a, f) { return a + n(f.calls); }, 0);
            hbars(box, sp.families.map(function (f) { return { name: f.label, value: n(f.calls) / tot * 100, label: fp(n(f.calls), tot), color: C.unavailable, small: n(f.calls) < 30 }; }), Math.max.apply(null, sp.families.map(function (f) { return n(f.calls) / tot * 100; })));
            var cb = document.getElementById('sipcodes'); cb.textContent = '';
            cb.appendChild(table(['Código', 'Familia', 'Resultado', 'Llamadas'], sp.codes.map(function (c) { return [c.code || '—', c.family, c.disposition || '—', c.calls]; })));
        }
        function amd(rows) {
            var box = document.getElementById('amd'); box.textContent = '';
            if (!rows || !rows.length) { empty(box); return; }
            var tot = rows.reduce(function (a, r) { return a + n(r.calls); }, 0);
            hbars(box, rows.map(function (r) { return { name: r.amd_status + ' · ' + r.amd_cause, value: n(r.calls) / tot * 100, label: fp(n(r.calls), tot), color: r.amd_status === 'HUMAN' ? C.human : r.amd_status === 'MACHINE' ? C.machine : C.not_sure }; }), Math.max.apply(null, rows.map(function (r) { return n(r.calls) / tot * 100; })));
        }
        function xfer(t) {
            var box = document.getElementById('xfer'), tot = n(t.ok) + n(t.failed);
            if (!tot) { empty(box, 'Sin transferencias en el rango.'); return; }
            hbars(box, [{ name: 'Completadas', value: n(t.ok) / tot * 100, label: fp(n(t.ok), tot), color: C.human, small: tot < 30 }, { name: 'Fallidas', value: n(t.failed) / tot * 100, label: fp(n(t.failed), tot), color: C.failed, small: tot < 30 }], 100);
        }
        function carriers(cr) {
            var wrap = document.getElementById('carrierbox'); wrap.hidden = !(cr && cr.multiple);
            if (wrap.hidden) return;
            var box = document.getElementById('carrier'); box.textContent = '';
            box.appendChild(table(['Troncal', 'Llamadas', 'Contestadas', 'Contacto humano', 'Fallidas'], cr.rows.map(function (r) { var c = n(r.calls); return [r.carrier, c, fp(n(r.answered), c), fp(n(r.human) + n(r.not_sure), c), fp(n(r.failed), c)]; })));
        }

        function matrix(cells) {
            var box = document.getElementById('matrix'); box.textContent = '';
            if (!cells || !cells.length) { empty(box); return; }
            var hours = cells.map(function (c) { return n(c.hour_local); }), h0 = Math.min.apply(null, hours), h1 = Math.max.apply(null, hours), map = {};
            cells.forEach(function (c) { map[c.weekday + '_' + c.hour_local] = c; });
            var t = el('table', 'matrix'), hr = el('tr'); hr.appendChild(el('th', '', ''));
            for (var h = h0; h <= h1; h++) hr.appendChild(el('th', '', String(h))); t.appendChild(hr);
            for (var d = 1; d <= 7; d++) {
                var tr = el('tr'); tr.appendChild(el('th', '', DAYS[d]));
                for (h = h0; h <= h1; h++) {
                    var c = map[d + '_' + h], td = el('td'); if (!c) { td.className = 'empty'; tr.appendChild(td); continue; }
                    var calls = n(c.calls), p = pct(n(c.answered), calls) || 0;
                    td.style.background = 'rgba(59,130,246,' + (0.15 + 0.85 * Math.min(1, p / 100)) + ')'; td.textContent = Math.round(p) + '%';
                    td.title = DAYS[d] + ' ' + h + ':00 · ' + fp(n(c.answered), calls) + ' contestadas' + (calls < MIN ? ' (muestra pequeña)' : ''); if (calls < MIN) td.className = 'low'; tr.appendChild(td);
                }
                t.appendChild(tr);
            }
            box.appendChild(t); box.appendChild(el('p', 'muted', 'Cada celda: % contestadas. Pasa el cursor para ver cuántas llamadas hay detrás.'));
        }
        function attempts(apl) {
            var box = document.getElementById('attempts'); box.textContent = '';
            if (!apl || !apl.lists.length) { empty(box); return; }
            var shades = ['#1f2937', '#60a5fa', '#3b82f6', '#2563eb', '#1e3a8a'], names = ['Sin marcar', '1 intento', '2 intentos', '3 intentos', '4 o más'];
            apl.lists.forEach(function (l) {
                var arr = l.leads_by_attempts.map(n), start = l.never_dialed_known ? 0 : 1, tot = 0; for (var i = start; i < 5; i++) tot += arr[i];
                var line = el('div', 'hbar'), nm = el('span', 'name', l.list); nm.title = l.list; line.appendChild(nm);
                var tr = el('div', 'track'); for (i = start; i < 5; i++) if (arr[i]) { var sp = el('span'); sp.style.width = (100 * arr[i] / tot) + '%'; sp.style.background = shades[i]; sp.title = names[i] + ': ' + fp(arr[i], tot); tr.appendChild(sp); }
                line.appendChild(tr); line.appendChild(el('span', 'val', tot + ' contactos' + (l.never_dialed_known ? '' : ' marcados'))); box.appendChild(line);
            });
            var lg = el('div', 'legend'); names.forEach(function (nm, i) { var s = el('span'), ic = el('i'); ic.style.background = shades[i]; s.appendChild(ic); s.appendChild(document.createTextNode(nm)); lg.appendChild(s); }); box.appendChild(lg);
            box.appendChild(el('p', 'muted', 'Los «sin marcar» solo se cuentan sin rango de fechas.'));
        }
        function cumul(rows) {
            var box = document.getElementById('cumul'); box.textContent = '';
            if (!rows || !rows.length) { empty(box); return; }
            var W = 420, H = 150, L = 38, R = 10, T = 10, B = 24, pw = W - L - R, ph = H - T - B, s = svg(W, H), maxp = Math.max(10, Math.ceil(Math.max.apply(null, rows.map(function (r) { return n(r.cumulative_pct); })) / 10) * 10);
            [0, 0.5, 1].forEach(function (f) { var y = T + ph - f * ph; se(s, 'line', { x1: L, x2: W - R, y1: y, y2: y, stroke: '#374151' }); se(s, 'text', { x: 2, y: y + 4, 'font-size': 10, fill: '#9ca3af' }, Math.round(f * maxp) + ' %'); });
            var step = rows.length > 1 ? pw / (rows.length - 1) : 0, pts = rows.map(function (r, i) { return [L + (rows.length === 1 ? pw / 2 : i * step), T + ph - n(r.cumulative_pct) / maxp * ph, r]; });
            if (pts.length > 1) se(s, 'polyline', { points: pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' '), fill: 'none', stroke: C.human, 'stroke-width': 2 });
            pts.forEach(function (p) { var c = se(s, 'circle', { cx: p[0], cy: p[1], r: 4, fill: C.human }); var t = document.createElementNS(SVGNS, 'title'); t.textContent = 'Intento ' + p[2].attempt_no + ': ' + p[2].cumulative_pct + ' % acumulado (' + p[2].leads + ' contactos nuevos, ' + p[2].cumulative + ' en total)'; c.appendChild(t); se(s, 'text', { x: p[0] - 3, y: H - 8, 'font-size': 10, fill: '#9ca3af' }, String(p[2].attempt_no)); });
            box.appendChild(s); box.appendChild(el('p', 'muted', 'Eje horizontal: nº de intento. Eje vertical: % acumulado de contactos alcanzados (desde 0).'));
        }
        function detail(d) {
            var box = document.getElementById('detail'); box.textContent = '';
            var DM = { AMD: 'Solo AMD', ZYPAD_SOMBRA: 'Zypad sombra', ZYPAD_CORTE: 'Zypad corte' };
            box.appendChild(table(['Inicio (Lima)', 'Campaña', 'Lista', 'Teléfono', 'Tipo', 'Estado', 'AMD', 'Zypad', 'SIP', 'Q.850', 'Seg.'],
                d.rows.map(function (r) { return [r.started_at_local, r.campaign, r.list, r.phone, DM[r.dial_mode] || r.dial_mode || 'Solo AMD', r.result_final || r.disposition || r.status, r.amd_valid ? (r.amd_status || '') : (r.amd_status ? r.amd_status + ' (no válido)' : ''), (r.palabra ? '«' + r.palabra + '» ' + (r.momento || '') + (r.segundo != null && r.segundo !== '' ? ' ' + r.segundo + ' s' : '') + (r.preanswer_audio === 'SI' ? ' · audio previo' : '') : (r.preanswer_audio === 'SI' ? 'audio previo' : '')), r.sip_cause, r.q850, r.billsec]; })));
            lastPages = Math.max(1, Math.ceil(d.total / d.per_page));
            document.getElementById('pageinfo').textContent = 'Página ' + d.page + ' de ' + lastPages + ' · ' + d.total + ' llamadas';
            document.getElementById('prev').disabled = d.page <= 1; document.getElementById('next').disabled = d.page >= lastPages;
        }

        function dialmodes(dm) {
            var box = document.getElementById('dialmodes'); box.textContent = '';
            if (!dm || !dm.rows || !dm.rows.length) { empty(box, 'Sin llamadas con tipo de marcación en el rango.'); return; }
            function cnt(a, d) { return a + ' de ' + d + (d > 0 ? ' (' + String(pct(a, d)).replace('.', ',') + ' %)' : ''); }
            var t = el('table', 'table-compact'), th = el('thead'), tr = el('tr');
            ['Tipo de marcación', 'Llamadas (N)', 'Audio antes de contestar', 'Casilla de voz', 'Zypad oyó una palabra', 'Ambos dicen casilla', 'Solo Zypad', 'Solo AMD', 'Solo Zypad con AMD humano', 'Tiempo que habría ahorrado el corte'].forEach(function (h) { tr.appendChild(el('th', '', h)); });
            th.appendChild(tr); t.appendChild(th); var tb = el('tbody');
            dm.rows.forEach(function (r) {
                var x = el('tr'), c = n(r.calls), first = el('td', '', r.label); if (r.small_sample) first.appendChild(el('span', 'tag-small', 'muestra pequeña')); x.appendChild(first);
                [String(c), cnt(n(r.preanswer_audio), c), cnt(n(r.casilla_voz), c), cnt(n(r.zypad_hits), c), String(n(r.both_casilla)), String(n(r.only_zypad)), String(n(r.only_amd))].forEach(function (v) { x.appendChild(el('td', '', v)); });
                var rev = el('td', '', String(n(r.only_zypad_amd_human))); if (n(r.only_zypad_amd_human) > 0) { rev.style.background = 'rgba(245,158,11,.22)'; rev.style.fontWeight = '700'; rev.title = 'Caso a revisar con la grabación; no se considera error'; rev.appendChild(el('span', 'muted', ' · a revisar')); } x.appendChild(rev);
                x.appendChild(el('td', '', r.saved_n > 0 ? n(r.saved_n) + ' llamadas · total ' + String(r.saved_seconds_total).replace('.', ',') + ' s · media ' + String(r.saved_seconds_avg).replace('.', ',') + ' s' : 'Sin datos'));
                tb.appendChild(x);
            });
            t.appendChild(tb); box.appendChild(t);
            box.appendChild(el('p', 'muted', '«Solo Zypad con AMD humano» se revisa con la grabación: puede ser un posible corte equivocado o una detección acertada que el AMD no vio.'));
        }
        function fillOptions(opts) {
            options = opts; var c = document.getElementById('f_campaign');
            opts.forEach(function (o) { var op = el('option', '', o.name); op.value = o.campaign_id; c.appendChild(op); });
            optionsLoaded = true; fillLists();
        }
        function fillLists() {
            var l = document.getElementById('f_list'), keep = l.value; l.textContent = ''; var all = el('option', '', 'Todas'); all.value = ''; l.appendChild(all);
            var cid = document.getElementById('f_campaign').value;
            options.forEach(function (o) { if (cid && String(o.campaign_id) !== cid) return; o.lists.forEach(function (x) { var op = el('option', '', x.name + ' (#' + x.list_id + ')'); op.value = x.list_id; l.appendChild(op); }); });
            l.value = keep;
        }
        function limaDate(offset) { var d = new Date(Date.now() + offset * 86400000); return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); }
        function quick(from, to) { form.elements.from.value = from; form.elements.to.value = to; page = 1; load(); }
        var q = document.getElementById('quick');
        [['Hoy', 0, 0], ['Ayer', -1, -1], ['Últimos 7 días', -6, 0], ['Últimos 30 días', -29, 0]].forEach(function (x) { var b = el('button', 'btn-action', x[0]); b.type = 'button'; b.addEventListener('click', function () { quick(limaDate(x[1]), limaDate(x[2])); }); q.appendChild(b); });
        var all = el('button', 'btn-action', 'Todo el historial'); all.type = 'button'; all.addEventListener('click', function () { quick('', ''); }); q.appendChild(all);

        function load() {
            document.getElementById('csv').href = API + '?' + query({ export: 'csv' });
            fetch(API + '?' + query({ page: page }), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (j) {
                    document.getElementById('error').textContent = '';
                    if (!optionsLoaded) fillOptions(j.options);
                    var r = j.report, x = j.extra;
                    notes(j); kpis(r.kpi); trend(x && x.by_day); comparatives(r); sumTable(j.summary);
                    dialmodes(j.dialmodes); if (x) { funnel(x.funnel); sip(x.sip); amd(x.amd_by_cause); carriers(x.by_carrier); matrix(x.by_weekday_hour); attempts(x.attempts_per_lead); cumul(x.first_contact_attempt); }
                    xfer(r.transfers); detail(r.detail);
                })
                .catch(function (e) { var b = document.getElementById('error'); b.textContent = ''; b.appendChild(el('p', 'rep-error', 'No se pudo cargar el informe: ' + e.message)); });
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); page = 1; load(); });
        document.getElementById('f_campaign').addEventListener('change', fillLists);
        document.getElementById('prev').addEventListener('click', function () { if (page > 1) { page--; load(); } });
        document.getElementById('next').addEventListener('click', function () { if (page < lastPages) { page++; load(); } });
        load();
    })();
    </script>
</body>
</html>

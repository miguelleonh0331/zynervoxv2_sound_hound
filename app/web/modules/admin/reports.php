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
        .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-bottom: 1rem; }
        .filters label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .filters input, .filters select { min-width: 9rem; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .kpi { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; border-top: 3px solid var(--kc, #6b7280); }
        .kpi .l { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .kpi .v { font-size: 1.6rem; font-weight: 700; margin-top: 0.2rem; }
        .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; min-width: 0; }
        .panel h2 { font-size: 0.85rem; margin: 0 0 0.6rem; }
        .hbar { display: grid; grid-template-columns: 9rem 1fr 3rem; gap: 0.5rem; align-items: center; font-size: 0.78rem; margin: 0.25rem 0; }
        .hbar .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hbar .track { background: var(--glass); height: 12px; border-radius: 2px; overflow: hidden; display: flex; }
        .hbar .track span { height: 100%; }
        .legend { display: flex; flex-wrap: wrap; gap: 0.6rem; font-size: 0.7rem; color: var(--text-muted); margin-top: 0.5rem; }
        .legend i { display: inline-block; width: 10px; height: 10px; margin-right: 4px; border-radius: 2px; }
        .scroll { overflow-x: auto; }
        .pager { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.6rem; font-size: 0.8rem; }
        .rep-error { color: #fca5a5; margin-bottom: 1rem; }
        .muted { color: var(--text-muted); font-size: 0.75rem; }
        @media (max-width: 600px) { .panels { grid-template-columns: 1fr; } .hbar { grid-template-columns: 6rem 1fr 2.5rem; } }
    </style>
</head>
<body>
    <?php renderSidebar('reports'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Reportes</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Resultados de llamadas · horas en Lima</p>
            </div>
        </header>
        <form class="filters" id="filters">
            <label>Desde <input type="date" name="from"></label>
            <label>Hasta <input type="date" name="to"></label>
            <label>Campaña <select name="campaign_id" id="f_campaign"><option value="">Todas</option></select></label>
            <label>Lista <select name="list_id" id="f_list"><option value="">Todas</option></select></label>
            <button type="submit" class="btn-primary">Aplicar</button>
            <a class="btn-action" id="csv" href="#">Exportar CSV</a>
        </form>
        <div id="error"></div>
        <section class="kpis" id="kpis"></section>
        <section class="panels">
            <div class="panel" style="grid-column: 1 / -1;"><h2>Llamadas por hora</h2><div id="byhour"></div><div class="legend" id="legend-hour"></div></div>
            <div class="panel"><h2>Por lista</h2><div id="bylist"></div><div class="legend" id="legend-list"></div></div>
            <div class="panel"><h2>Resultado del AMD</h2><div id="byamd"></div><p class="muted" id="amdnote"></p></div>
            <div class="panel"><h2>Causas SIP / Q.850</h2><div class="scroll" id="bycause"></div></div>
            <div class="panel"><h2>Transferencias</h2><div id="xfer"></div></div>
        </section>
        <div class="panel">
            <h2>Detalle de llamadas</h2>
            <div class="scroll" id="detail"></div>
            <div class="pager"><button type="button" class="btn-action" id="prev">Anterior</button><span id="pageinfo"></span><button type="button" class="btn-action" id="next">Siguiente</button></div>
        </div>
    </main>
    <script>
    (function () {
        var API = 'reports_api.php', page = 1, lastPages = 1, optionsLoaded = false, options = [];
        var COLORS = { human: '#10b981', machine: '#6366f1', not_sure: '#a78bfa', unavailable: '#f59e0b', no_answer: '#9ca3af', failed: '#ef4444', other: '#475569' };
        var NAMES = { human: 'Humano', machine: 'Contestadora', not_sure: 'No seguro', unavailable: 'No disponible', no_answer: 'Sin respuesta', failed: 'Fallidas' };
        var KEYS = ['human', 'machine', 'not_sure', 'unavailable', 'no_answer', 'failed'];
        var form = document.getElementById('filters');

        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function n(v) { return Number(v) || 0; }
        function query(extra) {
            var p = new URLSearchParams(new FormData(form));
            Array.from(p.keys()).forEach(function (k) { if (!p.get(k)) p.delete(k); });
            Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
            return p.toString();
        }
        function legend(id, keys) {
            var box = document.getElementById(id); box.textContent = '';
            keys.forEach(function (k) { var s = el('span'); var i = el('i'); i.style.background = COLORS[k]; s.appendChild(i); s.appendChild(document.createTextNode(NAMES[k])); box.appendChild(s); });
        }
        function stacked(row, total) {
            var track = el('div', 'track'), sum = 0;
            KEYS.forEach(function (k) { var v = n(row[k]); sum += v; if (v) { var s = el('span'); s.style.width = (100 * v / total) + '%'; s.style.background = COLORS[k]; s.title = NAMES[k] + ': ' + v; track.appendChild(s); } });
            var rest = n(row.calls) - sum;
            if (rest > 0) { var o = el('span'); o.style.width = (100 * rest / total) + '%'; o.style.background = COLORS.other; o.title = 'Otros: ' + rest; track.appendChild(o); }
            return track;
        }

        function kpis(k) {
            var box = document.getElementById('kpis'); box.textContent = '';
            [['Llamadas', n(k.calls), '#3b82f6'], ['Contestadas', n(k.answered), '#10b981'], ['Contacto', k.contact_pct + ' %', '#10b981'], ['Humano', n(k.human), '#10b981'], ['Contestadora', n(k.machine), '#6366f1'],
             ['No disponible', n(k.unavailable), '#f59e0b'], ['Sin respuesta', n(k.no_answer), '#9ca3af'], ['Fallidas', n(k.failed), '#ef4444'], ['Transferencias OK', n(k.xfer_ok), '#10b981'], ['Transferencias fallidas', n(k.xfer_fail), '#ef4444'], ['Duración media (s)', n(k.avg_billsec), '#6b7280']]
            .forEach(function (x) { var c = el('div', 'kpi'); c.style.setProperty('--kc', x[2]); c.appendChild(el('div', 'l', x[0])); c.appendChild(el('div', 'v', String(x[1]))); box.appendChild(c); });
        }
        function byHour(rows) {
            var box = document.getElementById('byhour'); box.textContent = '';
            if (!rows.length) { box.appendChild(el('p', 'muted', 'Sin llamadas en el rango.')); return; }
            rows = rows.slice(-72);
            var max = Math.max.apply(null, rows.map(function (r) { return n(r.calls); })) || 1;
            var W = Math.max(600, rows.length * 22), H = 180, pad = 22, bw = (W - 10) / rows.length;
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 ' + W + ' ' + (H + pad)); svg.setAttribute('width', '100%'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Llamadas por hora');
            rows.forEach(function (r, i) {
                var y = H, x = 5 + i * bw, calls = n(r.calls), sum = 0, parts = KEYS.map(function (k) { sum += n(r[k]); return [k, n(r[k])]; });
                if (calls > sum) parts.push(['other', calls - sum]);
                parts.forEach(function (p) {
                    if (!p[1]) return; var h = H * p[1] / max; y -= h;
                    var rect = document.createElementNS(svg.namespaceURI, 'rect'); rect.setAttribute('x', x + 1); rect.setAttribute('y', y); rect.setAttribute('width', Math.max(1, bw - 3)); rect.setAttribute('height', h); rect.setAttribute('fill', COLORS[p[0]]);
                    var t = document.createElementNS(svg.namespaceURI, 'title'); t.textContent = r.hour_local + ' · ' + (NAMES[p[0]] || 'Otros') + ': ' + p[1]; rect.appendChild(t); svg.appendChild(rect);
                });
                if (i % Math.ceil(rows.length / 12) === 0) { var lb = document.createElementNS(svg.namespaceURI, 'text'); lb.setAttribute('x', x); lb.setAttribute('y', H + 14); lb.setAttribute('font-size', '10'); lb.setAttribute('fill', '#9ca3af'); lb.textContent = r.hour_local.slice(5); svg.appendChild(lb); }
            });
            var wrap = el('div', 'scroll'); wrap.appendChild(svg); box.appendChild(wrap);
        }
        function byList(rows) {
            var box = document.getElementById('bylist'); box.textContent = '';
            if (!rows.length) { box.appendChild(el('p', 'muted', 'Sin datos.')); return; }
            var max = Math.max.apply(null, rows.map(function (r) { return n(r.calls); })) || 1;
            rows.forEach(function (r) {
                var line = el('div', 'hbar'); line.appendChild(el('span', 'name', r.list + ' · ' + r.campaign));
                var track = stacked(r, max); line.appendChild(track); line.appendChild(el('span', '', String(n(r.calls)))); box.appendChild(line);
            });
        }
        function byAmd(rows, f) {
            var box = document.getElementById('byamd'); box.textContent = '';
            document.getElementById('amdnote').textContent = f.include_invalid_amd ? '' : 'Solo resultados del AMD válidos desde ' + f.amd_valid_from_local + ' (hora de Lima).';
            if (!rows.length) { box.appendChild(el('p', 'muted', 'Sin resultados.')); return; }
            var max = Math.max.apply(null, rows.map(function (r) { return n(r.calls); })) || 1;
            rows.forEach(function (r) {
                var line = el('div', 'hbar'); line.appendChild(el('span', 'name', r.result + (r.amd_status && r.amd_status !== r.result ? ' (' + r.amd_status + ')' : '')));
                var track = el('div', 'track'), s = el('span'); s.style.width = (100 * n(r.calls) / max) + '%'; s.style.background = r.amd_status === 'HUMAN' ? COLORS.human : r.amd_status === 'MACHINE' ? COLORS.machine : COLORS.not_sure; track.appendChild(s);
                line.appendChild(track); line.appendChild(el('span', '', String(n(r.calls)))); box.appendChild(line);
            });
        }
        function table(head, rows) {
            var t = el('table', 'table-compact'), th = el('thead'), tr = el('tr');
            head.forEach(function (h) { tr.appendChild(el('th', '', h)); }); th.appendChild(tr); t.appendChild(th);
            var tb = el('tbody'); rows.forEach(function (r) { var x = el('tr'); r.forEach(function (c) { x.appendChild(el('td', '', c == null ? '' : String(c))); }); tb.appendChild(x); }); t.appendChild(tb); return t;
        }
        function causes(rows) {
            var box = document.getElementById('bycause'); box.textContent = '';
            if (!rows.length) { box.appendChild(el('p', 'muted', 'Sin datos.')); return; }
            box.appendChild(table(['Causa SIP', 'Q.850', 'Resultado', 'Llamadas'], rows.map(function (r) { return [r.sip_cause || '—', r.q850, r.disposition || '—', r.calls]; })));
        }
        function xfer(t) {
            var box = document.getElementById('xfer'); box.textContent = '';
            var total = n(t.ok) + n(t.failed);
            if (!total) { box.appendChild(el('p', 'muted', 'Sin transferencias en el rango.')); return; }
            [['OK', n(t.ok), '#10b981'], ['Fallidas', n(t.failed), '#ef4444']].forEach(function (x) {
                var line = el('div', 'hbar'); line.appendChild(el('span', 'name', x[0])); var tr = el('div', 'track'), s = el('span'); s.style.width = (100 * x[1] / total) + '%'; s.style.background = x[2]; tr.appendChild(s);
                line.appendChild(tr); line.appendChild(el('span', '', String(x[1]))); box.appendChild(line);
            });
        }
        function detail(d) {
            var box = document.getElementById('detail'); box.textContent = '';
            box.appendChild(table(['Inicio (Lima)', 'Campaña', 'Lista', 'Teléfono', 'Estado', 'AMD', 'SIP', 'Q.850', 'Seg.'],
                d.rows.map(function (r) { return [r.started_at_local, r.campaign, r.list, r.phone, r.disposition || r.status, r.amd_valid ? (r.amd_status || '') : (r.amd_status ? r.amd_status + ' (no válido)' : ''), r.sip_cause, r.q850, r.billsec]; })));
            lastPages = Math.max(1, Math.ceil(d.total / d.per_page));
            document.getElementById('pageinfo').textContent = 'Página ' + d.page + ' de ' + lastPages + ' · ' + d.total + ' llamadas';
            document.getElementById('prev').disabled = d.page <= 1; document.getElementById('next').disabled = d.page >= lastPages;
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

        function load() {
            document.getElementById('csv').href = API + '?' + query({ export: 'csv' });
            fetch(API + '?' + query({ page: page }), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (j) {
                    document.getElementById('error').textContent = '';
                    if (!optionsLoaded) fillOptions(j.options);
                    var r = j.report; kpis(r.kpi); byHour(r.by_hour); legend('legend-hour', KEYS); byList(r.by_list); legend('legend-list', KEYS); byAmd(r.by_amd, r.filters); causes(r.by_cause); xfer(r.transfers); detail(r.detail);
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

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
        table.matrix { width: auto; border-collapse: separate; border-spacing: 2px; font-size: 0.68rem; }
        table.matrix th { font-weight: 700; color: var(--text); background: transparent; text-transform: none; padding: 2px 6px; text-align: center; font-size: 0.78rem; }
        table.matrix td { min-width: 34px; height: 30px; text-align: center; border-radius: 2px; color: #fff; }
        table.matrix td.empty { background: #e5e7eb; color: transparent; }
        table.matrix td.low { opacity: 0.35; }
        .big { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.6rem; margin: 0.5rem 0 0.8rem; }
        .big .c { background: var(--bg-card); border: 1px solid var(--border); border-top: 5px solid var(--kc); border-radius: 2px; padding: 0.6rem 0.75rem; }
        .big .c .num { font-size: 2.1rem; font-weight: 800; line-height: 1.1; }
        .big .c .nm { font-size: 0.85rem; font-weight: 700; } .big .c .pc { font-size: 0.8rem; color: var(--text-muted); }
        .big .c.zero { opacity: 0.55; } .big .c.zero .num { font-size: 1.4rem; }
        .stack { display: flex; height: 30px; border-radius: 3px; overflow: hidden; background: var(--glass); }
        .stack span { display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; }
        .stack span.lt { color: #1f2937; }
        .cmp { display: grid; grid-template-columns: minmax(8rem, 15rem) 1fr minmax(8rem, 13rem); gap: 0.6rem; align-items: center; margin: 0.4rem 0; font-size: 0.8rem; }
        .cmp .stack { height: 22px; } .cmp .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; } .cmp .nm small { display: block; font-weight: 400; color: var(--text-muted); font-size: 0.68rem; }
        .cmp .rt { font-size: 0.74rem; color: var(--text-muted); } .cmp .rt strong { color: var(--text); font-size: 0.85rem; }
        .key { display: flex; flex-wrap: wrap; gap: 0.2rem 0.8rem; font-size: 0.72rem; color: var(--text-muted); margin: 0.4rem 0 0.8rem; }
        .key i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; }
        .says { margin: 0.3rem 0 0.8rem; padding-left: 1.1rem; font-size: 0.88rem; line-height: 1.6; }
        .says li strong { font-size: 1rem; }
        details.tech { margin-top: 1.6rem; border: 1px solid var(--border); border-radius: 2px; padding: 0.4rem 0.9rem 0.9rem; }
        details.tech > summary { cursor: pointer; font-weight: 700; font-size: 1rem; padding: 0.4rem 0; }
        @media (max-width: 600px) { .cmp { grid-template-columns: 1fr; gap: 0.15rem; } { .panels { grid-template-columns: 1fr; } .hbar { grid-template-columns: 6.5rem 1fr 6.5rem; } }
    </style>
</head>
<body>
    <?php renderSidebar('reports'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Reportes</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Qué pasó con tus contactos, sin tecnicismos · cada contacto cuenta una sola vez, por su último resultado · horas en Lima</p>
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
        <p class="muted" id="scope"></p>
        <div id="error"></div>
        <div class="notes" id="notes"></div>

        <h2 class="sec">¿Qué pasó con mis contactos?</h2>
        <p class="muted" id="contactnote"></p>
        <section class="big" id="bigcats"></section>
        <div id="bigbar"></div>
        <ul class="says" id="contactsays"></ul>

        <div id="cmpsec" hidden>
            <h2 class="sec">Comparar listas</h2>
            <p class="muted">Cada fila es una lista y su barra es el 100 % de sus contactos. Ordenadas de más a menos humanos entre los contactos ya llamados.</p>
            <div id="cmpkey" class="key"></div>
            <div id="cmprows"></div>
            <div class="scroll" id="cmptable"></div>
        </div>
        <div id="cmpcampsec" hidden>
            <h2 class="sec">Comparar campañas</h2>
            <p class="muted">Lo mismo, sumando las listas de cada campaña (contactos, no intentos).</p>
            <div id="cmpcamprows"></div>
        </div>

        <h2 class="sec">¿Cuántas casillas de voz detectó Zypad antes de contestar?</h2>
        <p class="muted">Zypad y el AMD son dos detectores de casillas de voz del marcador: Zypad escucha antes de que contesten y el AMD decide después. Aquí se cuentan contactos, no intentos.</p>
        <div id="zypadbox"></div>

        <h2 class="sec">¿Cuándo conviene llamar?</h2>
        <p class="muted">Porcentaje de intentos contestados según el día y la hora (Lima). Más oscuro = contestan más. Las celdas apagadas tienen menos de <span id="minn"></span> intentos: no saques conclusiones de ellas. Esta tabla cuenta intentos, no contactos.</p>
        <div class="scroll" id="matrix"></div>

        <h2 class="sec">¿Sirven los reintentos?</h2>
        <p class="muted">Un contacto que no contesta se vuelve a llamar. Aquí se ve cuántas veces se llamó a cada contacto y qué aportó cada vuelta.</p>
        <ul class="says" id="retrysays"></ul>
        <div class="scroll" id="retrytable"></div>

        <details class="tech">
            <summary>Detalle técnico: conteo de intentos (no de contactos)</summary>
            <p class="muted">Todo lo de este desplegable cuenta <strong>intentos</strong>: cada vez que el marcador llama a un número, reintentos incluidos. Por eso sus cifras no coinciden con las de arriba, que cuentan contactos.</p>
            <h2 class="sec">Resumen de intentos</h2>
            <section class="kpis" id="kpis"></section>
            <section class="panels">
                <div class="panel wide"><h3>¿Cómo fueron los intentos día a día?</h3><div id="trend"></div></div>
            </section>

            <h2 class="sec">Qué pasó en cada intento</h2>
            <section class="panels">
                <div class="panel wide"><h3>Resultado de los intentos, con su código SIP</h3><p class="sub">El código SIP es el que devuelve el proveedor de llamadas; va al final en pequeño.</p><div class="scroll" id="sipcodes"></div></div>
                <div class="panel"><h3>Resultado del AMD</h3><p class="sub">Qué decidió el AMD en cada intento contestado, y su causa.</p><div id="amd"></div></div>
                <div class="panel"><h3>Transferencias</h3><p class="sub">Transferencias a tu destino completadas o fallidas (intentos).</p><div id="xfer"></div></div>
                <div class="panel wide" id="carrierbox" hidden><h3>Por troncal</h3><div class="scroll" id="carrier"></div></div>
            </section>

            <h2 class="sec">Tipos de marcación (intentos)</h2>
            <section class="panels">
                <div class="panel wide"><h3>Solo AMD frente a Zypad</h3>
                    <p class="sub">Compara cada tipo con su N. En «sombra», Zypad escucha antes de contestar pero no corta: decide el AMD. «Tiempo ahorrado» es lo que el corte habría adelantado frente al momento de contestar, solo en llamadas donde Zypad oyó una palabra antes de contestar. Sin metas ni umbrales: son datos para comparar.</p>
                    <div class="scroll" id="dialmodes"></div></div>
            </section>

            <h2 class="sec">Detalle de intentos</h2>
            <section class="panels">
                <div class="panel wide"><div class="filters" style="margin-bottom: 0.5rem;"><label>Estado <select id="f_estado"><option value="">Todos</option></select></label></div><div class="scroll" id="detail"></div>
                    <div class="pager"><button type="button" class="btn-action" id="prev">Anterior</button><span id="pageinfo"></span><button type="button" class="btn-action" id="next">Siguiente</button></div></div>
            </section>
        </details>
    </main>
    <script>
    (function () {
        var API = 'reports_api.php', page = 1, lastPages = 1, optionsLoaded = false, options = [];
        var MIN = 20; // muestra mínima por celda/lista; criterio de MarcadorBuilder
        var C = { human: '#10b981', machine: '#6366f1', not_sure: '#a78bfa', unavailable: '#f59e0b', no_answer: '#9ca3af', failed: '#ef4444', answered: '#3b82f6', other: '#475569' };
        var N = { human: 'Humano', machine: 'Casilla de voz', not_sure: 'No seguro', unavailable: 'No disponible', no_answer: 'Sin respuesta', failed: 'Fallidas' };
        var KEYS = ['human', 'machine', 'not_sure', 'unavailable', 'no_answer', 'failed'];
        var DAYS = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        var SVGNS = 'http://www.w3.org/2000/svg';
        var form = document.getElementById('filters');
        // Enlace desde Listas: ?campaign_id=N&list_id=M (enteros). Se aplica en la primera carga; si la lista no existe o no es de esa campaña, la página se comporta como siempre.
        var detailEstado = ''; // filtro «Estado» del detalle de intentos (clave de MarcadorBuilder); recarga solo el detalle
        var qs = new URLSearchParams(location.search), pending = null;
        (function () { var c = /^[1-9][0-9]*$/.test(qs.get('campaign_id') || '') ? qs.get('campaign_id') : '', l = /^[1-9][0-9]*$/.test(qs.get('list_id') || '') ? qs.get('list_id') : ''; if (c && l) pending = { campaign_id: c, list_id: l }; })();
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
            if (detailEstado) p.set('estado', detailEstado);
            if (pending) { p.set('campaign_id', pending.campaign_id); p.set('list_id', pending.list_id); }
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


        // ---- Resultado por contacto (cada contacto cuenta una vez, por su último intento) ----
        var CC = { humano: '#10b981', casilla_voz: '#3b82f6', no_seguro: '#8b5cf6', contestada_sin_amd: '#38bdf8', no_disponible: '#f59e0b', sin_respuesta: '#6b7280', ocupado: '#f97316', errores: '#ef4444', en_llamada: '#1e3a8a', sin_llamar: '#d1d5db' };
        function fx(v) { return String(v).replace('.', ','); }
        function rp(v, t) { return fx(t > 0 ? (Math.round(1000 * v / t) / 10).toFixed(1) : '0.0'); } // porcentaje real redondeado, sin ajustar para cuadrar
        function stack(counts, pcts, cats, total) {
            var bar = el('div', 'stack'); bar.setAttribute('role', 'img');
            cats.forEach(function (c) {
                var v = n(counts[c.key]); if (!v) return;
                var p = 100 * v / total, sp = el('span', c.key === 'sin_llamar' || c.key === 'contestada_sin_amd' ? 'lt' : '', p >= 9 ? rp(v, total) + ' %' : ''); sp.style.width = p + '%'; sp.style.background = CC[c.key];
                sp.title = c.label + ': ' + v + ' de ' + total + ' contactos (' + rp(v, total) + ' %)'; bar.appendChild(sp);
            });
            return bar;
        }
        function keyOf(box, cats) { box.textContent = ''; cats.forEach(function (c) { var s = el('span'), i = el('i'); i.style.background = CC[c.key]; s.appendChild(i); s.appendChild(document.createTextNode(c.label)); box.appendChild(s); }); }
        function contactsTotal(ct) {
            var box = document.getElementById('bigcats'), bar = document.getElementById('bigbar'); box.textContent = ''; bar.textContent = '';
            document.getElementById('contactnote').textContent = ct && ct.note ? ct.note : '';
            if (!ct || !ct.total || !ct.total.contacts) { box.appendChild(el('p', 'muted', 'No hay contactos con resultado en este rango.')); return; }
            var t = ct.total;
            ct.categories.forEach(function (c) {
                var v = n(t.counts[c.key]); if (!v && (c.key === 'sin_llamar' || c.key === 'en_llamada') && ct.source === 'attempts') return;
                var k = el('div', 'c' + (v ? '' : ' zero')); k.style.setProperty('--kc', CC[c.key]);
                k.appendChild(el('div', 'num', String(v))); k.appendChild(el('div', 'nm', c.label)); k.appendChild(el('div', 'pc', rp(v, t.contacts) + ' % de los contactos')); box.appendChild(k);
            });
            bar.appendChild(el('p', 'muted', t.contacts + ' contactos en total = 100 %'));
            bar.appendChild(stack(t.counts, t.pct, ct.categories, t.contacts));
        }
        function zypadVsAmd(z) {
            var box = document.getElementById('zypadbox'); box.textContent = '';
            if (!z || !z.rows || !z.rows.length) { box.appendChild(el('p', 'muted', 'Todavía no hay contactos llamados con Zypad en este rango.')); return; }
            z.rows.forEach(function (r) {
                var h = el('h3', '', r.label + ' · ' + r.contacts + ' contactos'); h.style.margin = '0.5rem 0 0.2rem'; if (r.contacts < MIN) h.appendChild(el('span', 'tag-small', 'pocos datos')); box.appendChild(h);
                var amd = n(r.casilla_amd), ul = el('ul', 'says');
                function li(parts) { var x = el('li'); parts.forEach(function (p) { x.appendChild(typeof p === 'string' ? document.createTextNode(p) : p); }); ul.appendChild(x); }
                function b(t) { return el('strong', '', t); }
                if (!amd) li(['El AMD no marcó ninguna casilla de voz en este rango.']);
                else li(['Zypad detectó ', b(n(r.ambos) + ' de las ' + amd), ' casillas de voz que marcó el AMD (', rp(n(r.ambos), amd) + ' %), antes de contestar.']);
                if (n(r.solo_zypad)) {
                    var parts = [b(String(n(r.solo_zypad))), ' contactos con la palabra de Zypad que el AMD no marcó como casilla: ', b(String(n(r.solo_zypad_amd_humano))), ' los atendió una persona (a revisar con la grabación)'];
                    if (r.solo_zypad_sin_contestar != null) parts.push(' y ', b(String(n(r.solo_zypad_sin_contestar))), ' no contestaron');
                    li(parts.concat(['.']));
                }
                box.appendChild(ul);
            });
        }

        function notes(d) {
            var box = document.getElementById('notes'); box.textContent = '';
            if (d.extra && d.extra.sip && d.extra.sip.partial) box.appendChild(el('p', '', 'Hay ' + n(d.extra.sip.partial_calls) + ' llamadas sin código SIP final capturado (las de la primera tanda de pruebas): sus causas aparecen como «sin código» o «provisional».'));
            (d.warnings || []).forEach(function (w) { box.appendChild(el('p', '', w)); });
        }

        // Reparte las filas de comparación: una fila por elemento con su barra al 100 % de contactos.
        function cmpRows(box, items, cats, nameOf, subOf) {
            box.textContent = '';
            var ls = items.slice().sort(function (a, b) { return (a.needle.no_data - b.needle.no_data) || (n(b.needle.pct) - n(a.needle.pct)); });
            ls.forEach(function (l) {
                var r = el('div', 'cmp'), nm = el('div', 'nm', nameOf(l)); nm.title = nameOf(l) + ' · ' + subOf(l); nm.appendChild(el('small', '', subOf(l)));
                r.appendChild(nm); r.appendChild(stack(l.counts, l.pct, cats, l.contacts));
                var rt = el('div', 'rt'), st = el('strong', '', l.needle.no_data ? 'Sin datos' : rp(l.needle.human, l.needle.called) + ' % humanos');
                rt.appendChild(st); rt.appendChild(document.createElement('br'));
                rt.appendChild(document.createTextNode(l.needle.no_data ? l.contacts + ' contactos' : l.needle.human + ' de ' + l.needle.called + ' contactos llamados'));
                if (l.contacts < MIN || l.needle.no_data) { rt.appendChild(document.createElement('br')); rt.appendChild(el('span', 'tag-small', 'pocos datos')); }
                r.appendChild(rt); box.appendChild(r);
            });
            return ls;
        }
        function compareLists(ct) {
            var sec = document.getElementById('cmpsec'), csec = document.getElementById('cmpcampsec'), tb = document.getElementById('cmptable'); tb.textContent = '';
            var lists = ct && ct.lists || [], camps = ct && ct.campaigns || [];
            sec.hidden = lists.length < 2; csec.hidden = camps.length < 2; // una sola lista o campaña no compara nada
            if (!sec.hidden) {
                keyOf(document.getElementById('cmpkey'), ct.categories);
                var ls = cmpRows(document.getElementById('cmprows'), lists, ct.categories, function (l) { return l.list; }, function (l) { return l.campaign + (l.state_label ? ' · ' + l.state_label : ''); });
                var cols = ct.categories.filter(function (c) { return ls.some(function (l) { return n(l.counts[c.key]) > 0; }); }).map(function (c) { return c.key; }), lab = {}; ct.categories.forEach(function (c) { lab[c.key] = c.label; });
                tb.appendChild(table(['Lista', 'Campaña'].concat(cols.map(function (k) { return lab[k]; })).concat(['Total']),
                    ls.map(function (l) { return [l.list, l.campaign].concat(cols.map(function (k) { return n(l.counts[k]) + ' (' + rp(n(l.counts[k]), l.contacts) + ' %)'; })).concat([l.contacts + ' (100 %)']); })));
            }
            if (!csec.hidden) cmpRows(document.getElementById('cmpcamprows'), camps, ct.categories, function (c) { return c.campaign; }, function (c) { return c.lists + (c.lists === 1 ? ' lista' : ' listas'); });
        }

        // Frases bajo «¿Qué pasó con mis contactos?»: contestadas, transferidas y pasos, todo calculado con los datos que ya llegan.
        function contactSays(ct, rep) {
            var ul = document.getElementById('contactsays'); ul.textContent = '';
            if (!ct || !ct.total || !ct.total.contacts) return;
            var t = ct.total, c = t.counts, ans = n(c.humano) + n(c.casilla_voz) + n(c.no_seguro) + n(c.contestada_sin_amd), called = t.contacts - n(c.sin_llamar);
            function li(parts) { var x = el('li'); parts.forEach(function (p) { x.appendChild(typeof p === 'string' ? document.createTextNode(p) : p); }); ul.appendChild(x); }
            function b(v) { return el('strong', '', String(v)); }
            var det = [['humano', 'humanos'], ['casilla_voz', 'casillas de voz'], ['no_seguro', 'no seguros'], ['contestada_sin_amd', 'contestadas sin AMD']].filter(function (d) { return n(c[d[0]]) > 0; }).map(function (d) { return n(c[d[0]]) + ' ' + d[1]; });
            li(['Contestaron ', b(ans + ' de ' + t.contacts), ' contactos' + (det.length ? ': ' + det.slice(0, -1).join(', ') + (det.length > 1 ? ' y ' : '') + det[det.length - 1] : '') + '.']);
            var xok = rep && rep.transfers ? n(rep.transfers.ok) : 0, hns = n(c.humano) + n(c.no_seguro), xshow = xok > 0 && xok <= hns;
            if (xshow) li(['Transferidas a tu destino: ', b(xok), ' de esos ' + hns + ' humanos y no seguros.']);
            else if (xok) li(['Transferidas a tu destino: ', b(xok), ' (cuenta intentos).']);
            li(['Pasos: llamados ', b(called), ' → contestaron ', b(ans), ' → humanos y no seguros ', b(hns), xshow ? ' → transferidas ' : '', xshow ? b(xok) : '', '.']);
        }

        // Etiqueta en español llano de un resultado de intento. Sigue el contrato de categorías: 480 y 408 = No disponible, 486 y 600 = Ocupado, 487 = Sin respuesta.
        function attemptLabel(code, disp) {
            var c = String(code || '');
            if (c === '480' || c === '408') return 'No disponible';
            if (c === '486' || c === '600') return 'Ocupado';
            if (c === '487') return 'Sin respuesta';
            if (c === '200' || disp === 'AMD_MACHINE' || disp === 'XFER_OK' || disp === 'ANSWER_HUMAN' || disp === 'XFER_FAIL') {
                if (disp === 'AMD_MACHINE') return 'Casilla de voz';
                if (disp === 'XFER_OK') return 'Transferida a tu destino';
                if (disp === 'XFER_FAIL') return 'Contestó pero falló la transferencia';
                if (disp === 'ANSWER_HUMAN') return 'Contestó y se cortó sin AMD';
                return 'Contestó';
            }
            if (disp === 'NOANSWER') return 'Sin respuesta';
            return 'Falló';
        }
        function sip(sp) {
            var box = document.getElementById('sipcodes'); box.textContent = '';
            if (!sp || !sp.codes || !sp.codes.length) { empty(box); return; }
            var g = {}, order = [];
            sp.codes.forEach(function (c) { var l = attemptLabel(c.code, c.disposition); if (!g[l]) { g[l] = { calls: 0, codes: [] }; order.push(l); } g[l].calls += n(c.calls); if (c.code && g[l].codes.indexOf(String(c.code)) < 0) g[l].codes.push(String(c.code)); });
            order.sort(function (a, b) { return g[b].calls - g[a].calls; });
            var tot = order.reduce(function (a, l) { return a + g[l].calls; }, 0), t = table(['Qué pasó', 'Intentos', 'Código SIP'], order.map(function (l) { return [l, g[l].calls + ' (' + fx(pct(g[l].calls, tot)) + ' %)', g[l].codes.join(', ') || '—']; }));
            Array.from(t.querySelectorAll('tbody tr')).forEach(function (r) { r.lastChild.style.fontSize = '0.68rem'; r.lastChild.style.color = 'var(--text-muted)'; });
            box.appendChild(t); box.appendChild(el('p', 'muted', 'Total: ' + tot + ' intentos.'));
        }

        // Intentos en que el AMD dijo HUMANO (la misma cifra que «Resultado del AMD»). No usar kpi.human: suma también las contestadas sin AMD.
        function amdHuman(x) { return ((x && x.amd_by_cause) || []).reduce(function (a, r) { return a + (r.amd_status === 'HUMAN' ? n(r.calls) : 0); }, 0); }
        function kpis(k, x) {
            var box = document.getElementById('kpis'); box.textContent = ''; var calls = n(k.calls);
            [['Intentos', calls, 'llamadas hechas, reintentos incluidos', '#3b82f6'],
             ['Contestados', n(k.answered), 'intentos en que alguien contestó', '#3b82f6'],
             ['Casilla de voz', n(k.machine), 'intentos que cayeron en una casilla', '#3b82f6'],
             ['Atendidos por una persona', amdHuman(x), 'intentos en que el AMD detectó una persona', '#10b981'],
             ['No disponible u ocupado', n(k.unavailable), 'intentos sin conexión con el cliente', '#f59e0b'],
             ['Sin respuesta', n(k.no_answer), 'intentos que nadie contestó', '#9ca3af'],
             ['Fallaron', n(k.failed), 'intentos con error', '#ef4444'],
             ['Transferidas a tu destino', n(k.xfer_ok), 'intentos pasados a tu destino', '#10b981']]
            .forEach(function (x) { var c = el('div', 'kpi'); c.style.setProperty('--kc', x[3]); c.appendChild(el('div', 'l', x[0])); c.appendChild(el('div', 'v', String(x[1]))); c.appendChild(el('div', 'b', (calls > 0 && x[0] !== 'Intentos' ? rp(x[1], calls) + ' % de los intentos · ' : '') + x[2])); box.appendChild(c); });
        }

        function trend(days, humans) {
            var box = document.getElementById('trend'); box.textContent = '';
            if (!days || !days.length) { empty(box); return; }
            if (days.length < 2) { var d0 = days[0]; box.appendChild(el('p', '', 'Todo ocurrió el ' + d0.day_local + ': ' + n(d0.calls) + ' intentos, ' + n(d0.answered) + ' contestados y ' + humans + ' atendidos por una persona (según el AMD).')); return; }
            var W = Math.max(640, days.length * 60), H = 170, L = 38, R = 24, T = 10, B = 24, pw = W - L - R, ph = H - T - B;
            var s = svg(W, H); s.setAttribute('aria-label', 'Intentos por día');
            var series = [['answered', C.answered, 'Contestados']];
            var vals = days.map(function (d) { return { a: pct(n(d.answered), n(d.calls)), h: null, calls: n(d.calls) }; });
            var maxp = Math.max(10, Math.ceil(Math.max.apply(null, vals.map(function (v) { return Math.max(v.a || 0, v.h || 0); })) / 10) * 10);
            [0, 0.5, 1].forEach(function (f) { var y = T + ph - f * ph; se(s, 'line', { x1: L, x2: W - R, y1: y, y2: y, stroke: '#374151', 'stroke-width': 1 }); se(s, 'text', { x: 2, y: y + 4, 'font-size': 10, fill: '#6b7280' }, Math.round(f * maxp) + ' %'); });
            var step = pw / Math.max(1, days.length - 1);
            series.forEach(function (sr, si) {
                var pts = [];
                days.forEach(function (d, i) { var v = si === 0 ? vals[i].a : vals[i].h; if (v === null) return; pts.push([L + i * step, T + ph - v / maxp * ph, v, i]); });
                if (pts.length > 1) se(s, 'polyline', { points: pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' '), fill: 'none', stroke: sr[1], 'stroke-width': 2 });
                pts.forEach(function (p) { var small = vals[p[3]].calls < MIN; var c = se(s, 'circle', { cx: p[0], cy: p[1], r: 4, fill: small ? '#fff' : sr[1], stroke: sr[1], 'stroke-width': 2 }); var t = document.createElementNS(SVGNS, 'title'); t.textContent = days[p[3]].day_local + ' · ' + sr[2] + ': ' + fx(p[2]) + ' % de ' + vals[p[3]].calls + ' intentos' + (small ? ' (pocos datos)' : ''); c.appendChild(t); });
            });
            days.forEach(function (d, i) { if (i % Math.ceil(days.length / 10) === 0) se(s, 'text', { x: L + i * step, y: H - 6, 'font-size': 10, fill: '#6b7280', 'text-anchor': i === days.length - 1 ? 'end' : 'middle' }, d.day_local.slice(5)); });
            box.appendChild(s);
            var lg = el('div', 'legend'); series.forEach(function (sr) { var sp = el('span'), i = el('i'); i.style.background = sr[1]; sp.appendChild(i); sp.appendChild(document.createTextNode(sr[2] + ' (% de los intentos del día)')); lg.appendChild(sp); }); box.appendChild(lg);
        }

        // ¿Sirven los reintentos? Frases y tabla en lenguaje llano; solo cuenta lo ya hecho.
        function retries(x) {
            var ul = document.getElementById('retrysays'), tb = document.getElementById('retrytable'); ul.textContent = ''; tb.textContent = '';
            if (!x) return;
            function li(parts) { var e = el('li'); parts.forEach(function (p) { e.appendChild(typeof p === 'string' ? document.createTextNode(p) : p); }); ul.appendChild(e); }
            function b(v) { return el('strong', '', String(v)); }
            var apl = x.attempts_per_lead && x.attempts_per_lead.total, fc = x.first_contact_attempt || [];
            if (apl) {
                var names = ['con 1 intento', 'con 2', 'con 3', 'con 4 o más'], parts = [];
                for (var i = 1; i < 5; i++) if (n(apl[i]) > 0 || i === 1) parts.push(n(apl[i]) + ' ' + names[i - 1]);
                li([b(parts.join(', ').replace(/, ([^,]*)$/, ' y $1')), '. Son contactos llamados, contados una vez cada uno.']);
            }
            if (fc.length) {
                var first = n(fc[0].leads), total = n(fc[fc.length - 1].cumulative), more = total - first;
                li(['El primer intento alcanzó a ', b(first + ' de ' + total), ' contactos que contestaron; los reintentos añadieron ', b(more), '.']);
            } else li(['Todavía no hay contactos que hayan contestado.']);
            li(['Solo cuenta los reintentos ya hechos: si la lista se detuvo antes de terminarlos, estas cifras pueden crecer.']);
            var rows = x.by_attempt;
            if (rows && rows.length) tb.appendChild(table(['Intento', 'Llamadas', 'Contestaron', 'Humanos'], rows.map(function (r) { return ['Intento ' + r.attempt_no, n(r.calls), n(r.answered), n(r.human)]; })));
            else if (fc.length) tb.appendChild(table(['Intento', 'Contactos que contestaron por primera vez', 'Acumulado de contactos que contestaron'], fc.map(function (r) { return ['Intento ' + r.attempt_no, n(r.leads), n(r.cumulative)]; })));
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
            box.appendChild(table(['Troncal', 'Intentos', 'Contestados', 'Humano (con o sin AMD)', 'Fallaron'], cr.rows.map(function (r) { var c = n(r.calls); return [r.carrier, c, fp(n(r.answered), c), fp(n(r.human), c), fp(n(r.failed), c)]; })));
        }

        function matrix(cells) {
            var box = document.getElementById('matrix'); box.textContent = '';
            if (!cells || !cells.length) { empty(box); return; }
            var hours = cells.map(function (c) { return n(c.hour_local); }), h0 = Math.min.apply(null, hours), h1 = Math.max.apply(null, hours), map = {}, days = [];
            cells.forEach(function (c) { map[c.weekday + '_' + c.hour_local] = c; if (days.indexOf(n(c.weekday)) < 0) days.push(n(c.weekday)); });
            days.sort(function (a, b) { return a - b; });
            var t = el('table', 'matrix'), hr = el('tr'); hr.appendChild(el('th', '', ''));
            for (var h = h0; h <= h1; h++) hr.appendChild(el('th', '', h + ' h')); t.appendChild(hr);
            days.forEach(function (d) {
                var tr = el('tr'); tr.appendChild(el('th', '', DAYS[d]));
                for (var h = h0; h <= h1; h++) {
                    var c = map[d + '_' + h], td = el('td'); if (!c) { td.className = 'empty'; tr.appendChild(td); continue; }
                    var calls = n(c.calls), p = pct(n(c.answered), calls) || 0, a = 0.15 + 0.85 * Math.min(1, p / 100);
                    td.style.background = 'rgba(37,99,235,' + a + ')'; td.style.color = a > 0.5 ? '#fff' : '#111827'; td.textContent = Math.round(p) + '%';
                    td.title = DAYS[d] + ' ' + h + ':00 · ' + fp(n(c.answered), calls) + ' intentos contestados' + (calls < MIN ? ' (muestra pequeña)' : ''); if (calls < MIN) td.className = 'low'; tr.appendChild(td);
                }
                t.appendChild(tr);
            });
            box.appendChild(t); box.appendChild(el('p', 'muted', 'Cada celda: % contestadas. Pasa el cursor para ver cuántos intentos hay detrás.'));
        }
        function detail(d) {
            var box = document.getElementById('detail'); box.textContent = '';
            var DM = { AMD: 'Solo AMD', ZYPAD_SOMBRA: 'Zypad sombra', ZYPAD_CORTE: 'Zypad corte' };
            box.appendChild(table(['Inicio (Lima)', 'Campaña', 'Lista', 'Teléfono', 'Tipo', 'Estado', 'AMD', 'Zypad', 'SIP', 'Q.850', 'Seg.'],
                d.rows.map(function (r) { return [r.started_at_local, r.campaign, r.list, r.phone, DM[r.dial_mode] || r.dial_mode || 'Solo AMD', r.estado_label || '', r.amd_valid ? (r.amd_status || '') : (r.amd_status ? r.amd_status + ' (no válido)' : ''), (r.palabra ? '«' + r.palabra + '» ' + (r.momento || '') + (r.segundo != null && r.segundo !== '' ? ' ' + r.segundo + ' s' : '') + (r.preanswer_audio === 'SI' ? ' · audio previo' : '') : (r.preanswer_audio === 'SI' ? 'audio previo' : '')), r.sip_cause, r.q850, r.billsec]; })));
            // El teléfono completo se pinta en una sola línea y se selecciona de un clic para copiarlo.
            Array.from(box.querySelectorAll('tbody tr')).forEach(function (tr) { var td = tr.children[3]; if (td) { td.style.whiteSpace = 'nowrap'; td.style.userSelect = 'all'; td.style.fontVariantNumeric = 'tabular-nums'; } });
            var sel = document.getElementById('f_estado'), est = d.estados || {}, sum = Object.keys(est).reduce(function (a, k) { return a + n(est[k]); }, 0);
            sel.textContent = ''; var o0 = el('option', '', 'Todos (' + sum + ')'); o0.value = ''; sel.appendChild(o0);
            (d.estado_list || []).forEach(function (e) { if (!n(est[e.key]) && e.key !== detailEstado && (e.key === 'transferencia_fallida' || e.key === 'contesto')) return; var o = el('option', '', e.label + ' (' + n(est[e.key]) + ')'); o.value = e.key; sel.appendChild(o); });
            sel.value = detailEstado;
            lastPages = Math.max(1, n(d.pages) || Math.ceil(d.total / d.per_page));
            document.getElementById('pageinfo').textContent = 'Página ' + d.page + ' de ' + lastPages + ' · ' + d.total + ' llamadas' + (detailEstado ? ' con ese estado' : '');
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
        function quick(from, to) { document.getElementById('scope').textContent = ''; form.elements.from.value = from; form.elements.to.value = to; page = 1; load(); }
        var q = document.getElementById('quick');
        [['Hoy', 0, 0], ['Ayer', -1, -1], ['Últimos 7 días', -6, 0], ['Últimos 30 días', -29, 0]].forEach(function (x) { var b = el('button', 'btn-action', x[0]); b.type = 'button'; b.addEventListener('click', function () { quick(limaDate(x[1]), limaDate(x[2])); }); q.appendChild(b); });
        var all = el('button', 'btn-action', 'Todo el historial'); all.type = 'button'; all.addEventListener('click', function () { quick('', ''); }); q.appendChild(all);

        // Recarga solo el detalle de intentos (cambio de Estado o de página); las demás secciones no se tocan.
        function loadDetail() {
            document.getElementById('csv').href = API + '?' + query({ export: 'csv' });
            fetch(API + '?' + query({ page: page }), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (j) { document.getElementById('error').textContent = ''; detail(j.report.detail); })
                .catch(function (e) { var b = document.getElementById('error'); b.textContent = ''; b.appendChild(el('p', 'rep-error', 'No se pudo cargar el detalle: ' + e.message)); });
        }
        function load() {
            document.getElementById('csv').href = API + '?' + query({ export: 'csv' });
            fetch(API + '?' + query({ page: page }), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Error'); return j; }); })
                .then(function (j) {
                    document.getElementById('error').textContent = '';
                    if (!optionsLoaded) {
                        fillOptions(j.options);
                        if (pending) {
                            var camp = j.options.filter(function (o) { return String(o.campaign_id) === pending.campaign_id; })[0], lst = camp && camp.lists.filter(function (x) { return String(x.list_id) === pending.list_id; })[0];
                            if (!lst) { pending = null; load(); return; } // lista inexistente o de otra campaña: comportamiento normal
                            document.getElementById('f_campaign').value = pending.campaign_id; fillLists(); document.getElementById('f_list').value = pending.list_id;
                            document.getElementById('scope').textContent = 'Mostrando toda la vida de la lista «' + lst.name + '» (sin límite de fechas, con los contactos aún sin llamar). Elige fechas y pulsa Aplicar para acotar.';
                            pending = null;
                        }
                    }
                    var r = j.report, x = j.extra;
                    notes(j); contactsTotal(j.contacts); contactSays(j.contacts, r); compareLists(j.contacts); zypadVsAmd(j.zypad_amd); kpis(r.kpi, x); trend(x && x.by_day, amdHuman(x));
                    dialmodes(j.dialmodes); if (x) { sip(x.sip); amd(x.amd_by_cause); carriers(x.by_carrier); matrix(x.by_weekday_hour); } retries(x);
                    xfer(r.transfers); detail(r.detail);
                })
                .catch(function (e) { if (pending) { pending = null; load(); return; } var b = document.getElementById('error'); b.textContent = ''; b.appendChild(el('p', 'rep-error', 'No se pudo cargar el informe: ' + e.message)); });
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); document.getElementById('scope').textContent = ''; page = 1; load(); });
        document.getElementById('f_campaign').addEventListener('change', fillLists);
        document.getElementById('prev').addEventListener('click', function () { if (page > 1) { page--; loadDetail(); } });
        document.getElementById('next').addEventListener('click', function () { if (page < lastPages) { page++; loadDetail(); } });
        document.getElementById('f_estado').addEventListener('change', function () { detailEstado = this.value; page = 1; loadDetail(); });
        load();
    })();
    </script>
</body>
</html>

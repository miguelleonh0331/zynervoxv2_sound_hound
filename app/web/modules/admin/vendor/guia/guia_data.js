/* Guía interactiva: datos de DEMOSTRACIÓN y temas del recorrido.
 * Todo es inventado a partir de rangos reales (≈13 % de humanos, ≈40 % de casillas, hasta 25 canales, listas de 400 a 2000 contactos).
 * Ningún número, nombre de persona, monto ni dirección es real: los teléfonos son 900 000 0xx. Estos datos viven solo aquí, no en la base.
 * Las pantallas reales se cargan dentro de la guía y su fetch se sustituye por esta función (ver guia.js): nunca sale una petición de escritura. */
(function () {
    'use strict';
    var D = window.GUIA_DEMO = {};

    function counts(contacts, called, hum, cas, open) {
        var c = {
            humano: Math.round(called * hum), casilla_voz: Math.round(called * cas), no_seguro: Math.round(called * 0.01), contestada_sin_amd: Math.round(called * 0.005),
            no_disponible: Math.round(called * 0.18), sin_respuesta: 0, ocupado: Math.round(called * 0.03), errores: 1, bloqueado: 0, en_llamada: open, sin_llamar: contacts - called
        };
        var s = 0; Object.keys(c).forEach(function (k) { s += c[k]; });
        c.sin_respuesta = Math.max(0, contacts - s);
        return c;
    }
    var nextId = 900;
    function mkList(name, contacts, called, hum, cas, channels, open, state) {
        var id = ++nextId, c = counts(contacts, called, hum, cas, open), live = state !== 'stopped';
        return {
            list_id: id, name: name, active: true, started: live, reason: state === 'retry' ? 'Primera pasada terminada · esperando reintentos (45 pendientes)' : (live ? '' : 'Lista detenida'),
            channels: channels, open_calls: open, progress_pct: Math.round(100 * called / contacts), totals: { calls: Math.round(called * 1.15), contacts: contacts, not_called: contacts - called },
            insight: { temperature: hum >= 0.1 ? 'Caliente' : (hum >= 0.05 ? 'Tibia' : 'Fría'), recommendation: 'Seguir', thresholds_provisional: true, thresholds: { hot_human_pct: 10, warm_human_pct: 5, min_called_contacts: 20 } },
            distribution: { contacts: contacts, counts: c, pct: {}, needle: { human: c.humano, called: called, contacts: contacts, pct: called ? Math.round(1000 * c.humano / called) / 10 : 0, no_data: called < 20, label: c.humano + ' de ' + called + ' contactos llamados', min_called: 20 } },
            last10: { calls: 50, human: Math.round(50 * hum), window: 50, no_data: false, label: '', basis: 'intentos' }
        };
    }
    function dashboard() {
        var camps = [
            { campaign_id: 1, name: 'COMPRA_DEUDA', active: true, started: true, lists: [mkList('demo_compra_01', 2000, 1450, 0.13, 0.40, 25, 24, 'live'), mkList('demo_compra_02', 400, 380, 0.08, 0.38, 5, 4, 'live'), mkList('demo_compra_03', 800, 90, 0.04, 0.41, 10, 3, 'live'), mkList('demo_compra_04', 600, 0, 0, 0, 5, 0, 'stopped')] },
            { campaign_id: 2, name: 'REFINANCIAMIENTO', active: true, started: true, lists: [mkList('demo_refi_01', 1200, 700, 0.115, 0.39, 10, 9, 'live'), mkList('demo_refi_02', 600, 600, 0.06, 0.42, 5, 0, 'retry'), mkList('demo_refi_03', 500, 0, 0, 0, 5, 0, 'stopped')] },
            { campaign_id: 3, name: 'TARJETA_CREDITO', active: true, started: true, lists: [mkList('demo_tarjeta_01', 2000, 1900, 0.15, 0.40, 20, 19, 'live'), mkList('demo_tarjeta_02', 400, 120, 0.09, 0.37, 5, 5, 'live'), mkList('demo_tarjeta_03', 700, 0, 0, 0, 10, 0, 'stopped')] }
        ];
        var open = 0; camps.forEach(function (c) { c.lists.forEach(function (l) { open += l.open_calls; }); });
        return {
            ok: true, at: new Date().toISOString(),
            server: { cpu_pct: 18, ram_pct: 41, ram_used: 4.7 * 1073741824, ram_total: 11.4 * 1073741824, disk_pct: 3, disk_used: 8.4 * 1073741824, disk_total: 274.6 * 1073741824 },
            services: [{ key: 'asterisk', label: 'Asterisk', on: true }, { key: 'mysql', label: 'MySQL', on: true }, { key: 'apache', label: 'Apache', on: true }, { key: 'dialer', label: 'Motor de marcación', on: true }],
            dashboard: { server: { max_channels: 100, open_calls: open, engine_alive: true }, campaigns: camps }, dashboard_error: ''
        };
    }

    // ---------------- Reportes: datos inventados con la misma forma que reports_api.php ----------------
    var CATS = [['humano', 'Humano', 'verde'], ['casilla_voz', 'Casilla de voz', 'azul'], ['no_seguro', 'No seguro', 'violeta'], ['contestada_sin_amd', 'Contestada sin AMD', 'celeste'], ['no_disponible', 'No disponible', 'ambar'], ['sin_respuesta', 'Sin respuesta', 'gris'], ['ocupado', 'Ocupado', 'naranja'], ['errores', 'Errores', 'rojo'], ['bloqueado', 'Bloqueado', 'negro'], ['en_llamada', 'En llamada', 'azul oscuro'], ['sin_llamar', 'Sin llamar', 'gris claro']];
    var ESTADOS = [['casilla_voz', 'Casilla de voz'], ['transferida', 'Transferida a tu destino'], ['sin_respuesta', 'Sin respuesta'], ['no_disponible', 'No disponible'], ['ocupado', 'Ocupado'], ['contesto_sin_amd', 'Contestó y se cortó sin AMD'], ['fallo', 'Falló']];
    function pctOf(c, total) { var p = {}; Object.keys(c).forEach(function (k) { p[k] = total ? Math.round(1000 * c[k] / total) / 10 : 0; }); return p; }
    function needleOf(c, contacts) { var called = contacts - (c.sin_llamar || 0); return { human: c.humano, called: called, contacts: contacts, pct: called ? Math.round(1000 * c.humano / called) / 10 : 0, no_data: called < 20, label: c.humano + ' de ' + called + ' contactos llamados', min_called: 20 }; }
    function block(c) { var t = 0; Object.keys(c).forEach(function (k) { t += c[k]; }); return { contacts: t, counts: c, pct: pctOf(c, t), needle: needleOf(c, t) }; }
    function addCounts(a, b) { var o = {}; Object.keys(a).forEach(function (k) { o[k] = a[k] + (b[k] || 0); }); return o; }
    function fixCounts(c) { c.bloqueado = c.bloqueado || 0; return c; }

    var REPLISTS = [
        { cid: 1, camp: 'COMPRA_DEUDA', id: 901, name: 'demo_compra_01', contacts: 2000, called: 2000, hum: 0.133, cas: 0.405, state: 'terminada' },
        { cid: 1, camp: 'COMPRA_DEUDA', id: 902, name: 'demo_compra_02', contacts: 400, called: 400, hum: 0.09, cas: 0.38, state: 'terminada' },
        { cid: 2, camp: 'REFINANCIAMIENTO', id: 904, name: 'demo_refi_01', contacts: 1200, called: 1200, hum: 0.115, cas: 0.39, state: 'detenida' },
        { cid: 2, camp: 'REFINANCIAMIENTO', id: 905, name: 'demo_refi_02', contacts: 600, called: 580, hum: 0.06, cas: 0.42, state: 'en curso' },
        { cid: 3, camp: 'TARJETA_CREDITO', id: 907, name: 'demo_tarjeta_01', contacts: 2000, called: 2000, hum: 0.15, cas: 0.4, state: 'terminada' }
    ];
    var POOL = null;
    function pool() { // 120 intentos de ejemplo con teléfonos ficticios 900 000 0xx
        if (POOL) return POOL; POOL = [];
        var plan = [['casilla_voz', 45], ['transferida', 15], ['sin_respuesta', 25], ['no_disponible', 18], ['ocupado', 10], ['contesto_sin_amd', 3], ['fallo', 4]], n = 0;
        var info = { casilla_voz: ['AMD_MACHINE', 'MACHINE', 'SIP 200 OK', 16, 'Casilla de voz'], transferida: ['XFER_OK', 'HUMAN', 'SIP 200 OK', 16, 'Transferida a tu destino'], sin_respuesta: ['NOANSWER', '', 'SIP 487 Request Terminated', 16, 'Sin respuesta'], no_disponible: ['BUSY', '', 'SIP 480 Temporarily Unavailable', 18, 'No disponible'], ocupado: ['BUSY', '', 'SIP 486 Busy Here', 17, 'Ocupado'], contesto_sin_amd: ['ANSWER_HUMAN', '', 'SIP 200 OK', 16, 'Contestó y se cortó sin AMD'], fallo: ['FAILED', '', 'SIP 500 Server Internal Error', 41, 'Falló'] };
        plan.forEach(function (p) { for (var i = 0; i < p[1]; i++) { n++; var f = info[p[0]], L = REPLISTS[n % REPLISTS.length], mm = (59 - (n * 7) % 60), hh = 11 - (n % 3);
            POOL.push({ attempt_id: 5000 - n, started_at_local: '2026-11-06 ' + (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm + ':' + ((n * 13) % 60 < 10 ? '0' : '') + (n * 13) % 60, amd_valid: true, campaign_id: L.cid, campaign: L.camp, list_id: L.id, list: L.name, lead_id: 100 + n,
                phone: '900000' + String(100 + (n % 90)).slice(-3), status: 'ANSWER', disposition: f[0], amd_status: f[1], amd_cause: '', sip_cause: f[2], q850: f[3], billsec: p[0] === 'transferida' ? 14 + (n % 20) : (p[0] === 'casilla_voz' ? 9 : 0), dial_mode: 'ZYPAD_SOMBRA', palabra: '', momento: '', segundo: null, preanswer_audio: 'SI', result_final: '', estado: p[0], estado_label: f[4] }); } });
        return POOL;
    }
    function reportsData(q) {
        var lists = REPLISTS.map(function (L) {
            var c = counts(L.contacts, L.called, L.hum, L.cas, 0); c.bloqueado = 0; var b = block(c);
            return { campaign_id: L.cid, campaign: L.camp, list_id: L.id, list: L.name, list_deleted: false, state: L.state, state_label: L.state, first_call: '2026-11-05 09:12:04', last_call: '2026-11-06 11:48:51',
                attempts: { label: 'intentos', attempts: Math.round(L.called * 1.05), answered_attempts: Math.round(L.called * 0.55), transfers_ok: Math.round(L.called * L.hum) }, contacts: b.contacts, counts: b.counts, pct: b.pct, needle: b.needle };
        });
        var tot = lists.reduce(function (a, l) { return a ? addCounts(a, l.counts) : addCounts(l.counts, {}); }, null), camps = {};
        lists.forEach(function (l) { var k = l.campaign_id; camps[k] = camps[k] ? { campaign_id: k, campaign: l.campaign, lists: camps[k].lists + 1, counts: addCounts(camps[k].counts, l.counts) } : { campaign_id: k, campaign: l.campaign, lists: 1, counts: addCounts(l.counts, {}) }; });
        var campaigns = Object.keys(camps).map(function (k) { var c = camps[k], b = block(c.counts); return { campaign_id: c.campaign_id, campaign: c.campaign, lists: c.lists, contacts: b.contacts, counts: b.counts, pct: b.pct, needle: b.needle }; });
        var T = block(tot), calls = Math.round(T.contacts * 1.08), answered = Math.round(calls * 0.52), human = Math.round(T.counts.humano * 1.06), machine = Math.round(T.counts.casilla_voz * 1.0);
        var xfer = Math.round(T.counts.humano * 1.02);
        var kpi = { calls: calls, answered: answered, human: human, machine: machine, not_sure: T.counts.no_seguro, unavailable: Math.round(calls * 0.2), no_answer: Math.round(calls * 0.24), failed: 8, xfer_ok: xfer, xfer_fail: 0, avg_billsec: null, first_call: '2026-11-05 09:12:04', last_call: '2026-11-06 11:48:51', contact_pct: 52 };
        var day = function (d, f) { return { day_local: d, calls: Math.round(calls * f), answered: Math.round(answered * f), human: Math.round(human * f), machine: Math.round(machine * f), not_sure: 2, unavailable: Math.round(kpi.unavailable * f), no_answer: Math.round(kpi.no_answer * f), failed: 2, xfer_ok: Math.round(xfer * f), xfer_fail: 0 }; };
        var byDay = [day('2026-11-04', 0.28), day('2026-11-05', 0.4), day('2026-11-06', 0.32)];
        var cells = []; for (var wd = 1; wd <= 5; wd++) for (var h = 9; h <= 19; h++) { var cl = 30 + ((wd * 7 + h * 5) % 50), pr = 0.3 + 0.35 * Math.sin((h - 9) / 10 * Math.PI) - (wd === 5 ? 0.05 : 0); cells.push({ weekday: wd, hour_local: h, calls: cl, answered: Math.round(cl * pr), human: Math.round(cl * pr * 0.25), machine: Math.round(cl * pr * 0.7), not_sure: 0, unavailable: 0, no_answer: 0, failed: 0, xfer_ok: 0, xfer_fail: 0 }); }
        var codes = [{ code: '200', family: 'contestada', disposition: 'AMD_MACHINE', calls: machine }, { code: '487', family: 'cancelado', disposition: 'NOANSWER', calls: kpi.no_answer }, { code: '480', family: 'no_disponible', disposition: 'BUSY', calls: Math.round(kpi.unavailable * 0.7) }, { code: '200', family: 'contestada', disposition: 'XFER_OK', calls: xfer }, { code: '486', family: 'ocupado', disposition: 'BUSY', calls: Math.round(kpi.unavailable * 0.3) }, { code: '200', family: 'contestada', disposition: 'ANSWER_HUMAN', calls: 22 }, { code: '500', family: 'congestion', disposition: 'CONGESTION', calls: 5 }];
        var attempts = [{ attempt_no: 1, calls: T.contacts, answered: Math.round(T.contacts * 0.5), human: Math.round(T.counts.humano * 0.97) }, { attempt_no: 2, calls: 190, answered: 14, human: 6 }, { attempt_no: 3, calls: 60, answered: 2, human: 1 }, { attempt_no: 4, calls: 20, answered: 0, human: 0 }];
        var P = pool(), est = null, pg = 1, per = 25; q = q || {}; if (q.estado) est = q.estado; if (q.page) pg = Math.max(1, parseInt(q.page, 10) || 1);
        var rows = est ? P.filter(function (r) { return r.estado === est; }) : P, estados = {}; ESTADOS.forEach(function (e) { estados[e[0]] = P.filter(function (r) { return r.estado === e[0]; }).length; });
        var cz = Math.round(T.counts.casilla_voz);
        return {
            ok: true, warnings: [],
            report: { filters: {}, kpi: kpi, by_hour: [], by_list: [], by_amd: [], by_cause: [], transfers: { ok: xfer, failed: 0 },
                detail: { page: pg, per_page: per, total: rows.length, pages: Math.max(1, Math.ceil(rows.length / per)), rows: rows.slice((pg - 1) * per, pg * per), estados: estados, estado_list: ESTADOS.map(function (e) { return { key: e[0], label: e[1] }; }) } },
            extra: { filters: {}, by_day: byDay, by_weekday_hour: cells, funnel: { stages: [], leaks: {} }, attempts_per_lead: { total: [0, T.contacts - 250, 190, 40, 20], lists: [] },
                first_contact_attempt: [{ attempt_no: 1, leads: Math.round(answered * 0.97), cumulative: Math.round(answered * 0.97), cumulative_pct: 97 }, { attempt_no: 2, leads: Math.round(answered * 0.03), cumulative: answered, cumulative_pct: 100 }],
                sip: { families: [], codes: codes, partial: false, partial_calls: 0 },
                amd_by_cause: [{ amd_status: 'MACHINE', amd_cause: 'MAXWORDS', calls: Math.round(cz * 0.5) }, { amd_status: 'MACHINE', amd_cause: 'INITIALSILENCE', calls: Math.round(cz * 0.47) }, { amd_status: 'HUMAN', amd_cause: 'HUMAN', calls: human }, { amd_status: 'NOTSURE', amd_cause: 'TOOLONG', calls: T.counts.no_seguro }],
                by_carrier: { multiple: false, rows: [] }, by_attempt: attempts },
            contacts: { filters: {}, source: 'summary', note: 'Contactos de cada lista por su último resultado.', categories: CATS.map(function (c) { return { key: c[0], label: c[1], color: c[2] }; }), total: T, campaigns: campaigns, lists: lists },
            zypad_amd: { filters: {}, rows: [{ dial_mode: 'ZYPAD_SOMBRA', label: 'Zypad en sombra + AMD', contacts: T.contacts, small_sample: false, casilla_zypad: Math.round(cz * 0.75), casilla_amd: cz, ambos: Math.round(cz * 0.62), solo_zypad: Math.round(cz * 0.13), solo_amd: Math.round(cz * 0.38), solo_zypad_amd_humano: 8, solo_zypad_sin_contestar: Math.round(cz * 0.13) - 8, solo_zypad_otros: 0 }] },
            dialmodes: { filters: {}, rows: [{ dial_mode: 'ZYPAD_SOMBRA', label: 'Zypad en sombra + AMD', calls: calls, preanswer_audio: Math.round(calls * 0.99), amd_decided: Math.round(calls * 0.52), amd_machine: machine, zypad_hits: Math.round(cz * 0.75), casilla_voz: cz, both_casilla: Math.round(cz * 0.62), only_zypad: Math.round(cz * 0.13), only_amd: Math.round(cz * 0.38), only_zypad_amd_human: 8, saved_n: 500, preanswer_audio_pct: 99, casilla_voz_pct: 38.7, saved_seconds_total: 1789.6, saved_seconds_avg: 3.5, small_sample: false }] },
            summary: { lists: [], campaigns: [] },
            options: [{ campaign_id: 1, name: 'COMPRA_DEUDA', lists: [{ list_id: 901, name: 'demo_compra_01' }, { list_id: 902, name: 'demo_compra_02' }] }, { campaign_id: 2, name: 'REFINANCIAMIENTO', lists: [{ list_id: 904, name: 'demo_refi_01' }, { list_id: 905, name: 'demo_refi_02' }] }, { campaign_id: 3, name: 'TARJETA_CREDITO', lists: [{ list_id: 907, name: 'demo_tarjeta_01' }] }]
        };
    }

    // ---------------- Test: campañas, pruebas recientes y la «creación» simulada ----------------
    var testCreated = [];
    function testData() {
        var base = [
            { list_id: 951, campaign_id: 1, campaign: 'COMPRA_DEUDA', phone: '900000011', created_at: '2026-11-06 14:02:10', state: 'terminada', estado: 'transferida', estado_label: 'Transferida a tu destino', resultado: 'AMD HUMAN · SIP 200 · 18 s' },
            { list_id: 950, campaign_id: 3, campaign: 'TARJETA_CREDITO', phone: '900000012', created_at: '2026-11-06 13:40:55', state: 'terminada', estado: 'casilla_voz', estado_label: 'Casilla de voz', resultado: 'AMD MACHINE · SIP 200 · 9 s' }
        ];
        return { ok: true, campaigns: [{ campaign_id: 1, name: 'COMPRA_DEUDA' }, { campaign_id: 2, name: 'REFINANCIAMIENTO' }, { campaign_id: 3, name: 'TARJETA_CREDITO' }, { campaign_id: 4, name: 'ZYPAD_SOMBRA' }], recent: testCreated.concat(base) };
    }
    /** Única «escritura» que la guía simula: crear la lista de prueba de ejemplo. No sale ninguna petición; solo cambia este arreglo en memoria. */
    D.respondPost = function (url, body) {
        if (!/(^|\/)test\.php(\?|$)/.test(String(url))) return null;
        var q = new URLSearchParams(body); if (q.get('action') !== 'create') return null;
        var cid = parseInt(q.get('campaign_id'), 10), names = { 1: 'COMPRA_DEUDA', 2: 'REFINANCIAMIENTO', 3: 'TARJETA_CREDITO', 4: 'ZYPAD_SOMBRA' };
        if (!cid || !names[cid]) return { ok: false, error: 'Elige una campaña.' };
        var phone = (q.get('phone') || '').replace(/\D/g, '');
        if (phone.length !== 9) return { ok: false, error: 'El número debe tener 9 dígitos (se acepta con prefijo 51 o +51, espacios o guiones).' };
        phone = '900000' + phone.slice(-3); // la guía nunca guarda ni muestra otro número que uno ficticio 900 000 0xx
        var id = 990 + testCreated.length;
        testCreated.unshift({ list_id: id, campaign_id: cid, campaign: names[cid], phone: phone, created_at: new Date().toISOString().replace('T', ' ').slice(0, 19), state: 'sin_iniciar', estado: null, estado_label: null, resultado: '' });
        return { ok: true, list: { list_id: id, campaign_id: cid, name: 'test-demo-' + id } };
    };
    D.reset = function () { testCreated = []; };

    /** Respuesta simulada para un GET de la pantalla real: {status, body}. Cualquier otra ruta no tiene datos de demostración. */
    D.respond = function (url) {
        var u = String(url), path = u.split('?')[0].split('/').pop(), qs = new URLSearchParams(u.indexOf('?') >= 0 ? u.slice(u.indexOf('?') + 1) : '');
        if (path === 'dashboard_api.php') return { status: 200, body: dashboard() };
        if (path === 'reports_api.php') { if (qs.get('export')) return { status: 403, body: { ok: false, error: 'Modo demostración: no hay descarga.' } }; return { status: 200, body: reportsData({ estado: qs.get('estado'), page: qs.get('page') }) }; }
        if (path === 'test.php') return { status: 200, body: testData() };
        return { status: 404, body: { ok: false, error: 'Sin datos de demostración para esta pantalla.' } };
    };

    // ---- Temas. page = pantalla real que se carga; sel/card/txt = elemento real que se resalta; action: 'click' (clic visual), 'real' (clic real SOLO en controles de interfaz sin red), 'type', 'select' (visuales) ----
    D.topics = [
        {
            id: 'dashboard', title: 'Dashboard', ready: true, icon: '◔',
            prep: function (doc, i) { var p = doc.getElementById('stpanel'); if (p) p.hidden = !(i === 11 || i === 12); }, // el panel de detenidas solo está abierto en los pasos 11 y 12 (el de «Listas detenidas» lo abre con un clic real de interfaz)
            about: 'El panel operativo: qué listas están llamando, cómo van y cómo pararlas o iniciarlas.',
            steps: [
                { page: 'index_v2.php', sel: '.top', text: 'La línea de arriba resume el servidor: CPU, memoria, disco, cuántas llamadas hay en curso y si Asterisk, MySQL, Apache y el motor de marcación están encendidos (punto verde = encendido).' },
                { sel: '#groups > .col', text: 'Cada columna es una campaña, con su nombre y su estado arriba. Dentro van sus listas, una debajo de otra. Solo aparecen las listas que están llamando ahora; el ancho se reparte entre las campañas que haya (hasta 6 por fila).' },
                { sel: '.cell a.nm', action: 'click', text: 'El nombre de la lista es un enlace a su reporte. El punto verde parpadea mientras discando; si está en ámbar, la lista espera sus reintentos.' },
                { sel: '.cell .gz', text: 'Dos agujas, mismo modelo: «Acumulado» (todos los contactos ya llamados) y «Últimas 50» (las 50 últimas llamadas). Azul = pocos humanos, ámbar = intermedio, verde = bueno. Si el acumulado supera el 10 %, el cuadro se resalta.' },
                { sel: '.cell .info', text: 'El número grande es el % de humanos entre los contactos ya llamados. Debajo: cuántos humanos hubo en las últimas 50 llamadas, el avance (contactos llamados / total) y los canales de la lista.' },
                { sel: '.cell .leg', text: 'La leyenda cuenta a cada contacto una sola vez, por su último resultado: Humano, Casilla de voz, No disponible, Sin respuesta… Solo salen las categorías que valen más de 0.' },
                { sel: '.cell .bar', text: 'La barra de colores es lo mismo en una sola línea: todos los contactos de la lista suman el 100 %. Pasa el cursor por un color para ver su cifra.' },
                { sel: '.cell .info select', action: 'select', value: '25', text: 'Canales asignados: cuántas llamadas puede hacer a la vez esta lista (de 1 hasta el máximo del servidor). Se cambia con este desplegable y se aplica en caliente, sin parar la lista.' },
                { sel: '.cell .ch button', txt: 'Detener', action: 'click', text: '«Detener» frena la lista; las llamadas que ya están en curso terminan solas. Pide confirmación. (En la guía no se detiene nada.)' },
                { sel: '.cell .ch button', txt: 'Desactivar', action: 'click', text: '«Desactivar» detiene la lista y la saca del panel. Para volver a usarla se activa de nuevo en la pantalla Listas.' },
                { sel: '#stbtn', action: 'real', text: '«Listas detenidas (N)» abre el panel de las listas que están activas pero paradas.' },
                { sel: '#stpanel .row .mini-btn', action: 'click', text: 'Cada una tiene su botón «Iniciar». Al pulsarlo pide confirmación porque el marcador llamará de verdad a los contactos. (En la guía no se inicia nada.)' },
                { sel: '#stbtn', action: 'real', text: 'Se vuelve a pulsar para cerrar el panel.' },
                { sel: '#startall', action: 'click', text: '«Iniciar todas» inicia todas las listas detenidas de golpe, con una confirmación que dice cuántas son. Si alguna falla, sigue con las demás y al final muestra un resumen.' },
                { sel: '#stopall', action: 'click', text: '«Parar todas» detiene todas las listas que están llamando, incluidas las que esperan reintentos. También pide confirmación.' },
                { sel: '#menubtn', action: 'click', text: '«Esconder menú» oculta el menú lateral para ganar sitio cuando hay muchas listas; el botón se queda para volver a mostrarlo y recuerda tu preferencia.' }
            ]
        },
        {
            id: 'campanas', title: 'Campañas', ready: true, icon: '◉',
            about: 'Cómo crear una campaña y qué significa cada opción: tipo de marcación, Blacklist, acción por resultado, reintentos y horarios.',
            steps: [
                { page: 'campaigns.php', sel: '.card table', text: 'Aquí se listan las campañas con su estado (activa, en marcha o detenida) y cuántas listas tienen. Desde cada fila se edita, se inicia o se detiene.' },
                { sel: 'a.btn-primary', txt: 'Nueva campaña', action: 'click', text: 'Con «Nueva campaña» se abre el formulario para crear una. Vamos a recorrerlo.' },
                { page: 'campaigns.php?view=new', sel: 'input[name=name]', action: 'type', value: 'CAMPAÑA_DEMO', text: 'Empieza por el nombre. Es solo para reconocerla en los paneles y reportes.' },
                { sel: 'select[name=active]', action: 'select', value: '1', text: '«Activa» decide si la campaña puede marcar. Una campaña inactiva no inicia llamadas aunque tenga listas.' },
                { sel: 'select[name=call_origin]', action: 'select', value: '7314', text: '«Tipo de marcación»: «Solo AMD» detecta con el AMD si contestó una persona o una casilla de voz; «Zypad en sombra + AMD» además escucha antes de contestar con Zypad, pero decide el AMD.' },
                { sel: 'select[name=blacklist_enabled]', action: 'select', value: '1', text: '«Blacklist» Sí/No (por defecto No): si es Sí, el marcador no llama a los números de la lista negra global; esos contactos salen como «Bloqueado». Los números se cargan solo por MySQL, no hay pantalla para eso.' },
                { sel: 'select[name=transfer_carrier_id]', text: 'Cuando contesta una persona, la llamada se transfiere. Aquí se elige la troncal por la que se transfiere…' },
                { sel: 'input[name=transfer_destination]', action: 'type', value: '900000010', text: '…y el destino: a quién llega la llamada transferida (un anexo o número). En la guía se usa un valor inventado.' },
                { card: 'Headers de la transferencia', text: 'Los headers son datos del contacto que viajan con la transferencia (por ejemplo su identificador) para que quien atiende vea de quién se trata.' },
                { card: 'Acción según el resultado del AMD', text: 'Qué hacer según lo que detecte el AMD: «Transferir», «Colgar y registrar» o «Reintentar» (con intentos máximos y minutos de espera). Típico: humano → transferir; casilla → reintentar más tarde.' },
                { card: 'Reintentos por código SIP final', text: 'Cuando la llamada no se conecta, el proveedor devuelve un código (por ejemplo «sin respuesta» u «ocupado»). Aquí se decide cuántas veces reintentar cada caso y con qué espera. Un código sin regla no se reintenta.' },
                { card: 'Franjas horarias', text: 'Los días y las horas en que la campaña puede marcar, en la zona horaria elegida. Sin franjas, marca a cualquier hora.' },
                { sel: '#cfg button[value=save]', action: 'click', text: '«Guardar» aplica los cambios. (En la guía no se guarda nada: es un ensayo.) Después de guardar, se asignan listas a la campaña desde la pantalla Listas.' }
            ]
        },
        {
            id: 'listas', title: 'Listas', ready: true, icon: '☰',
            about: 'Cargar la base de contactos con la plantilla CSV, activar y desactivar listas, canales y enlace a Reportes.',
            steps: [
                { page: 'lists.php', card: 'Límite del servidor', text: 'Arriba se fija el máximo de canales de todo el servidor: ninguna suma de llamadas simultáneas puede pasar de ese número, repartan como repartan las listas.' },
                { card: 'Listado de listas', text: 'Aquí están todas las listas de contactos, con su campaña, si están activas, cuántos contactos tienen, sus canales, cuántas llamadas hay abiertas ahora y su estado.' },
                { sel: '.table-compact tbody tr td:nth-child(3) a', action: 'click', text: 'El nombre de la lista es un enlace: abre Reportes con esa lista ya elegida, mostrando todo su historial.' },
                { sel: 'input[name=channels]', action: 'type', value: '10', text: 'Canales: cuántas llamadas simultáneas puede hacer esta lista (de 1 hasta el máximo del servidor). Se escribe el número…' },
                { sel: 'form button.btn-action', txt: 'Aplicar', action: 'click', text: '…y «Aplicar» lo guarda sin parar la campaña. (En la guía no se guarda.)' },
                { sel: 'form button.btn-action', txt: 'Activar', action: 'click', text: '«Activar» / «Desactivar»: una lista inactiva no marca ni sale en el Dashboard. Al desactivar una que está marchando, se detiene primero y se pide confirmación.' },
                { sel: 'form button.btn-action', txt: 'Eliminar', action: 'click', text: '«Eliminar» borra la lista y sus contactos (el historial de llamadas se conserva). No se puede deshacer y pide confirmación.' },
                { card: 'Cargar base', text: 'Para llenar una lista con contactos se carga un archivo CSV o TXT. Primero se elige la campaña y la lista (o «Nueva lista…»).' },
                { sel: '#lb_campaign', action: 'select', value: '', text: 'Primero la campaña a la que pertenece la lista.' },
                { sel: '#lb_list', action: 'select', value: 'new', text: 'Luego la lista: una existente (la carga REEMPLAZA todos sus contactos) o «Nueva lista…» para crear una nueva con el nombre que escribas.' },
                { sel: 'a.btn-action', txt: 'Plantilla', action: 'click', text: '«Plantilla» descarga un archivo CSV de ejemplo, listo para modificar: trae la cabecera (id_contacto, nombre, numero, monto, local, direccion) y una fila inventada con el número vacío para que lo escribas. La columna «numero» es obligatoria.' },
                { sel: 'input[type=file]', action: 'click', text: 'Se elige tu archivo (UTF-8, hasta 50000 registros). Cada contacto debe traer un número de 9 dígitos; las demás columnas quedan como datos del contacto.' },
                { sel: '#load-form button.btn-primary', action: 'click', text: '«Cargar base» importa el archivo y avisa cuántos contactos se importaron, cuántos eran duplicados y cuáles se rechazaron y por qué. (En la guía no se carga nada.)' }
            ]
        },
        {
            id: 'reportes', title: 'Reportes', ready: true, icon: '▤',
            prep: function (doc, i) { var d = doc.querySelector('details.tech'); if (d) d.open = i > 10; }, // el detalle técnico se abre con el clic real del paso 11 (índice 10)
            about: 'Qué pasó con los contactos, comparar listas, Zypad frente a AMD, cuándo llamar, reintentos y el detalle de intentos.',
            steps: [
                { page: 'reports.php', sel: '#filters', text: 'Los filtros acotan todo el informe: fechas, campaña, lista y estado de la lista. Sin fechas se ve toda la historia. «Exportar CSV» baja el detalle (con los teléfonos enmascarados).' },
                { sel: '#quick .btn-action', txt: 'Últimos 7 días', action: 'click', text: 'Estos botones rápidos eligen las fechas de un clic: Hoy, Ayer, Últimos 7 o 30 días, o Todo el historial. Las horas están en hora de Lima.' },
                { sel: '#bigcats', text: '«¿Qué pasó con mis contactos?» cuenta a cada contacto UNA sola vez, por su último resultado: Humano, Casilla de voz, No seguro, Contestada sin AMD, No disponible, Sin respuesta, Ocupado, Errores…' },
                { sel: '#bigbar', text: 'La barra suma el 100 % de los contactos. Los porcentajes son reales, redondeados desde el conteo, sin ajustes para que cuadren.' },
                { sel: '#contactsays', text: 'Debajo, frases que resumen: cuántos contactos contestaron, cuántas llamadas se transfirieron a tu destino y los pasos: llamados → contestaron → humanos y no seguros → transferidas.' },
                { sel: '#cmprows', text: '«Comparar listas»: una fila por lista, con su barra al 100 % de contactos, ordenadas de más a menos humanos entre los ya llamados. Con una sola lista no aparece porque no hay con qué comparar.' },
                { sel: '#zypadbox', text: 'Zypad y el AMD son dos detectores de casillas de voz: Zypad escucha antes de que contesten y el AMD decide después. Aquí se ve cuántas casillas del AMD detectó Zypad antes de contestar y los casos a revisar con la grabación.' },
                { sel: '#matrix', text: '«¿Cuándo conviene llamar?»: el porcentaje de intentos contestados según el día y la hora. Más oscuro = contestan más. Las celdas apagadas tienen pocos intentos: no saques conclusiones de ellas.' },
                { sel: '#retrysays', text: '«¿Sirven los reintentos?»: cuántos contactos se llamaron 1, 2, 3 o 4 veces y cuántos contactos extra alcanzaron los reintentos.' },
                { sel: '#retrytable', text: 'La tabla muestra, por número de intento, las llamadas hechas, cuántas contestaron y cuántas fueron humanos.' },
                { sel: 'details.tech > summary', action: 'real', text: 'El «Detalle técnico» está cerrado por defecto. Todo lo que hay dentro cuenta INTENTOS (cada vez que se marca un número), no contactos, por eso sus cifras no coinciden con las de arriba.' },
                { sel: '#kpis', text: 'Resumen de intentos: llamadas hechas, contestadas, casillas de voz, atendidos por una persona, no disponibles, sin respuesta, fallos y transferidas.' },
                { sel: '#f_estado', action: 'real-change', value: 'casilla_voz', text: '«Detalle de intentos»: el filtro «Estado» muestra solo las llamadas con ese resultado (aquí «Casilla de voz»). Se recarga solo la tabla, vuelve a la página 1 y se conserva al paginar.' },
                { sel: '#detail table', text: 'Cada fila es un intento: hora, campaña, lista, teléfono (aquí inventados, 900 000 0xx), tipo de marcación, estado en lenguaje llano y datos técnicos al final.' },
                { sel: '#csv', action: 'click', text: '«Exportar CSV» baja este mismo detalle (respetando el filtro de Estado) con los teléfonos enmascarados. (En la guía no se descarga nada.)' }
            ]
        },
        {
            id: 'test', title: 'Test', ready: true, icon: '✆',
            about: 'Crear una lista de prueba con un solo número, iniciarla y ver el resultado.',
            prep: function (doc, i) { if (i === 0) window.GUIA_DEMO.reset(); },
            steps: [
                { page: 'test.php', sel: '#form', text: 'La pantalla Test sirve para probar rápido con UN número: se crea una lista de prueba (1 número, 1 canal) y tú decides cuándo llamar. Crearla no llama a nadie.' },
                { sel: '#campaign', action: 'select', value: '1', text: 'Se elige la campaña con la que se hará la prueba: de ella se toman la troncal, la transferencia y el tipo de marcación.' },
                { sel: '#phone', action: 'type', value: '900000012', text: 'Se escribe el número: 9 dígitos, sin 51. (En la guía se usa uno inventado, 900 000 012.)' },
                { sel: '#create', action: 'submit-demo', submit: '#form', sim: true, text: '«Crear lista de prueba». SIMULADO: en la guía solo aparece una lista de ejemplo en pantalla; no se crea nada de verdad.' },
                { sel: '#created', text: 'Aparece la ficha con el nombre de la lista (test-fecha-hora), la campaña, el número y un enlace a su reporte. Todavía no se ha llamado a nadie.' },
                { sel: '#created button', txt: 'Iniciar', action: 'click', sim: true, text: '«Iniciar» llamaría de verdad a ese número, por eso pide una confirmación que lo dice claro. EN LA GUÍA ES SOLO UN CLIC SIMULADO: no se llama a nadie ni se inicia nada.' },
                { sel: '#recent', text: '«Últimas pruebas»: hora, campaña, número, estado (Sin iniciar, En curso o Terminada) y el resultado en lenguaje llano, con un enlace a Reportes. Se actualiza sola cada pocos segundos.' }
            ]
        },
        {
            id: 'amd', title: 'AMD y Blacklist', ready: true, icon: '✓',
            about: 'Para qué sirven el AMD y la lista negra, la opción por campaña y cómo se carga la lista negra.',
            steps: [
                { page: 'amd.php', none: true, text: 'El AMD (detector de contestadora) escucha los primeros segundos de una llamada contestada y decide: HUMANO (una persona), MACHINE (casilla de voz) o NOTSURE (no está seguro). Con eso la campaña decide si transfiere, cuelga o reintenta.' },
                { card: 'Valores actuales', text: 'En la pantalla AMD se ajustan los parámetros de la detección (tiempos de silencio, palabras máximas…). Cada cambio queda como una versión nueva, así que se puede volver a una anterior.' },
                { card: 'Zypad: palabras de casilla de voz', text: 'Zypad escucha antes de que contesten y reconoce frases típicas de las casillas («deje su mensaje…»). Este catálogo de palabras se puede ampliar y activar o desactivar.' },
                { page: 'campaigns.php?view=new', sel: 'select[name=blacklist_enabled]', action: 'select', value: '1', text: 'La Blacklist es una lista negra global de números a los que NO se debe llamar. En cada campaña se activa con este Sí/No (por defecto No). Si es Sí, el marcador no llama a esos números y esos contactos salen como «Bloqueado».' },
                { none: true, text: 'Los números de la lista negra se cargan solo por MySQL, directamente en la base: no hay pantalla para eso. Un número bloqueado no llega al proveedor, no se reintenta y aparece en Reportes como «Bloqueado».' }
            ]
        }
    ];
})();

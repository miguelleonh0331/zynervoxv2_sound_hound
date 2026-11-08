/* Guía interactiva (propia, sin librerías ni CDN). Muestra la PANTALLA REAL dentro de un iframe aislado y la recorre con un puntero animado.
 *
 * SEGURIDAD (modo demostración). Ninguna acción de la guía puede escribir, iniciar, detener, cargar ni borrar:
 *  1) El iframe va con sandbox="allow-same-origin allow-scripts": SIN allow-forms (el navegador no deja enviar formularios),
 *     sin allow-modals, allow-popups, allow-downloads ni allow-top-navigation.
 *  2) Antes de que corra ningún script de la pantalla, se inyecta un «escudo» que sustituye fetch, XMLHttpRequest, sendBeacon, submit,
 *     window.open, confirm/alert/prompt y localStorage: la pantalla NUNCA hace peticiones de red; sus lecturas se responden con datos
 *     inventados (guia_data.js) y cualquier método distinto de GET se rechaza y se cuenta.
 *  3) Los enlaces de la pantalla se bloquean. Los clics que la guía «hace» son visuales; solo se despacha un clic real en una lista
 *     blanca de controles de interfaz (REAL_OK: abrir/cerrar paneles). Excepciones EXPLÍCITAS y sin red:
 *     - REAL_CHANGE_OK: cambiar el filtro «Estado» del detalle de Reportes: la pantalla hace un GET que el escudo responde con datos de ejemplo.
 *     - REAL_SUBMIT_OK: enviar el formulario de Test: la pantalla hace un POST que el escudo responde ÉL MISMO con una lista de ejemplo
 *       (D.respondPost, solo test.php/create); no sale ninguna petición y no se crea nada.
 * Los HTML de las pantallas se leen con un GET normal (como si el administrador abriera la pantalla). */
(function () {
    'use strict';
    var BASE = location.href.replace(/[?#].*$/, '').replace(/[^/]*$/, '');
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var REAL_OK = ['#stbtn', 'details.tech > summary']; // clics reales: solo abren/cierran un panel de la interfaz
    var REAL_CHANGE_OK = ['#f_estado']; // cambio real: el filtro Estado del detalle (GET con datos de ejemplo)
    var REAL_SUBMIT_OK = ['#form'];      // envío real: formulario de Test, respondido por el escudo con una lista de ejemplo
    window.__guiaLog = []; window.__guiaBlocked = 0;

    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, REDUCED ? Math.min(ms, 30) : ms); }); }
    function log(kind, what) { window.__guiaLog.push(kind + ' ' + what); if (kind === 'BLOQUEADO') { window.__guiaBlocked++; var b = $('blocked'); if (b) b.textContent = window.__guiaBlocked; } }
    window.__guiaNote = log;

    // ---- escudo que se inyecta en la pantalla real (se ejecuta DENTRO del iframe, antes que sus scripts) ----
    function shield() {
        var P = window.parent;
        function note(k, w) { try { P.__guiaNote(k, w); } catch (e) {} }
        function json(status, body) { return new Response(JSON.stringify(body), { status: status, headers: { 'Content-Type': 'application/json' } }); }
        window.fetch = function (u, o) {
            var url = String((u && u.url) || u), m = String((o && o.method) || (u && u.method) || 'GET').toUpperCase();
            if (m === 'POST' && /(^|\/)test\.php(\?|$)/.test(url)) { // única escritura «simulada»: crear la lista de ejemplo de la pantalla Test (sin red, sin base)
                var sim = P.GUIA_DEMO.respondPost(url, String((o && o.body) || ''));
                if (sim) { note('simulado', 'crear lista de prueba (de ejemplo)'); return Promise.resolve(json(200, sim)); }
            }
            if (m !== 'GET') { note('BLOQUEADO', m + ' ' + url); return Promise.resolve(json(403, { ok: false, error: 'Modo demostración: no se guarda, inicia ni detiene nada.' })); }
            var r = P.GUIA_DEMO.respond(url); note('demo', 'GET ' + url.split('?')[0].split('/').pop());
            return Promise.resolve(json(r.status, r.body));
        };
        XMLHttpRequest.prototype.open = function (m, u) { note('BLOQUEADO', 'XHR ' + m + ' ' + u); };
        XMLHttpRequest.prototype.send = function () {};
        navigator.sendBeacon = function (u) { note('BLOQUEADO', 'beacon ' + u); return false; };
        HTMLFormElement.prototype.submit = function () { note('BLOQUEADO', 'submit'); };
        HTMLFormElement.prototype.requestSubmit = function () { note('BLOQUEADO', 'submit'); };
        document.addEventListener('submit', function (e) {
            if (window.__allowSubmit && e.target && e.target.id === window.__allowSubmit) { window.__allowSubmit = null; note('simulado', 'envío de #' + e.target.id); return; } // lo despacha la guía; el handler de la pantalla solo hace fetch (que el escudo controla)
            e.preventDefault(); e.stopImmediatePropagation(); note('BLOQUEADO', 'submit');
        }, true);
        document.addEventListener('click', function (e) { var a = e.target && e.target.closest && e.target.closest('a[href]'); if (a) { e.preventDefault(); note('bloqueado', 'enlace ' + a.getAttribute('href')); } }, true);
        window.open = function () { note('BLOQUEADO', 'open'); return null; };
        window.confirm = function () { return false; }; window.alert = function () {}; window.prompt = function () { return null; };
        Storage.prototype.setItem = function () {}; Storage.prototype.removeItem = function () {};
    }
    var SHIELD_CSS = '.sidebar{display:none!important}.main-content{margin-left:0!important}a,button,select,input{cursor:default!important}';

    // ---- estado ----
    var stage, frame, hl, ptr, tip, topic = null, idx = 0, loaded = '', token = 0, ptrPos = null;

    function loadPage(page) {
        return fetch(BASE + page, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); }).then(function (html) {
            if (!/<head[\s>]/i.test(html)) throw new Error('La pantalla no se pudo leer (¿sesión caducada?).');
            var inject = '<base href="' + BASE + '"><style>' + SHIELD_CSS + '</style><script>(' + shield.toString() + ')();<\/script>';
            html = html.replace(/<head[^>]*>/i, function (m) { return m + inject; });
            return new Promise(function (res) { frame.onload = function () { frame.onload = null; loaded = page; res(); }; frame.srcdoc = html; });
        });
    }

    function visible(e) { var r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; }
    function resolve(step, doc) {
        if (step.card) {
            var hs = doc.querySelectorAll('h2,h3');
            for (var i = 0; i < hs.length; i++) if (hs[i].textContent.indexOf(step.card) >= 0) return hs[i].closest('.card') || hs[i].parentElement;
            return null;
        }
        var list = Array.prototype.slice.call(doc.querySelectorAll(step.sel));
        if (step.txt) list = list.filter(function (e) { return e.textContent.trim().indexOf(step.txt) === 0; });
        for (var k = 0; k < list.length; k++) if (visible(list[k])) return list[k];
        return list[0] || null;
    }
    function waitFor(step) {
        var t0 = Date.now();
        return new Promise(function (res) {
            (function poll() {
                var doc = frame.contentDocument, e = doc && resolve(step, doc);
                if (e && visible(e)) return res(e);
                if (Date.now() - t0 > 9000) return res(e || null);
                setTimeout(poll, 120);
            })();
        });
    }

    function place(node, x, y, w, h) { node.style.transform = 'translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px)'; if (w !== undefined) { node.style.width = Math.round(w) + 'px'; node.style.height = Math.round(h) + 'px'; } }
    function ripple(x, y) { var r = el('div', 'g-ripple'); r.style.left = x + 'px'; r.style.top = y + 'px'; stage.appendChild(r); setTimeout(function () { r.remove(); }, 700); }
    function badge(x, y) { var b = el('div', 'g-sim', 'CLIC SIMULADO · no llama a nadie'); b.style.left = Math.min(x + 18, stage.clientWidth - 230) + 'px'; b.style.top = (y + 22) + 'px'; stage.appendChild(b); setTimeout(function () { b.remove(); }, 3500); }
    function typeInto(input, value, my) {
        input.value = ''; var i = 0;
        return new Promise(function (res) { (function tick() { if (my !== token || i >= value.length) return res(); input.value += value[i++]; setTimeout(tick, REDUCED ? 0 : 70); })(); });
    }

    function showTip(step, rect, n, total) {
        tip.textContent = '';
        var head = el('div', 'g-tip-h'); head.appendChild(el('b', '', topic.title)); head.appendChild(el('span', 'g-count', 'Paso ' + (n + 1) + ' de ' + total)); tip.appendChild(head);
        tip.appendChild(el('p', 'g-tip-t', step.text));
        var row = el('div', 'g-tip-b');
        var prev = el('button', 'g-btn', 'Anterior'); prev.type = 'button'; prev.disabled = n === 0; prev.addEventListener('click', function () { go(idx - 1); });
        var next = el('button', 'g-btn g-primary', n === total - 1 ? 'Terminar' : 'Siguiente'); next.type = 'button'; next.addEventListener('click', function () { if (idx === total - 1) showIndex(); else go(idx + 1); });
        var ind = el('button', 'g-btn', 'Índice'); ind.type = 'button'; ind.addEventListener('click', showIndex);
        var out = el('button', 'g-btn', 'Salir'); out.type = 'button'; out.addEventListener('click', function () { location.href = 'index.php'; });
        row.appendChild(prev); row.appendChild(next); row.appendChild(ind); row.appendChild(out); tip.appendChild(row);
        tip.hidden = false;
        var sw = stage.clientWidth, sh = stage.clientHeight, tw = Math.min(380, sw - 24); tip.style.width = tw + 'px';
        var th = tip.offsetHeight, x, y;
        if (!rect) { x = (sw - tw) / 2; y = (sh - th) / 2; }
        else if (rect.height > sh * 0.55 || rect.width > sw * 0.9) { x = sw - tw - 16; y = sh - th - 16; }
        else {
            x = Math.min(Math.max(12, rect.left + rect.width / 2 - tw / 2), sw - tw - 12);
            y = rect.bottom + 18; if (y + th > sh - 8) y = rect.top - th - 18; if (y < 8) y = Math.min(sh - th - 8, Math.max(8, rect.bottom + 18));
        }
        place(tip, x, y);
    }

    async function show(i) {
        var my = ++token, step = topic.steps[i]; idx = i;
        document.querySelectorAll('.g-step-dot').forEach(function (d, k) { d.classList.toggle('on', k === i); });
        tip.hidden = true;
        try {
            if (step.page && step.page !== loaded) { stage.classList.add('loading'); await loadPage(step.page); stage.classList.remove('loading'); if (my !== token) return; }
            var doc = frame.contentDocument; if (topic.prep) topic.prep(doc, i);
            if (step.none) { hl.style.opacity = 0; ptr.style.opacity = 0; showTip(step, null, i, topic.steps.length); return; }
            var e = await waitFor(step); if (my !== token) return;
            if (!e) { hl.style.opacity = 0; showTip({ text: step.text + ' (Este elemento no está visible en esta pantalla ahora.)' }, null, i, topic.steps.length); return; }
            e.scrollIntoView({ block: 'center', inline: 'nearest' }); await sleep(120); if (my !== token) return;
            var r = e.getBoundingClientRect(), pad = 4;
            hl.style.opacity = 1; place(hl, r.left - pad, r.top - pad, r.width + pad * 2, r.height + pad * 2);
            var big = r.width > 320 || r.height > 120, tx = big ? r.left + 46 : r.left + r.width / 2, ty = big ? r.top + 26 : r.top + r.height / 2;
            ptr.style.opacity = 1; place(ptr, tx, ty); ptrPos = [tx, ty];
            await sleep(950); if (my !== token) return;
            if (step.action === 'click' || step.action === 'real' || step.action === 'submit-demo') { ripple(tx, ty); if (step.sim) badge(tx, ty); }
            if (step.action === 'real' && REAL_OK.indexOf(step.sel) >= 0) { await sleep(250); e.click(); await sleep(450); }
            else if (step.action === 'real-change' && REAL_CHANGE_OK.indexOf(step.sel) >= 0) { e.value = step.value; e.dispatchEvent(new Event('change', { bubbles: true })); await sleep(900); }
            else if (step.action === 'submit-demo' && REAL_SUBMIT_OK.indexOf(step.submit) >= 0) { var fm = frame.contentDocument.querySelector(step.submit); if (fm) { frame.contentWindow.__allowSubmit = fm.id; fm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); await sleep(1100); } }
            else if (step.action === 'type') { await typeInto(e, step.value || '', my); }
            else if (step.action === 'select') { if (step.value !== undefined) e.value = step.value; ripple(tx, ty); await sleep(250); }
            if (my !== token) return;
            r = e.getBoundingClientRect(); place(hl, r.left - pad, r.top - pad, r.width + pad * 2, r.height + pad * 2);
            showTip(step, r, i, topic.steps.length);
        } catch (err) {
            stage.classList.remove('loading'); showTip({ text: 'No se pudo cargar esta pantalla: ' + err.message }, null, i, topic.steps.length);
        }
    }
    function go(i) { if (!topic || i < 0 || i >= topic.steps.length) return; show(i); }

    function start(t) {
        topic = t; idx = 0; loaded = ''; $('index').hidden = true; $('tour').hidden = false; ptr.style.opacity = 0; hl.style.opacity = 0; tip.hidden = true;
        var dots = $('dots'); dots.textContent = ''; t.steps.forEach(function (s, k) { var d = el('span', 'g-step-dot'); d.title = 'Paso ' + (k + 1); d.addEventListener('click', function () { go(k); }); dots.appendChild(d); });
        $('tour-title').textContent = 'Guía · ' + t.title; show(0);
    }
    function showIndex() { token++; topic = null; loaded = ''; frame.removeAttribute('srcdoc'); frame.src = 'about:blank'; $('tour').hidden = true; $('index').hidden = false; tip.hidden = true; }

    function buildIndex() {
        var box = $('topics'); box.textContent = '';
        window.GUIA_DEMO.topics.forEach(function (t, n) {
            var c = el('div', 'g-topic' + (t.ready ? '' : ' soon'));
            c.appendChild(el('div', 'g-ico', t.icon)); c.appendChild(el('h3', '', (n + 1) + '. ' + t.title)); c.appendChild(el('p', '', t.about));
            var b = el('button', 'g-btn g-primary', t.ready ? 'Empezar (' + t.steps.length + ' pasos)' : 'Próximamente'); b.type = 'button'; b.disabled = !t.ready;
            if (t.ready) b.addEventListener('click', function () { start(t); });
            c.appendChild(b); box.appendChild(c);
        });
    }

    function reposition() { if (topic) show(idx); }
    document.addEventListener('DOMContentLoaded', function () {
        stage = $('stage'); frame = $('frame'); hl = $('hl'); ptr = $('ptr'); tip = $('tip');
        buildIndex();
        var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(reposition, 250); });
        document.addEventListener('keydown', function (e) { if (!topic) return; if (e.key === 'ArrowRight') go(idx + 1); else if (e.key === 'ArrowLeft') go(idx - 1); else if (e.key === 'Escape') showIndex(); });
        var q = new URLSearchParams(location.search).get('tema'); // acceso directo: guia.php?tema=dashboard
        if (q) window.GUIA_DEMO.topics.forEach(function (tp) { if (tp.id === q && tp.ready) start(tp); });
    });
})();

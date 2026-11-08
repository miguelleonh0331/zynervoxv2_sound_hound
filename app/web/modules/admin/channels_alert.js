// Aviso emergente de «Sobredimensionamiento de canales». El servidor decide (BotChannelsException); aquí solo se muestra el mensaje y las cifras que devuelve.
// Uso: channelsAlert(mensaje, cifras) con cifras = {discando, maximo, pedidos, libres, faltan}.
(function () {
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
    window.channelsAlert = function (message, f) {
        var old = document.getElementById('chn-alert'); if (old) old.remove();
        var back = el('div'); back.id = 'chn-alert'; back.setAttribute('role', 'alertdialog'); back.setAttribute('aria-modal', 'true'); back.setAttribute('aria-labelledby', 'chn-title');
        back.style.cssText = 'position:fixed;inset:0;background:rgba(17,24,39,.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem';
        var box = el('div'); box.style.cssText = 'background:#fff;color:#111827;max-width:460px;width:100%;border-top:6px solid #dc2626;padding:1.1rem 1.25rem;box-shadow:0 10px 30px rgba(0,0,0,.35);font-size:.9rem';
        var h = el('h2', '', 'No hay canales suficientes'); h.id = 'chn-title'; h.style.cssText = 'margin:0 0 .5rem;font-size:1.05rem'; box.appendChild(h);
        box.appendChild(el('p', '', message)).style.margin = '0 0 .6rem';
        if (f) {
            var bajaMax = f.pedidos === 0 && f.libres === 0 && f.faltan > 0; // al bajar el máximo del servidor: «maximo» es el valor que se intentó guardar
            var rows = bajaMax ? [['Discando ahora', f.discando], ['Máximo que intentaste', f.maximo], ['Sobran', f.faltan]]
                : [['Discando ahora', f.discando], ['Máximo del servidor', f.maximo], ['Pedidos', f.pedidos], ['Libres', f.libres]];
            if (!bajaMax && f.faltan > 0) rows.push(['Faltan', f.faltan]);
            var t = el('table'); t.style.cssText = 'border-collapse:collapse;width:100%;margin:.2rem 0 .6rem';
            rows.forEach(function (r) { var tr = el('tr'); var a = el('td', '', r[0]), b = el('td', '', String(r[1])); a.style.cssText = 'padding:.15rem 0;color:#4b5563'; b.style.cssText = 'padding:.15rem 0;text-align:right;font-weight:700;font-variant-numeric:tabular-nums'; tr.appendChild(a); tr.appendChild(b); t.appendChild(tr); });
            box.appendChild(t);
        }
        box.appendChild(el('p', '', f && f.pedidos === 0 && f.libres === 0 && f.faltan > 0 ? 'No se cambió el máximo. Detén listas o baja sus canales primero y vuelve a intentarlo.' : 'No se inició ni se cambió nada. Baja los canales de esta lista o detén otra lista y vuelve a intentarlo.')).style.cssText = 'margin:0 0 .8rem;color:#374151';
        var ok = el('button', 'btn-primary', 'Entendido'); ok.type = 'button'; ok.style.cssText = 'float:right;cursor:pointer';
        function close() { back.remove(); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        ok.addEventListener('click', close); back.addEventListener('click', function (e) { if (e.target === back) close(); }); document.addEventListener('keydown', onKey);
        box.appendChild(ok); box.appendChild(el('div')).style.clear = 'both'; back.appendChild(box); document.body.appendChild(back); ok.focus();
    };
})();

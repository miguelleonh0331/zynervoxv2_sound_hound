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

    /** Respuesta simulada para un GET de la pantalla real: {status, body}. Cualquier otra ruta no tiene datos de demostración. */
    D.respond = function (url) {
        var path = String(url).split('?')[0].split('/').pop();
        if (path === 'dashboard_api.php') return { status: 200, body: dashboard() };
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
        { id: 'listas', title: 'Listas', ready: false, icon: '☰', about: 'Plantilla CSV, cargar la base de contactos, activar y desactivar, canales y reportes.', steps: [] },
        { id: 'reportes', title: 'Reportes', ready: false, icon: '▤', about: 'Qué pasó con los contactos, comparar listas, Zypad frente a AMD, reintentos y el detalle de intentos.', steps: [] },
        { id: 'test', title: 'Test', ready: false, icon: '✆', about: 'Crear una lista de prueba con un solo número e iniciarla.', steps: [] },
        { id: 'amd', title: 'AMD y Blacklist', ready: false, icon: '✓', about: 'Para qué sirven, la opción por campaña y cómo se carga la lista negra.', steps: [] }
    ];
})();

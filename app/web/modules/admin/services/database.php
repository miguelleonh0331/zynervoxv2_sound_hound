<?php
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios / Base de datos - Zynervox</title>
    <link rel="stylesheet" href="../layout.css">
    <style>
        .db-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .db-row label { min-width: 110px; margin: 0; font-size: 0.75rem; }
        .db-row input { flex: 1; font-family: monospace; font-size: 0.75rem; }
        .db-actions { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.4rem; }
        .db-msg { font-size: 0.75rem; min-height: 1em; }
        .db-msg.ok { color: #047857; }
        .db-msg.error { color: #b91c1c; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../sidebar.php'; renderSidebar('database', '../../../'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Servicios / Base de datos</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">
                    Conexión única del sistema telefónico (IVR Builder, Bot IVR). Pensado para
                    cluster: apunte aquí a la base de datos central que comparten todos los nodos.
                </p>
            </div>
        </header>

        <div class="card">
            <h2>Conexión a base de datos</h2>
            <div class="db-row"><label>Host</label><input id="cfgDbHost" placeholder="127.0.0.1"></div>
            <div class="db-row"><label>Puerto</label><input id="cfgDbPort" placeholder="3306"></div>
            <div class="db-row"><label>Base de datos</label><input id="cfgDbName" placeholder="zynervox_core"></div>
            <div class="db-row"><label>Usuario</label><input id="cfgDbUser" placeholder="zynervox_bot_ivr"></div>
            <div class="db-row"><label>Password</label><input id="cfgDbPass" type="password" placeholder="••••••••"></div>
            <div class="db-actions">
                <button type="button" class="btn-action" id="db-test">Test</button>
                <button type="button" class="btn-action" id="db-save">Guardar</button>
                <span id="db-msg" class="db-msg"></span>
            </div>
        </div>

        <script>
        (function () {
            var API = '../../../ivr_builder/config_api.php';
            var csrf = '';

            function el(id) { return document.getElementById(id); }
            function setMsg(text, kind) {
                var m = el('db-msg');
                m.textContent = text || '';
                m.className = 'db-msg' + (kind ? ' ' + kind : '');
            }

            function postConfig(action, payload) {
                return fetch(API, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(Object.assign({ action: action }, payload))
                }).then(function (r) { return r.json(); });
            }

            function currentPayload() {
                return {
                    db_host: el('cfgDbHost').value.trim(),
                    db_port: el('cfgDbPort').value.trim(),
                    db_name: el('cfgDbName').value.trim(),
                    db_user: el('cfgDbUser').value.trim(),
                    db_pass: el('cfgDbPass').value
                };
            }

            function load() {
                // Defaults primero: si ivr_deploy_config todavia no existe
                // (instalacion recien borrada), el fetch de abajo falla y los
                // campos no deben quedar vacios.
                el('cfgDbHost').value = '127.0.0.1';
                el('cfgDbPort').value = 3306;
                el('cfgDbName').value = 'zynervox_core';
                el('cfgDbUser').value = 'zynervox_bot_ivr';
                el('cfgDbPass').value = '';
                fetch(API + '?action=get', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) throw new Error(d.error || 'No se pudo cargar la configuración');
                        csrf = d.csrf;
                        var c = d.config;
                        if (c.db_host) el('cfgDbHost').value = c.db_host;
                        if (c.db_port) el('cfgDbPort').value = c.db_port;
                        if (c.db_name) el('cfgDbName').value = c.db_name;
                        if (c.db_user) el('cfgDbUser').value = c.db_user;
                        if (c.db_pass) el('cfgDbPass').value = c.db_pass;
                    })
                    .catch(function () { setMsg('No se pudo cargar la configuración. Recarga la página.', 'error'); });
            }

            el('db-test').addEventListener('click', function () {
                setMsg('probando...', '');
                postConfig('test_db', currentPayload()).then(function (d) {
                    setMsg(d.ok ? d.message : d.error, d.ok ? 'ok' : 'error');
                }).catch(function () { setMsg('No se pudo completar la prueba.', 'error'); });
            });
            el('db-save').addEventListener('click', function () {
                setMsg('guardando...', '');
                postConfig('save_db', currentPayload()).then(function (d) {
                    setMsg(d.ok ? d.message : d.error, d.ok ? 'ok' : 'error');
                }).catch(function () { setMsg('No se pudo guardar la configuración.', 'error'); });
            });

            load();
        })();
        </script>
    </main>

</body>
</html>

<?php
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

$configFile = '/etc/zynervox/zynervox-zypad.php';
$installed = is_readable($configFile);
$config = $installed ? require $configFile : null;

if ($installed) {
    if (empty($_SESSION['zypad_csrf'])) {
        $_SESSION['zypad_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = $_SESSION['zypad_csrf'];
    $endpoint = 'http://' . $config['bind_ip'] . ':' . $config['port'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios / Zypad - Zynervox</title>
    <link rel="stylesheet" href="../layout.css">
    <style>
        .zypad-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.6rem; margin-top: 0.6rem; }
        .zypad-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .zypad-row label { min-width: 110px; margin: 0; }
        .zypad-row input { font-family: monospace; font-size: 0.75rem; }
        .zypad-copy { white-space: nowrap; }
        .zypad-badge { display: inline-block; padding: 2px 10px; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .zypad-badge.up { background: #d1fae5; color: #047857; }
        .zypad-badge.down { background: #fee2e2; color: #b91c1c; }
        .zypad-badge.unknown { background: var(--glass); color: var(--text-muted); }
        .zypad-stat { font-size: 1.3rem; font-weight: 700; }
        .zypad-stat-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .zypad-msg { font-size: 0.75rem; margin-top: 0.4rem; min-height: 1em; }
        .zypad-msg.error { color: #b91c1c; }
        .zypad-msg.ok { color: #047857; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../sidebar.php'; renderSidebar('zypad', '../../../'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Servicios / Zypad</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Detector de preanswer (STT + reglas), empaquetado en Docker</p>
            </div>
        </header>

        <?php if (!$installed): ?>
        <div class="card">
            <h2>No instalado</h2>
            <p style="font-size: 0.8rem; color: var(--text-muted);">
                Este servidor no tiene Zypad instalado todavía. Reinstale Zynervox agregando
                <code>--with-zypad --install-docker</code> (si Docker no está presente) a
                <code>installer/install.sh</code>.
            </p>
        </div>
        <?php else: ?>

        <div class="zypad-grid">
            <div class="card">
                <h2>Estado</h2>
                <p><span id="zypad-status" class="zypad-badge unknown">Verificando...</span></p>
                <div class="zypad-row" style="margin-top: 0.6rem;">
                    <button type="button" id="zypad-start" class="btn-action" disabled>Iniciar</button>
                    <button type="button" id="zypad-stop" class="btn-action" disabled>Detener</button>
                </div>
                <p id="zypad-action-msg" class="zypad-msg"></p>
            </div>

            <div class="card">
                <h2>Monitor</h2>
                <div class="zypad-grid" style="grid-template-columns: repeat(3, 1fr); margin-top: 0;">
                    <div><div id="zypad-cpu" class="zypad-stat">-</div><div class="zypad-stat-label">CPU</div></div>
                    <div><div id="zypad-mem" class="zypad-stat">-</div><div class="zypad-stat-label">Memoria</div></div>
                    <div><div id="zypad-net" class="zypad-stat">-</div><div class="zypad-stat-label">Red (RX / TX)</div></div>
                </div>
            </div>

            <div class="card">
                <h2>Conexión (para otros servidores)</h2>
                <div class="zypad-row">
                    <label>Endpoint</label>
                    <input type="text" readonly value="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>" id="zypad-endpoint">
                    <button type="button" class="btn-action zypad-copy" data-copy="zypad-endpoint">Copiar</button>
                </div>
                <div class="zypad-row">
                    <label>API key</label>
                    <input type="text" readonly value="<?= htmlspecialchars($config['api_key'], ENT_QUOTES, 'UTF-8') ?>" id="zypad-apikey">
                    <button type="button" class="btn-action zypad-copy" data-copy="zypad-apikey">Copiar</button>
                </div>
                <p style="font-size: 0.7rem; color: var(--text-muted);">
                    Configure el otro servidor con <code>Authorization: Bearer &lt;API key&gt;</code> contra este endpoint.
                </p>
            </div>

            <div class="card">
                <h2>Panel administrativo de Zypad</h2>
                <div class="zypad-row">
                    <label>Usuario</label>
                    <input type="text" readonly value="<?= htmlspecialchars($config['admin_user'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="zypad-row">
                    <label>Contraseña</label>
                    <input type="password" readonly value="<?= htmlspecialchars($config['admin_password'], ENT_QUOTES, 'UTF-8') ?>" id="zypad-adminpass">
                    <button type="button" class="btn-action" id="zypad-toggle-pass">Mostrar</button>
                </div>
                <p style="margin-top: 0.4rem;">
                    <a class="btn-action" href="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>/admin" target="_blank" rel="noopener">Administrar frases de clasificación</a>
                </p>
                <p style="font-size: 0.7rem; color: var(--text-muted);">
                    Abre <code><?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>/admin</code> en pestaña nueva; pide el usuario/contraseña de arriba (HTTP Basic).
                </p>
            </div>
        </div>

        <script>
        (function () {
            var API = 'zypad_api.php';
            var CSRF = <?= json_encode($csrf) ?>;
            var statusEl = document.getElementById('zypad-status');
            var startBtn = document.getElementById('zypad-start');
            var stopBtn = document.getElementById('zypad-stop');
            var msgEl = document.getElementById('zypad-action-msg');
            var cpuEl = document.getElementById('zypad-cpu');
            var memEl = document.getElementById('zypad-mem');
            var netEl = document.getElementById('zypad-net');

            function setMsg(text, kind) {
                msgEl.textContent = text || '';
                msgEl.className = 'zypad-msg' + (kind ? ' ' + kind : '');
            }

            function applyStatus(data) {
                if (data.running) {
                    statusEl.textContent = 'Corriendo';
                    statusEl.className = 'zypad-badge up';
                    startBtn.disabled = true;
                    stopBtn.disabled = false;
                } else {
                    statusEl.textContent = 'Detenido';
                    statusEl.className = 'zypad-badge down';
                    startBtn.disabled = false;
                    stopBtn.disabled = true;
                }
                if (data.stats) {
                    cpuEl.textContent = data.stats.cpu;
                    memEl.textContent = data.stats.mem;
                    netEl.textContent = data.stats.net;
                } else {
                    cpuEl.textContent = '-';
                    memEl.textContent = '-';
                    netEl.textContent = '-';
                }
            }

            function refresh() {
                fetch(API + '?action=status', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) {
                            statusEl.textContent = 'Error';
                            statusEl.className = 'zypad-badge unknown';
                            return;
                        }
                        applyStatus(data);
                    })
                    .catch(function () {
                        statusEl.textContent = 'Sin respuesta';
                        statusEl.className = 'zypad-badge unknown';
                    });
            }

            function runAction(action, btn) {
                btn.disabled = true;
                setMsg('Procesando...', '');
                var body = new URLSearchParams({ action: action, csrf: CSRF });
                fetch(API, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        setMsg(data.ok ? 'Listo.' : ('Error: ' + (data.error || data.detail || '')), data.ok ? 'ok' : 'error');
                        refresh();
                    })
                    .catch(function () {
                        setMsg('Error de red al ejecutar la acción.', 'error');
                        refresh();
                    });
            }

            startBtn.addEventListener('click', function () { runAction('start', startBtn); });
            stopBtn.addEventListener('click', function () { runAction('stop', stopBtn); });

            document.querySelectorAll('.zypad-copy').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = document.getElementById(btn.getAttribute('data-copy'));
                    input.select();
                    navigator.clipboard && navigator.clipboard.writeText(input.value);
                    btn.textContent = 'Copiado';
                    setTimeout(function () { btn.textContent = 'Copiar'; }, 1500);
                });
            });

            var toggleBtn = document.getElementById('zypad-toggle-pass');
            var passInput = document.getElementById('zypad-adminpass');
            toggleBtn.addEventListener('click', function () {
                var showing = passInput.type === 'text';
                passInput.type = showing ? 'password' : 'text';
                toggleBtn.textContent = showing ? 'Mostrar' : 'Ocultar';
            });

            refresh();
            setInterval(refresh, 5000);
        })();
        </script>

        <?php endif; ?>
    </main>

</body>
</html>

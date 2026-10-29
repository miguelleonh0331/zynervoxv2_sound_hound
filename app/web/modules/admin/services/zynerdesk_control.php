<?php
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

$configFile = '/etc/zynervox/zynerdesk-control.php';
$installed = is_readable($configFile);
$config = $installed ? require $configFile : null;

if ($installed) {
    if (empty($_SESSION['zynerdesk_control_csrf'])) {
        $_SESSION['zynerdesk_control_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = $_SESSION['zynerdesk_control_csrf'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios / Zynerdesk - Zynervox</title>
    <link rel="stylesheet" href="../layout.css">
    <style>
        .zypad-badge { display: inline-block; padding: 2px 10px; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .zypad-badge.up { background: #d1fae5; color: #047857; }
        .zypad-badge.down { background: #fee2e2; color: #b91c1c; }
        .zypad-badge.unknown { background: var(--glass); color: var(--text-muted); }
        .zypad-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .zypad-msg { font-size: 0.75rem; margin-top: 0.4rem; min-height: 1em; }
        .zypad-msg.error { color: #b91c1c; }
        .zypad-msg.ok { color: #047857; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../sidebar.php'; renderSidebar('zynerdesk_control', '../../../'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Servicios / Zynerdesk</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Detener o iniciar el servicio Zynerdesk de este servidor</p>
            </div>
        </header>

        <?php if (!$installed): ?>
        <div class="card">
            <h2>No instalado</h2>
            <p style="font-size: 0.8rem; color: var(--text-muted);">
                Este servidor no tiene el control de Zynerdesk instalado todavía. Reinstale Zynervox
                (ya incluido en <code>--with-zynerdesk</code>), o ejecute a mano
                <code>installer/zynerdesk.sh install-control</code>.
            </p>
        </div>
        <?php else: ?>

        <div class="card" style="max-width: 360px;">
            <h2>Estado</h2>
            <p><span id="zypad-status" class="zypad-badge unknown">Verificando...</span></p>
            <div class="zypad-row" style="margin-top: 0.6rem;">
                <button type="button" id="zypad-start" class="btn-action" disabled>Iniciar</button>
                <button type="button" id="zypad-stop" class="btn-action" disabled>Detener</button>
            </div>
            <p id="zypad-action-msg" class="zypad-msg"></p>
        </div>

        <script>
        (function () {
            var API = 'zynerdesk_control_api.php';
            var CSRF = <?= json_encode($csrf) ?>;
            var statusEl = document.getElementById('zypad-status');
            var startBtn = document.getElementById('zypad-start');
            var stopBtn = document.getElementById('zypad-stop');
            var msgEl = document.getElementById('zypad-action-msg');

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

            refresh();
            setInterval(refresh, 5000);
        })();
        </script>

        <?php endif; ?>
    </main>

</body>
</html>

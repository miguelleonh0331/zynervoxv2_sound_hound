<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/DevChecklist.php';

use Includes\Auth;
use Includes\DevChecklist;

Auth::checkAccess(9);

// Ademas de ser admin nivel 9, tiene que ser exactamente el usuario 6666
// (pedido explicito). Cualquier otro admin nivel 9 es redirigido, y ademas
// el item de menu ni siquiera se le muestra (ver sidebar.php).
if (!DevChecklist::isAuthorized()) {
    header('Location: ../../index.php');
    exit;
}

$state = DevChecklist::getState();
$progress = DevChecklist::progress($state);

$priorityLabels = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
$priorityColors = ['alta' => '#ef4444', 'media' => '#F5821F', 'baja' => '#9CA0AC'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dev Checklist - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        #progress-wrap { margin-bottom: 1.25rem; }
        #progress-bar-track {
            width: 100%; height: 10px; border-radius: 6px; background: var(--glass);
            overflow: hidden; margin-top: 6px;
        }
        #progress-bar-fill {
            height: 100%; background: #F5821F; border-radius: 6px;
            transition: width .25s ease;
        }
        .check-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: .65rem .75rem; border-bottom: 1px solid var(--border);
        }
        .check-item:last-child { border-bottom: none; }
        .check-item input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; cursor: pointer; accent-color: #F5821F; }
        .check-item label { flex: 1; cursor: pointer; font-size: .875rem; }
        .check-item.is-checked label { color: var(--text-muted); text-decoration: line-through; }
        .prio-badge {
            font-size: .65rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;
            color: #fff; white-space: nowrap; margin-left: 8px;
        }
        .check-meta { font-size: .7rem; color: var(--text-muted); margin-top: 2px; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('dev'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Dev Checklist</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">
                    Roadmap de funciones estilo VICIdial pendientes &mdash; solo visible para ti
                </p>
            </div>
        </header>

        <div id="progress-wrap" class="card">
            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                <strong id="progress-label" style="font-size:.9rem;">
                    <?php echo $progress['done']; ?> / <?php echo $progress['total']; ?> completado
                </strong>
                <span id="progress-pct" style="color:#F5821F; font-weight:700;"><?php echo $progress['pct']; ?>%</span>
            </div>
            <div id="progress-bar-track">
                <div id="progress-bar-fill" style="width: <?php echo $progress['pct']; ?>%;"></div>
            </div>
        </div>

        <div class="card" style="padding: 0;">
            <div id="checklist-list">
                <?php foreach (DevChecklist::ITEMS as $item):
                    $itemState = $state[$item['key']] ?? null;
                    $checked = !empty($itemState['checked']);
                ?>
                <div class="check-item <?php echo $checked ? 'is-checked' : ''; ?>" data-key="<?php echo htmlspecialchars($item['key']); ?>">
                    <input type="checkbox" id="chk-<?php echo htmlspecialchars($item['key']); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                    <div style="flex:1;">
                        <label for="chk-<?php echo htmlspecialchars($item['key']); ?>">
                            <?php echo htmlspecialchars($item['label']); ?>
                            <span class="prio-badge" style="background: <?php echo $priorityColors[$item['priority']]; ?>;">
                                <?php echo $priorityLabels[$item['priority']]; ?>
                            </span>
                        </label>
                        <div class="check-meta" data-meta>
                            <?php if ($itemState): ?>
                                Marcado <?php echo $checked ? '' : 'des'; ?>hecho el <?php echo htmlspecialchars($itemState['updated_at']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

<script>
document.querySelectorAll('.check-item input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
        const row = cb.closest('.check-item');
        const key = row.getAttribute('data-key');
        const checked = cb.checked;
        row.classList.toggle('is-checked', checked);

        fetch('api_dev_checklist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: key, checked: checked })
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) { console.error('Dev checklist error:', data.error); return; }
            document.getElementById('progress-label').textContent = data.progress.done + ' / ' + data.progress.total + ' completado';
            document.getElementById('progress-pct').textContent = data.progress.pct + '%';
            document.getElementById('progress-bar-fill').style.width = data.progress.pct + '%';

            const meta = row.querySelector('[data-meta]');
            const itemState = data.state[key];
            if (itemState) {
                meta.textContent = 'Marcado ' + (itemState.checked ? '' : 'des') + 'hecho el ' + itemState.updated_at;
            }
        })
        .catch(e => console.error('Dev checklist save error:', e));
    });
});
</script>

</body>
</html>

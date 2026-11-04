<?php
declare(strict_types=1);
// AMD global: los 9 parámetros que usan todas las campañas, con versiones, comparación y vuelta a una versión anterior.
// Lectura y escritura por las funciones marcador_amd_global_* (sin SQL propio).
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
require_once __DIR__ . '/../../marcador/amd_audit.php';
use Includes\Auth;

Auth::checkAccess(9);
if (empty($_SESSION['amd_csrf'])) $_SESSION['amd_csrf'] = bin2hex(random_bytes(32));
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Rangos y explicación en lenguaje sencillo (orientativa; los rangos son los que valida el servicio).
$PARAMS = [
    'initial_silence' => [0, 60000, 'Cuánto silencio se tolera al inicio de la llamada antes de decidir que es una contestadora.'],
    'greeting' => [0, 60000, 'Duración máxima del saludo hablado. Si quien contesta habla más que esto de corrido, se considera contestadora.'],
    'after_greeting_silence' => [0, 60000, 'Silencio tras el saludo que indica que la persona terminó de hablar y espera respuesta: se considera humano.'],
    'total_analysis_time' => [0, 60000, 'Tiempo máximo total de análisis. Si se agota sin poder decidir, el resultado es «No seguro».'],
    'minimum_word_length' => [0, 60000, 'Duración mínima de un sonido para contarlo como palabra. Evita contar ruidos cortos.'],
    'between_words_silence' => [0, 60000, 'Silencio mínimo que separa una palabra de la siguiente.'],
    'maximum_number_of_words' => [1, 50, 'Cantidad máxima de palabras antes de decidir contestadora. Los humanos dicen pocas («¿Aló?», «Dígame»).'],
    'silence_threshold' => [0, 32767, 'Nivel de sonido por debajo del cual se considera silencio. Más alto = se exige más volumen para contar como voz.'],
    'maximum_word_length' => [0, 60000, 'Duración máxima de una palabra. Una «palabra» más larga se trata como contestadora o ruido.'],
];
function lima(string $utc): string {
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Lima'))->format('Y-m-d H:i'); } catch (Throwable $e) { return $utc; }
}

$error = '';
$message = (string)($_SESSION['amd_message'] ?? '');
unset($_SESSION['amd_message']);
$current = null; $history = [];
try {
    $db = bot_ivr_repository();
    $actor = (string)($_SESSION['user'] ?? 'admin');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (!hash_equals($_SESSION['amd_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sesión inválida. Recarga la página.');
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save') {
                $values = [];
                foreach ($PARAMS as $k => $_) if (isset($_POST[$k])) $values[$k] = $_POST[$k];
                $new = marcador_amd_global_save($db, $values, $actor, trim((string)($_POST['note'] ?? '')));
                $_SESSION['amd_message'] = 'Guardada la versión #' . $new['version_id'] . '. Se aplica a las llamadas nuevas de todas las campañas.';
            } elseif ($action === 'rollback') {
                $vid = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($vid === false) throw new RuntimeException('Versión inválida.');
                $new = marcador_amd_global_rollback($db, $vid, $actor);
                $_SESSION['amd_message'] = 'Se volvió a la versión #' . $vid . ' (guardada como versión #' . $new['version_id'] . ').';
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            header('Location: amd.php');
            exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $current = marcador_amd_global($db);
    $history = marcador_amd_global_history($db, 50);
} catch (Throwable $e) {
    $error = $error ?: 'No se pudo leer la configuración del AMD.';
}
require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMD - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .card + .card { margin-top: 1rem; }
        .amd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem; }
        .amd-field { border: 1px solid var(--border); border-radius: 2px; padding: 0.6rem; background: var(--bg-card); }
        .amd-field label { font-weight: 700; font-size: 0.82rem; display: block; margin-bottom: 0.3rem; }
        .amd-field input { width: 100%; box-sizing: border-box; }
        .amd-field p { font-size: 0.75rem; color: var(--text-muted); margin: 0.35rem 0 0; }
        .msg-ok { color: #10b981; margin-bottom: 1rem; } .msg-err { color: #fca5a5; margin-bottom: 1rem; }
        .scroll { overflow-x: auto; }
        td.diff { background: rgba(245, 158, 11, .22); font-weight: 700; }
        .cur { color: #10b981; font-weight: 700; }
        .note { font-size: 0.78rem; color: var(--text-muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-top: 0.75rem; }
    </style>
</head>
<body>
    <?php renderSidebar('amd'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">AMD</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Detección de contestadora: un solo juego de parámetros para todas las campañas</p>
            </div>
        </header>
        <?php if ($message !== ''): ?><p class="msg-ok"><?php echo h($message); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="msg-err"><?php echo h($error); ?></p><?php endif; ?>

<?php if ($current !== null): ?>
        <form method="POST" action="amd.php" class="card">
            <h2>Valores actuales
                <?php if (!empty($current['version_id'])): ?><span class="note"> · versión #<?php echo (int)$current['version_id']; ?>, <?php echo h(lima((string)$current['created_at'])); ?> (Lima), por <?php echo h($current['created_by']); ?></span>
                <?php else: ?><span class="note"> · valores iniciales aprobados (aún sin versiones guardadas)</span><?php endif; ?></h2>
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['amd_csrf']); ?>">
            <input type="hidden" name="action" value="save">
            <div class="amd-grid">
            <?php foreach ($PARAMS as $key => [$min, $max, $help]): ?>
                <div class="amd-field">
                    <label for="p_<?php echo h($key); ?>"><?php echo h(MARCADOR_AMD_LABELS[$key] ?? $key); ?></label>
                    <input type="number" id="p_<?php echo h($key); ?>" name="<?php echo h($key); ?>" value="<?php echo (int)$current[$key]; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" required>
                    <p><?php echo h($help); ?> <span class="note">(entre <?php echo $min; ?> y <?php echo $max; ?>)</span></p>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="actions">
                <label class="note" style="flex: 1; min-width: 220px;">Nota del cambio (opcional)<br><input type="text" name="note" maxlength="200" style="width: 100%; box-sizing: border-box;"></label>
                <button type="submit" class="btn-primary">Guardar como versión nueva</button>
                <button type="button" class="btn-action" disabled title="Requiere motor STT">Revisar con STT</button>
            </div>
            <p class="note">Cada guardado crea una versión nueva; las anteriores se conservan. El cambio vale para las llamadas que se lancen desde ahora, no para las que ya están en curso. «Revisar con STT» propondrá ajustes a partir de las grabaciones, pero requiere un motor STT que aún no está disponible.</p>
        </form>

        <div class="card">
            <h2>Historial de versiones</h2>
            <p class="note">Las celdas resaltadas son los valores que difieren de la versión actual.</p>
            <div class="scroll">
            <table class="table-compact">
                <thead><tr><th>Versión</th><th>Fecha (Lima)</th><th>Autor</th><th>Nota</th>
                    <?php foreach ($PARAMS as $key => $_): ?><th title="<?php echo h(MARCADOR_AMD_LABELS[$key] ?? $key); ?>"><?php echo h(preg_replace('/ \(.*\)$/', '', MARCADOR_AMD_LABELS[$key] ?? $key)); ?></th><?php endforeach; ?><th></th></tr></thead>
                <tbody>
                <?php foreach ($history as $v): ?>
                    <tr>
                        <td>#<?php echo (int)$v['version_id']; ?><?php if (!empty($v['is_current'])): ?> <span class="cur">actual</span><?php endif; ?></td>
                        <td><?php echo h(lima((string)$v['created_at'])); ?></td>
                        <td><?php echo h($v['created_by']); ?></td>
                        <td><?php echo h($v['note'] ?? ''); ?></td>
                        <?php foreach ($PARAMS as $key => $_): ?><td class="<?php echo (int)$v[$key] !== (int)$current[$key] ? 'diff' : ''; ?>"><?php echo (int)$v[$key]; ?></td><?php endforeach; ?>
                        <td>
                            <?php if (empty($v['is_current'])): ?>
                            <form method="POST" action="amd.php" style="display: inline;" onsubmit="return confirm(this.getAttribute('data-confirm'));" data-confirm="<?php echo h('Se volverá a los valores de la versión #' . (int)$v['version_id'] . ' (se guardará como versión nueva) y valdrán para las llamadas nuevas de todas las campañas. ¿Continuar?'); ?>">
                                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['amd_csrf']); ?>">
                                <input type="hidden" name="action" value="rollback">
                                <input type="hidden" name="version_id" value="<?php echo (int)$v['version_id']; ?>">
                                <button type="submit" class="btn-action">Volver a esta</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="14" class="note">Aún no hay versiones guardadas.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
<?php endif; ?>
    </main>
</body>
</html>

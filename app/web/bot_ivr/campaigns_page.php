<?php
declare(strict_types=1);
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/campaigns_service.php';
initial_survey_require_login();
if (empty($_SESSION['bot_campaign_csrf'])) $_SESSION['bot_campaign_csrf'] = bin2hex(random_bytes(32));
function bot_campaign_csrf(): void {
    if (!hash_equals($_SESSION['bot_campaign_csrf'], (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sesión inválida. Recarga la página.');
    }
}
function bot_campaign_token(): void {
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION['bot_campaign_csrf']) . '">';
}
function bot_campaign_redirect(string $target, string $message): void {
    $_SESSION['bot_campaign_message'] = $message;
    header('Location: ' . $target);
    exit;
}
$message = (string)($_SESSION['bot_campaign_message'] ?? '');
unset($_SESSION['bot_campaign_message']);
$error = '';
// La conexion ya no se configura aqui: un solo lugar centralizado en el
// panel de engranaje de IVR Builder (tabla ivr_deploy_config). Solo se
// valida que exista, para avisar si falta configurarla.
try { bot_ivr_db_config(); } catch (Throwable $e) { $error = $e->getMessage(); }
function bot_campaign_header(string $title): void {
    global $message, $error;
    initial_survey_page_start($title);
    echo <<<'CSS'
<style>
.bot-nav{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.bot-nav a{color:var(--primary-hover);font-size:13px;text-decoration:none;font-weight:600}.bot-nav a:hover{text-decoration:underline}
.bot-nav button{margin-left:auto}.bot-form{max-width:520px}.bot-table-wrap{overflow-x:auto}
@media(max-width:600px){.bot-nav button{margin-left:0}}
</style>
CSS;

    echo '<nav class="bot-nav"><a href="index.php">Mostrar campañas</a><a href="index.php?view=create">Crear campaña</a><a href="audio_lab.php">Prueba de audios</a></nav>';
    if ($message !== '') echo '<div class="carsa-msg">'.h($message).'</div>';
    if ($error !== '') echo '<div class="carsa-err">'.h($error).'</div>';
}
function bot_campaign_footer(): void {
    initial_survey_page_end();
}

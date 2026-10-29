<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

$sttPage = __DIR__ . '/stt_providers_app/stt_providers_admin.php';
ob_start();
require $sttPage;
$sttDocument = (string) ob_get_clean();
if (!preg_match('~<body[^>]*>(.*)</body>~is', $sttDocument, $sttMatch)) {
    $sttBody = '<p class="integration-error">No se pudo cargar el módulo STT.</p>';
} else {
    $sttBody = str_replace(
        'src="assets/stt_providers_admin.js',
        'src="stt_providers_app/assets/stt_providers_admin.js',
        $sttMatch[1]
    );
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Stt Providers - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
    <link rel="stylesheet" href="stt_providers_app/assets/stt_providers_admin.css">
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('stt_providers'); ?>
<div class="integration-shell">
    <header class="integration-head"><h1>Stt Providers</h1><p>Cuentas, API keys y pruebas de transcripción</p></header>
    <section class="integration-native stt-native">
        <?= $sttBody ?>
    </section>
</div>
<script>window.ZYNERVOX_STT_ENDPOINT = 'stt_providers.php';</script>
</body>
</html>

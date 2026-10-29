<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

$farmRoot = __DIR__ . '/farm_app';
$farmView = ($_GET['view'] ?? 'annexes') === 'proxies' ? 'proxies' : 'annexes';
$farmPage = $farmView === 'proxies' ? 'monitor.php' : 'annexes.php';

function renderFarmBody(string $page): string
{
    ob_start();
    require $page;
    $document = (string) ob_get_clean();
    if (!preg_match('~<body[^>]*>(.*)</body>~is', $document, $match)) {
        return '<p class="integration-error">No se pudo cargar el módulo Farm.</p>';
    }
    return str_replace(
        ['src="annexes.js', 'src="monitor/', 'src="world-map-real.svg'],
        ['src="farm_app/annexes.js', 'src="farm_app/monitor/', 'src="farm_app/monitor/world-map-real.svg'],
        $match[1]
    );
}

$farmBody = renderFarmBody($farmRoot . '/' . $farmPage);
ob_start();
require $farmRoot . '/' . $farmPage;
$farmDocument = (string) ob_get_clean();
$farmInlineCss = '';
if ($farmView === 'annexes' && preg_match_all('~<style[^>]*>(.*?)</style>~is', $farmDocument, $styleMatches)) {
    $farmInlineCss = implode("\n", $styleMatches[1]);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Farm - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
    <link rel="stylesheet" href="farm_app/monitor/styles.css">
    <?php if ($farmInlineCss !== ''): ?><style>@scope (.farm-native) { <?= $farmInlineCss ?> }</style><?php endif; ?>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('farm'); ?>
<div class="integration-shell">
    <header class="integration-head">
        <div><h1>Farm</h1><p>Anexos SIP, workers y proxies</p></div>
        <nav class="integration-tabs" aria-label="Secciones de Farm">
            <a class="<?= $farmView === 'annexes' ? 'active' : '' ?>" href="farm.php?view=annexes">Anexos</a>
            <a class="<?= $farmView === 'proxies' ? 'active' : '' ?>" href="farm.php?view=proxies">Monitor de proxies</a>
        </nav>
    </header>
    <script>
    window.ZYNERVOX_FARM_API = 'farm_app/api.php';
    window.ZYNERVOX_FARM_PROXY_API = 'farm_app/proxy_gateway.php';
    window.ZYNERVOX_FARM_PROXY_UPLOAD_API = 'farm_app/proxy_upload.php';
    window.ZYNERVOX_FARM_PROXY_FILES_API = 'farm_app/proxy_files.php';
    </script>
    <section class="integration-native farm-native">
        <?= $farmBody ?>
    </section>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/CampaignMonitor.php';

use Includes\Auth;
use Includes\CampaignMonitor;

Auth::checkAccess(9);

// La lista de campañas a monitorear vive en el navegador (localStorage),
// no en el servidor -- el cliente manda los ids que agregó con "+" como
// CSV en ?ids=A,B,C. Sin ids -> sin snapshots (no se golpea la BD con
// campañas que a nadie le interesa monitorear ahora mismo).
$idsParam = trim($_GET['ids'] ?? '');
$ids = $idsParam !== '' ? array_filter(array_map('trim', explode(',', $idsParam))) : [];
$snapshots = CampaignMonitor::getCampaignSnapshots($ids);

header('Content-Type: text/html; charset=UTF-8');
echo CampaignMonitor::renderHtml($snapshots);

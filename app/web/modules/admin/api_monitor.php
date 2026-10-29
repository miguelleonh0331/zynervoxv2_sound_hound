<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/AgentMonitor.php';

use Includes\Auth;
use Includes\AgentMonitor;

Auth::checkAccess(9);

$filter = $_GET['campaign_id'] ?? null;
$groups = AgentMonitor::getLiveByCampaign($filter ?: null);

header('Content-Type: text/html; charset=UTF-8');
echo AgentMonitor::renderHtml($groups);

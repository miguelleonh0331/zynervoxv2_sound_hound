<?php
// Landing de GTR/Supervisor: por ahora ambos comparten el mismo primer
// destino (Campañas). Auth::checkAccess ya filtra por nivel [7,8].
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

Auth::checkAccess([7, 8]);

header('Location: ../admin/campaigns.php');
exit;

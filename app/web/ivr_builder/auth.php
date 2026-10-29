<?php
declare(strict_types=1);
// Homologado a la sesion/roles de Zynervox (igual que bot_ivr/auth.php).
// ivr_builder/ vive un solo nivel bajo la raiz del proyecto.
require_once __DIR__ . '/../includes/Auth.php';

use Includes\Auth;

function initial_survey_is_authenticated(): bool {
    return isset($_SESSION['user']) && ($_SESSION['user_level'] ?? 0) == 9;
}

function ivr_builder_require_login(bool $json = false): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['ivr_config_csrf'])) $_SESSION['ivr_config_csrf'] = bin2hex(random_bytes(32));
    if (initial_survey_is_authenticated()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sesión requerida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isset($_SESSION['user'])) {
        header('Location: ../index.php');
        exit;
    }
    header('Location: ../' . Auth::getRedirectPath((int)($_SESSION['user_level'] ?? 0)));
    exit;
}

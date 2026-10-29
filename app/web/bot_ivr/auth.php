<?php
declare(strict_types=1);

// Homologado a la sesion/roles de Zynervox (antes era un login propio
// aislado del proyecto CARSA). bot_ivr/ vive un solo nivel bajo la raiz
// del proyecto (a diferencia de modules/xxx/, que vive dos niveles abajo),
// por eso NO se reusa Auth::checkAccess() directo (sus redirects estan
// escritos para "../../") -- se replica la misma logica con el prefijo
// relativo correcto para esta profundidad.
require_once __DIR__ . '/../includes/Auth.php';

use Includes\Auth;

function initial_survey_is_authenticated(): bool {
    return isset($_SESSION['user']) && ($_SESSION['user_level'] ?? 0) == 9;
}

function initial_survey_require_login(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user'])) {
        header('Location: ../index.php');
        exit;
    }
    if (($_SESSION['user_level'] ?? 0) != 9) {
        header('Location: ../' . Auth::getRedirectPath((int)$_SESSION['user_level']));
        exit;
    }
}

<?php
declare(strict_types=1);

// Shim de compatibilidad: el codigo migrado de CARSA/Bot IVR llama a
// carsa_db() por todo su codigo (agents.php, launch_*.php, campaign_*.php,
// etc.). En vez de reescribir cada llamada, este archivo hace que
// carsa_db() devuelva la misma conexion PDO que ya usa el resto de
// Zynervox (Includes\Database, credenciales de /etc/astguiclient.conf).
//
// NOTA: las tablas propias del Bot IVR (carsa_initial_survey_queue,
// synervox_campaigns, ivr_call_results, etc.) NO existen todavia en esta
// base de datos -- esto solo evita el fatal error de "require" faltante.
// Las paginas que consultan esas tablas hoy fallan de forma controlada
// (try/catch ya presente en el codigo original) hasta que se decida donde
// viven esas tablas (misma BD "asterisk" vs. una BD nueva dedicada).
require_once __DIR__ . '/../includes/Database.php';

if (!function_exists('carsa_db')) {
    function carsa_db(): \PDO {
        return \Includes\Database::getInstance();
    }
}

// Conexion dedicada para las tablas bot_ivr_flow* (flujos del IVR Builder),
// que viven en la base "zynervox" -- no en "asterisk" (carsa_db()).
if (!function_exists('bot_ivr_db')) {
    function bot_ivr_db(): \PDO {
        return \Includes\Database::getBotIvrInstance();
    }
}

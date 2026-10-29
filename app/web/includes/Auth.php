<?php

namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Auth {
    private static $sessionStarted = false;

    private static function startSession() {
        if (!self::$sessionStarted) {
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            self::$sessionStarted = true;
        }
    }

    public static function login($username, $password) {
        self::startSession();

        // 1. Tabla propia de zynervox (zynervox_users, BD zynervox_core):
        //    admin fijo, independiente de vicidial_users y de la BD
        //    compartida `asterisk`. Ver installer/zynervox-core.sh.
        try {
            $core = Database::getCoreInstance();
            $stmt = $core->prepare("SELECT user, pass, user_level, full_name
                                     FROM " . \Config\Config::coreTable('users') . "
                                     WHERE user = :user AND active = 'Y'
                                     LIMIT 1");
            $stmt->execute(['user' => $username]);
            $coreUser = $stmt->fetch();
            if ($coreUser && $password === $coreUser['pass']) {
                $_SESSION['user'] = $coreUser['user'];
                $_SESSION['user_level'] = (int)$coreUser['user_level'];
                $_SESSION['full_name'] = $coreUser['full_name'];
                Audit::logAccess($username, 'LOGIN', 'Successful login from website (zynervox_users)');
                return true;
            }
        } catch (Exception $e) {
            // zynervox_core no configurado o sin conexión: se sigue con vicidial_users.
        }

        if (\Config\Config::deployment('isolated', false)) return false;
        // 2. Fallback: vicidial_users en la BD compartida `asterisk`
        //    (astguiclient.conf). Hosts sin VICIdial/Asterisk instalado
        //    (ej. labs de prueba) no tienen ese archivo -- debe fallar como
        //    "login invalido", no reventar la pagina entera con 500.
        try {
            $db = Database::getInstance();

            // Columnas clave: user, pass, user_level, active
            $stmt = $db->prepare("SELECT user, pass, user_level, full_name
                                 FROM vicidial_users
                                 WHERE user = :user AND active = 'Y'
                                 LIMIT 1");
            $stmt->execute(['user' => $username]);
            $user = $stmt->fetch();

            if ($user) {
                // NOTA: Vicidial puede guardar passwords en texto plano o md5 según config.
                // Por simplicidad y compatibilidad inicial, validamos texto plano.
                if ($password === $user['pass']) {
                    $_SESSION['user'] = $user['user'];
                    $_SESSION['user_level'] = (int)$user['user_level'];
                    $_SESSION['full_name'] = $user['full_name'];

                    Audit::logAccess($username, 'LOGIN', 'Successful login from website');
                    return true;
                }
            }
        } catch (Exception $e) {
            // astguiclient.conf ausente/invalido: sin integracion VICIdial,
            // login invalido en vez de error fatal.
        }
        return false;
    }

    // $requiredLevel acepta un entero (nivel exacto) o un array de niveles
    // permitidos (ej. [7, 8] para paginas compartidas entre GTR y Supervisor).
    public static function checkAccess($requiredLevel) {
        self::startSession();
        if (!isset($_SESSION['user'])) {
            header("Location: ../../index.php");
            exit;
        }

        $allowed = is_array($requiredLevel) ? $requiredLevel : [$requiredLevel];
        if (!in_array($_SESSION['user_level'], $allowed)) {
            $correctPath = self::getRedirectPath($_SESSION['user_level']);
            // El path de getRedirectPath es relativo a la raíz del proyecto.
            // Puesto que estamos en /modules/xxx/index.php, necesitamos volver a la raíz.
            header("Location: ../../" . $correctPath);
            exit;
        }
    }

    // Niveles vigentes: 1=Agente (sin panel web propio, usa el modulo de
    // agente aparte), 7=GTR, 8=Supervisor (comparten /modules/sup), 9=Admin.
    public static function getRedirectPath($level) {
        // Rutas relativas desde la raíz del proyecto (donde está index.php)
        switch ($level) {
            case 1: return 'modules/agente/index.php';
            case 7:
            case 8: return 'modules/sup/index.php';
            case 9: return 'modules/admin/index.php';
            default: return 'index.php';
        }
    }

    public static function logout() {
        self::startSession();
        session_destroy();
        header("Location: index.php");
        exit;
    }
}

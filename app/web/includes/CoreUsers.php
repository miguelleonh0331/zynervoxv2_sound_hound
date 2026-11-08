<?php
declare(strict_types=1);

namespace Includes;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Permissions.php';

/**
 * Usuarios del panel (tabla propia zynervox_users de zynervox_core, la del login). El perfil es user_level: 9 Administrador, 8 Supervisor, 7 GTR.
 * No se borran usuarios: se desactivan (active = 'N'). Las claves nuevas se guardan con password_hash.
 * Todo error de validación es RuntimeException con un mensaje en español listo para mostrar.
 */
class CoreUsers {
    private static function table(): string { return \Config\Config::coreTable('users'); }

    public static function all(): array {
        $db = Database::getCoreInstance();
        return $db->query('SELECT user, full_name, user_level, active FROM ' . self::table() . ' ORDER BY user_level DESC, user ASC')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(string $user): ?array {
        $st = Database::getCoreInstance()->prepare('SELECT user, full_name, user_level, active FROM ' . self::table() . ' WHERE user = :u LIMIT 1');
        $st->execute(['u' => $user]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function checkProfile(int $level): void {
        if (!isset(Permissions::PROFILES[$level])) throw new \RuntimeException('Elige un perfil: Administrador, Supervisor o GTR.');
    }
    private static function checkName(string $fullName): string {
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName) > 100 || preg_match('/[\x00-\x1f]/u', $fullName)) throw new \RuntimeException('El nombre es obligatorio (hasta 100 caracteres).');
        return $fullName;
    }
    private static function checkPassword(string $password): void {
        if (strlen($password) < 8 || strlen($password) > 72) throw new \RuntimeException('La clave debe tener entre 8 y 72 caracteres.');
    }
    private static function activeAdmins(?string $except = null): int {
        $sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE user_level = 9 AND active = 'Y'" . ($except !== null ? ' AND user <> :u' : '');
        $st = Database::getCoreInstance()->prepare($sql);
        $st->execute($except !== null ? ['u' => $except] : []);
        return (int)$st->fetchColumn();
    }

    public static function create(string $user, string $password, string $fullName, int $level, string $actor): void {
        $user = trim($user);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/D', $user)) throw new \RuntimeException('El usuario debe tener de 3 a 60 caracteres: letras, números, punto, guion o guion bajo.');
        self::checkPassword($password); self::checkProfile($level); $fullName = self::checkName($fullName);
        if (self::find($user) !== null) throw new \RuntimeException('Ya existe un usuario con ese nombre.');
        $st = Database::getCoreInstance()->prepare('INSERT INTO ' . self::table() . " (user, pass, full_name, user_level, active) VALUES (:u, :p, :n, :l, 'Y')");
        $st->execute(['u' => $user, 'p' => password_hash($password, PASSWORD_DEFAULT), 'n' => $fullName, 'l' => $level]);
        Audit::logAccess($actor, 'USER_CREATE', 'Usuario ' . $user . ' creado con perfil ' . Permissions::PROFILES[$level]);
    }

    /** Cambia nombre, perfil y estado; $newPassword vacío = no cambia la clave. Nunca deja el sistema sin un Administrador activo. */
    public static function update(string $user, string $fullName, int $level, bool $active, string $newPassword, string $actor): void {
        $row = self::find($user);
        if ($row === null) throw new \RuntimeException('El usuario no existe.');
        self::checkProfile($level); $fullName = self::checkName($fullName);
        if ($newPassword !== '') self::checkPassword($newPassword);
        if ((int)$row['user_level'] === 9 && $row['active'] === 'Y' && ($level !== 9 || !$active) && self::activeAdmins($user) < 1) {
            throw new \RuntimeException('No se puede: debe quedar al menos un Administrador activo.');
        }
        if ($user === $actor && ($level !== 9 || !$active)) throw new \RuntimeException('No puedes quitarte tu propio perfil de Administrador ni desactivarte a ti mismo.');
        $sql = 'UPDATE ' . self::table() . ' SET full_name = :n, user_level = :l, active = :a' . ($newPassword !== '' ? ', pass = :p' : '') . ' WHERE user = :u';
        $params = ['n' => $fullName, 'l' => $level, 'a' => $active ? 'Y' : 'N', 'u' => $user];
        if ($newPassword !== '') $params['p'] = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::getCoreInstance()->prepare($sql)->execute($params);
        Audit::logAccess($actor, 'USER_UPDATE', 'Usuario ' . $user . ': perfil ' . Permissions::PROFILES[$level] . ($active ? ', activo' : ', desactivado') . ($newPassword !== '' ? ', clave cambiada' : ''));
    }
}

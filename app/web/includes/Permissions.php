<?php
declare(strict_types=1);

namespace Includes;

/**
 * Perfiles y permisos: UN solo mapa que usan el menú, las pantallas y las APIs.
 * El perfil es el user_level de zynervox_users (9 Administrador, 8 Supervisor, 7 GTR); no hay columna nueva.
 * Toda clave que NO esté en MAP es solo del Administrador (por defecto cerrado): una pantalla o acción nueva nace prohibida para los demás perfiles.
 * El servidor decide: las APIs rechazan con 403 aunque se llamen a mano; la pantalla solo oculta los botones.
 */
class Permissions {
    public const ADMIN = 9;
    public const SUPERVISOR = 8;
    public const GTR = 7;

    public const PROFILES = [self::ADMIN => 'Administrador', self::SUPERVISOR => 'Supervisor', self::GTR => 'GTR'];

    /** clave => niveles permitidos. */
    private const MAP = [
        // Pantallas (la clave es 'page.' + la clave del menú)
        'page.home'            => [9, 8, 7],   // Dashboard (index.php e index_v2.php)
        'page.reports'         => [9, 8, 7],   // Reportes, solo consulta
        'page.guia'            => [9, 8, 7],   // Guía (cada perfil ve sus temas, ver GUIDE_TOPICS)
        'page.lists'           => [9, 7],      // Listas
        // Dashboard: acciones
        'dash.start'           => [9, 7],
        'dash.stop'            => [9, 7],
        'dash.start_many'      => [9, 7],      // Iniciar todas (el botón Parar todas usa dash.stop)
        'dash.set_channels'    => [9, 7],
        'dash.activate'        => [9],
        'dash.deactivate'      => [9],         // Desactivar desde el Dashboard
        // Listas: acciones
        'lists.toggle_active'  => [9, 7],      // Activar / Desactivar
        'lists.set_channels'   => [9, 7],      // Aplicar canales
        'lists.upload'         => [9, 7],      // Cargar base
        'lists.template'       => [9, 7],      // Plantilla
        'lists.delete_list'    => [9],         // Eliminar listas
        'lists.set_server_max' => [9],         // Guardar máximo del servidor
        // Reportes: solo lectura
        'reports.read'         => [9, 8, 7],
    ];

    /** Temas de la Guía por perfil (null = todos). Los id son los de vendor/guia/guia_data.js. */
    private const GUIDE_TOPICS = [
        self::ADMIN => null,
        self::SUPERVISOR => ['dashboard', 'reportes'],
        self::GTR => ['dashboard', 'reportes', 'listas'],
    ];

    public static function level(): int {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return (int)($_SESSION['user_level'] ?? 0);
    }

    public static function profileName(?int $level = null): string {
        return self::PROFILES[$level ?? self::level()] ?? 'Sin perfil';
    }

    /** ¿Puede este perfil la clave? Lo no listado en el mapa es solo del Administrador. */
    public static function can(string $key, ?int $level = null): bool {
        $level ??= self::level();
        if (!isset(self::PROFILES[$level])) return false;
        return in_array($level, self::MAP[$key] ?? [self::ADMIN], true);
    }

    /** Temas de la Guía visibles para el perfil: null = todos, array vacío = ninguno. */
    public static function guideTopics(?int $level = null): ?array {
        $level ??= self::level();
        return array_key_exists($level, self::GUIDE_TOPICS) ? self::GUIDE_TOPICS[$level] : [];
    }

    /** Mapa completo para mostrarlo o documentarlo: clave => nombres de perfil permitidos. */
    public static function describe(): array {
        $out = [];
        foreach (self::MAP as $k => $levels) $out[$k] = array_map(fn($l) => self::PROFILES[$l], $levels);
        return $out;
    }

    /** Para las pantallas: las claves que el perfil actual puede hacer, listas para json_encode. */
    public static function flags(array $keys): array {
        $out = [];
        foreach ($keys as $k) $out[$k] = self::can($k);
        return $out;
    }

    /**
     * Guarda de pantalla. Sin sesión: al login. Perfil sin permiso: al Dashboard con un aviso (o al login si el perfil no existe, sin bucle).
     * $key = 'page.xxx' del mapa; una clave no listada exige Administrador.
     */
    public static function requirePage(string $key): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user'])) { header('Location: ../../index.php'); exit; }
        if (self::can($key)) return;
        if (!isset(self::PROFILES[self::level()])) { header('Location: ../../index.php'); exit; }
        $_SESSION['perm_notice'] = 'Tu perfil (' . self::profileName() . ') no tiene acceso a esa pantalla.';
        header('Location: ../../modules/admin/index.php');
        exit;
    }

    /** Guarda de API: 401 sin sesión, 403 con mensaje claro si el perfil no puede la acción. Devuelve solo si puede. */
    public static function requireApi(string $key): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['user'])) self::deny(401, 'Sesión requerida.');
        if (!self::can($key)) self::deny(403, 'Tu perfil (' . self::profileName() . ') no tiene permiso para hacer esto.');
    }

    private static function deny(int $code, string $message): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Aviso pendiente (una sola vez) para mostrarlo en el Dashboard tras una redirección. */
    public static function takeNotice(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $n = (string)($_SESSION['perm_notice'] ?? '');
        unset($_SESSION['perm_notice']);
        return $n;
    }
}

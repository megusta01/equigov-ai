<?php
/**
 * Plugin Name:       EquiGov Core
 * Description:       Diagnóstico institucional, planos de ação e rastreabilidade de recomendações para políticas públicas inclusivas.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Gustavo Figueredo
 * License:           GPL-2.0-or-later
 * Text Domain:       equigov-core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const EQUIGOV_CORE_VERSION = '0.2.0';
const EQUIGOV_CORE_FILE    = __FILE__;

// Composer quando disponível; senão, autoloader PSR-4 mínimo.
$equigovAutoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($equigovAutoload)) {
    require $equigovAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'EquiGov\\Core\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    });
}

register_activation_hook(__FILE__, [\EquiGov\Core\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\EquiGov\Core\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \EquiGov\Core\Plugin::instance()->boot();
});

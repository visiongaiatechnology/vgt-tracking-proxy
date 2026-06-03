<?php
/**
 * Plugin Name: VGT Selfhosted Open Core (VGT Gateway)
 * Plugin URI:  https://visiongaiatechnology.de
 * Description: Autarkes, hochperformantes Server-Side-Tracking Gateway für Meta CAPI & GA4 (Modularized).
 * Version:     2.3.3
 * Author:      VisionGaiaTechnology
 * License:     AGPLv3
 * Text Domain: vgt-tracking-proxy
 *
 * STATUS: Open Core
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

use Exception;
use Throwable;
use ErrorException;

defined('ABSPATH') || exit;

// ============================================================================
// SYSTEM BASICS & EXCEPTION HIERARCHY (PATTERN 1.5.A)
// ============================================================================

class AppException        extends Exception {}
class ValidationException extends AppException {} // USER-FACING: Nachricht wird unverändert angezeigt
class SecurityException    extends AppException {} // INTERNAL: Generische Nachricht an Client, Details ins error_log
class StorageException     extends AppException {} // INTERNAL: Generische Nachricht an Client, Details ins error_log

// ============================================================================
// ERROR HANDLER CONSISTENCY (PATTERN 1.5.C)
// ============================================================================

ini_set('display_errors', '0');              // Anzeige von Fehlern im Frontend unterdrückt
error_reporting(E_ALL);                      // Maximale Sensitivität für interne Fehleraufzeichnungen
set_error_handler(static function(int $sev, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $sev)) {
        return false;
    }
    throw new ErrorException($msg, 0, $sev, $file, $line);
});

// ============================================================================
// MODULAR LOADING (DEPENDENCY INJECTS)
// ============================================================================

require_once plugin_dir_path(__FILE__) . 'includes/class-cryptor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-config.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-anonymizer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-audit-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-meta-capi-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ga4-mp-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-queue-dispatcher.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-woocommerce-bridge.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-console.php';

// ============================================================================
// SYSTEM BOOTSTRAPPER (ACTIVATION & LIFE CYCLE)
// ============================================================================

register_activation_hook(__FILE__, static function(): void {
    AuditLogger::createTable();
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('vgt_proxy_audit_init', [], 'vgt-tracking');
    }
    // Log-Rotation-Planung registrieren
    if (!wp_next_scheduled('vgt_proxy_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'vgt_proxy_daily_cleanup');
    }
});

register_deactivation_hook(__FILE__, static function(): void {
    wp_clear_scheduled_hook('vgt_proxy_daily_cleanup');
});

/**
 * Hook-Priorität auf 11 gestellt, um sicherzustellen, dass WooCommerce geladen ist.
 * Der Bootstrapper wurde optimiert, um die Admin-Konsole unabhängig von WooCommerce
 * zu initialisieren (Sicherstellung der Systemkonfiguration und Log-Einsicht).
 */
add_action('plugins_loaded', static function(): void {
    if (class_exists('WooCommerce')) {
        QueueDispatcher::registerHooks();
        WooCommerceBridge::init();
    }

    // Tägliche Log-Bereinigung einhängen
    add_action('vgt_proxy_daily_cleanup', [AuditLogger::class, 'purgeOldLogs']);

    // Unkonditionale Initialisierung der Admin-Hooks zur Vermeidung von AJAX/Context-Sperren
    AdminConsole::init();
}, 11);
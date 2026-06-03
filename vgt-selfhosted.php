<?php
/**
 * Plugin Name: VGT Selfhosted Open Core (VGT Gateway)
 * Plugin URI:  https://visiongaiatechnology.de
 * Description: Autarkes, hochperformantes Server-Side-Tracking Gateway für Meta CAPI & GA4 (Modularized).
 * Version:     2.3.4
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
class SecurityException   extends AppException {} // INTERNAL: Generische Nachricht an Client, Details ins error_log
class StorageException    extends AppException {} // INTERNAL: Generische Nachricht an Client, Details ins error_log

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
// L4/L7 ACTIVE DEFENSIF-SHIELD (CSRF & SECURITY GUARD)
// ============================================================================

final class SecurityGuard {
    /**
     * Überprüft alle POST-Anfragen, die auf sensitive VGT-Daten abzielen, auf CSRF-Validität.
     */
    public static function verifyCSRF(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // 1. Validierung der globalen WordPress Options-Pipeline für VGT
        if (isset($_POST['option_page']) && $_POST['option_page'] === 'vgt_tracking_group') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'vgt_tracking_group-options')) {
                throw new SecurityException('Kritischer CSRF-Verifikationsfehler beim Speichern der Pipeline-Einstellungen.');
            }
        }

        // 2. Validierung manueller Log-Löschungsversuche
        if (isset($_POST['vgt_purge_logs'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'vgt_purge_action')) {
                throw new SecurityException('Kritischer CSRF-Verifikationsfehler beim Bereinigen des Audit-Logs.');
            }
        }
    }

    /**
     * Erzwingt strikte Context-Sicherheit und blockiert administrative Zugriffs-Anomalien.
     */
    public static function enforceContextSafety(): void {
        if (is_admin()) {
            // Verhindert, dass nicht-autorisierte Accounts Background-Hooks oder Interface-Routings manipulieren
            if (isset($_REQUEST['page']) && $_REQUEST['page'] === 'vgt-tracking-proxy') {
                if (!current_user_can('manage_options')) {
                    throw new SecurityException('Unautorisierter Zugriffspfad auf administrative Konsole blockiert.');
                }
            }
        }
    }

    /**
     * Injektiert systemweit strikte Sicherheits-Header zur Abwehr von Browser-basierten Cross-Site-Angriffen.
     */
    public static function injectSecureHeaders(): void {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('X-XSS-Protection: 1; mode=block');
        }
    }
}

// ============================================================================
// SYSTEM BOOTSTRAPPER (ACTIVATION & LIFE CYCLE)
// ============================================================================

register_activation_hook(__FILE__, static function(): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    
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
    if (!current_user_can('deactivate_plugins')) {
        return;
    }
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

    // Tägliche Log-Bereinigung mit Schutzbarriere versehen
    add_action('vgt_proxy_daily_cleanup', static function(): void {
        if (!defined('DOING_CRON') && !current_user_can('manage_options')) {
            error_log('[SEC] Manipulativer manueller Trigger-Versuch der Log-Rotation abgelehnt.');
            return;
        }
        AuditLogger::purgeOldLogs();
    });

    // Unkonditionale Initialisierung der Admin-Hooks zur Vermeidung von AJAX/Context-Sperren
    AdminConsole::init();
}, 11);

// ============================================================================
// SYSTEM SECURITY REGISTRATION (INTERCEPTORS)
// ============================================================================

// Header-Hardening so früh wie möglich initialisieren
add_action('init', [SecurityGuard::class, 'injectSecureHeaders'], 1);
add_action('admin_init', [SecurityGuard::class, 'injectSecureHeaders'], 1);

// CSRF & Zugriffsschutz-Orchestrierung
add_action('admin_init', static function(): void {
    try {
        SecurityGuard::verifyCSRF();
        SecurityGuard::enforceContextSafety();
    } catch (SecurityException $e) {
        // Loggen des Angriffsversuchs für Audits
        error_log('[SECURITY_VIOLATION] ' . $e->getMessage());
        
        // Ausgabe einer sicheren, un-orakelbaren Fehlermeldung
        wp_die(
            esc_html__('Anfrage aus Sicherheitsgründen abgelehnt. Ungültige Signatur oder unzureichende Berechtigungen.', 'vgt-tracking-proxy'),
            esc_html__('Sicherheitsverletzung blockiert', 'vgt-tracking-proxy'),
            ['response' => 403]
        );
    }
}, 1);

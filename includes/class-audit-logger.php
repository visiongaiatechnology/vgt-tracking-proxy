<?php
/**
 * Audit Logger & Rotator
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

use Throwable;

defined('ABSPATH') || exit;

final class AuditLogger {
    public const TABLE_NAME = 'vgt_tracking_audit_log';

    public static function createTable(): void {
        global $wpdb;
        $tableName = $wpdb->prefix . self::TABLE_NAME;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $tableName (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            event_name varchar(100) NOT NULL,
            api_target varchar(50) NOT NULL,
            status_code int(5) NOT NULL,
            request_payload longtext NOT NULL,
            response_payload longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY event_name (event_name),
            KEY api_target (api_target)
        ) $charsetCollate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log(string $eventName, string $target, int $statusCode, string $request, string $response): void {
        global $wpdb;
        $tableName = $wpdb->prefix . self::TABLE_NAME;

        try {
            $inserted = $wpdb->insert(
                $tableName,
                [
                    'event_name'       => sanitize_text_field($eventName),
                    'api_target'       => sanitize_text_field($target),
                    'status_code'      => $statusCode,
                    'request_payload'  => wp_json_encode(json_decode($request, true) ?? $request),
                    'response_payload' => wp_json_encode(json_decode($response, true) ?? $response),
                ],
                ['%s', '%s', '%d', '%s', '%s']
            );
            if ($inserted === false) {
                throw new StorageException('SQL-Insert für Auditlog fehlgeschlagen: ' . $wpdb->last_error);
            }
        } catch (Throwable $e) {
            error_log('[STORAGE] ' . $e->getMessage());
        }
    }

    /**
     * Verhindert unbegrenztes Tabellenwachstum (Log-Rotation).
     * Löscht Einträge, die älter als 30 Tage sind.
     */
    public static function purgeOldLogs(): void {
        global $wpdb;
        $tableName = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("DELETE FROM $tableName WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
}
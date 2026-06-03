<?php
/**
 * Gateway Configuration Wrapper
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class Config {
    private const OPTION_KEY = 'vgt_tracking_proxy_settings';

    /**
     * Holt alle Konfigurationseinstellungen aus der WordPress-Options-Tabelle.
     *
     * @return array<string, mixed>
     */
    public static function getAll(): array {
        $defaults = [
            'meta_enabled'       => 0,
            'meta_pixel_id'      => '',
            'meta_api_token'     => '',
            'ga4_enabled'        => 0,
            'ga4_measurement_id' => '',
            'ga4_api_secret'     => '',
            'anonymize_ip'       => 1,
            'debug_logging'      => 0,
        ];
        $stored = get_option(self::OPTION_KEY, []);
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    public static function get(string $key, mixed $default = null): mixed {
        $all = self::getAll();
        return $all[$key] ?? $default;
    }
}
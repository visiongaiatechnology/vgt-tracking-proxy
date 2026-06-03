<?php
/**
 * Data Anonymization & Attribution Engine
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class Anonymizer {
    /**
     * Bereinigt und hasht PII-Daten (E-Mail, Telefon) deterministisch mit SHA-256.
     */
    public static function hashPII(string $raw, string $type): string {
        $normalized = trim($raw);
        $normalized = mb_strtolower($normalized, 'UTF-8');

        if ($type === 'email') {
            if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                return '';
            }
        } elseif ($type === 'phone') {
            $normalized = preg_replace('/[^\d+]/', '', $normalized) ?? '';
        }

        return hash('sha256', $normalized);
    }

    /**
     * Maskiert IP-Adressen zur Wahrung der DSGVO-Souveränität.
     * Unterstützt hochpräzise IPv4-Maskierung (/24-Äquivalent) und IPv6-Maskierung (/64-Äquivalent).
     * Löst das Risiko komprimierter IPv6-Notation über ein duales Verarbeitungsverfahren.
     */
    public static function anonymizeIp(string $ip): string {
        $ip = trim($ip);
        if (empty($ip)) {
            return '0.0.0.0';
        }

        // 1. IPv4-Anonymisierung (Letztes Oktett auf 0 nullen)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts);
            }
        }

        // 2. IPv6-Anonymisierung (Letzte 4 Blöcke / 64 Bits auf 0 nullen)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Bevorzugter Weg: Hochperformante Byte-Manipulation im RAM
            if (function_exists('inet_pton') && function_exists('inet_ntop')) {
                $packed = @inet_pton($ip);
                if ($packed !== false) {
                    // IPv6 besteht aus 16 Bytes (128 Bits). Die hinteren 8 Bytes (64 Bits) werden genullt.
                    for ($i = 8; $i < 16; $i++) {
                        $packed[$i] = "\x00";
                    }
                    $masked = @inet_ntop($packed);
                    return $masked !== false ? $masked : '::';
                }
            }

            // Fallback-Weg: Deterministische String-Expansion für komprimierte Notationen
            return self::anonymizeIpv6Fallback($ip);
        }

        return '0.0.0.0';
    }

    /**
     * Pure-PHP Fallback-Methode zur fehlerfreien Expandierung und Maskierung komprimierter IPv6-Adressen.
     */
    private static function anonymizeIpv6Fallback(string $ip): string {
        // Expandiert das doppelte Doppelpunkt-Shorthand "::"
        if (str_contains($ip, '::')) {
            $parts = explode('::', $ip, 2);
            $left  = !empty($parts[0]) ? explode(':', $parts[0]) : [];
            $right = !empty($parts[1]) ? explode(':', $parts[1]) : [];
            
            $missingCount = 8 - (count($left) + count($right));
            if ($missingCount < 0) {
                return '::';
            }
            
            $expandedParts = array_merge(
                $left,
                array_fill(0, $missingCount, '0000'),
                $right
            );
        } else {
            $expandedParts = explode(':', $ip);
        }

        // Letzte 4 Blöcke (64 Bits) für DSGVO-Souveränität vollständig nullen
        for ($i = 4; $i < 8; $i++) {
            $expandedParts[$i] = '0000';
        }

        // Normalisieren der Hexadezimal-Blöcke (Füllung auf 4 Zeichen)
        $normalizedParts = array_map(static function(string $part): string {
            $part = preg_replace('/[^a-f0-9]/i', '', $part) ?? '';
            return str_pad($part, 4, '0', STR_PAD_LEFT);
        }, $expandedParts);

        if (count($normalizedParts) !== 8) {
            return '::';
        }

        return implode(':', $normalizedParts);
    }

    /**
     * Generiert eine persistente Client-ID im Fallback-Modus.
     */
    public static function getFallbackClientId(): string {
        $cookieName = '_vgt_ss_cid';
        if (isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])) {
            $cid = preg_replace('/[^a-f0-9\-]/i', '', $_COOKIE[$cookieName]);
            if (!empty($cid)) {
                return $cid;
            }
        }
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
        setcookie($cookieName, $uuid, time() + (3600 * 24 * 365), '/', '', true, true);
        return $uuid;
    }

    /**
     * Liest die echte GA-Client-ID aus dem Google Analytics _ga Cookie aus.
     */
    public static function getGaClientId(): string {
        if (isset($_COOKIE['_ga']) && is_string($_COOKIE['_ga'])) {
            $parts = explode('.', $_COOKIE['_ga']);
            $count = count($parts);
            if ($count >= 4) {
                return $parts[$count - 2] . '.' . $parts[$count - 1];
            } elseif ($count >= 2) {
                return $parts[$count - 2] . '.' . $parts[$count - 1];
            }
        }
        return self::getFallbackClientId();
    }

    /**
     * Extrahiert die GA4 Session ID des Benutzers aus dem zugehörigen dynamic Cookie.
     */
    public static function getGaSessionId(string $measurementId = ''): ?string {
        $containerId = '';
        if (!empty($measurementId)) {
            $containerId = str_ireplace('G-', '', $measurementId);
        }

        if (!empty($containerId)) {
            $cookieName = '_ga_' . $containerId;
            if (isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])) {
                $sid = self::extractSessionId($_COOKIE[$cookieName]);
                if ($sid) {
                    return $sid;
                }
            }
        }

        foreach ($_COOKIE as $key => $value) {
            if (str_starts_with($key, '_ga_') && is_string($value)) {
                $sid = self::extractSessionId($value);
                if ($sid) {
                    return $sid;
                }
            }
        }
        return null;
    }

    private static function extractSessionId(string $cookieValue): ?string {
        $parts = explode('.', $cookieValue);
        if (count($parts) >= 3) {
            return $parts[2];
        }
        return null;
    }

    /**
     * Holt das Meta _fbp (Facebook Browser Pixel) Cookie.
     */
    public static function getFbp(): ?string {
        if (isset($_COOKIE['_fbp']) && is_string($_COOKIE['_fbp'])) {
            return sanitize_text_field($_COOKIE['_fbp']);
        }
        return null;
    }

    /**
     * Holt das Meta _fbc (Facebook Click ID) Cookie oder generiert es aus dem Query-Parameter.
     */
    public static function getFbc(): ?string {
        if (isset($_COOKIE['_fbc']) && is_string($_COOKIE['_fbc'])) {
            return sanitize_text_field($_COOKIE['_fbc']);
        }
        if (isset($_GET['fbclid']) && is_string($_GET['fbclid'])) {
            return 'fb.1.' . time() . '.' . sanitize_text_field($_GET['fbclid']);
        }
        return null;
    }
}

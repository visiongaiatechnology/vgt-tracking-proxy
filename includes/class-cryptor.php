<?php
/**
 * Cryptography Engine (AES-256-GCM with AAD Site-Binding)
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class Cryptor {
    /**
     * Leitet einen hoch-entropischen, deterministischen 256-Bit-Schlüssel ab.
     * Durchsucht alle WordPress-Core-Salts zur Maximierung der kryptografischen Stärke.
     */
    private static function getKey(): string {
        $salts = '';
        $keysToCheck = [
            'SECURE_AUTH_KEY',
            'AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'SECURE_AUTH_SALT',
            'AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT'
        ];

        foreach ($keysToCheck as $const) {
            if (defined($const)) {
                $salts .= constant($const);
            }
        }

        // Falls die wp-config.php anomaliebedingt keine Salts aufweist,
        // weichen wir auf einen dynamisch generierten, persistenten System-Salt aus.
        if (empty($salts)) {
            $salts = get_option('vgt_proxy_system_salt');
            if (empty($salts)) {
                try {
                    $salts = bin2hex(random_bytes(32));
                    update_option('vgt_proxy_system_salt', $salts);
                } catch (\Throwable $e) {
                    // Letzter Notanker bei Totalausfall der kryptografischen Zufallsquellen des OS
                    $salts = hash('sha256', (string)wp_hash('vgt-critical-emergency-salt'));
                }
            }
        }

        return hash_hmac('sha256', 'vgt_proxy_encryption_key_v2', $salts, true);
    }

    /**
     * Legacy-Key-Derivierung zur Gewährleistung der Abwärtskompatibilität für Altdaten.
     */
    private static function getLegacyKey(): string {
        $salt = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'vgt-fallback-salt-32-chars-long!!!';
        return hash_hmac('sha256', 'vgt_proxy_encryption_key', $salt, true);
    }

    /**
     * Generiert deterministische Additional Associated Data (AAD).
     * Bindet den verschlüsselten Datenbestand an den Hostnamen der aktuellen WP-Installation.
     */
    private static function getAAD(): string {
        $domain = 'vgt-gateway-local';
        if (function_exists('home_url')) {
            $domain = parse_url(home_url(), PHP_URL_HOST) ?: home_url();
        }
        return 'vgt-gcm-binding:' . sanitize_text_field($domain);
    }

    /**
     * Verschlüsselt Klartext mit AES-256-GCM und sicherem AAD-VGT-Site-Binding.
     */
    public static function encrypt(string $plainText): string {
        if (empty($plainText)) {
            return '';
        }
        $method = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($method);
        if ($ivLength === false) {
            throw new SecurityException('Kryptografischer Systemfehler: Ungültige IV-Länge.');
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $plainText,
            $method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::getAAD(),
            16
        );

        if ($ciphertext === false) {
            throw new SecurityException('Kryptografischer Verschlüsselungsfehler im VGT-Kernel.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Entschlüsselt einen Base64-String unter Validierung des GCM-Tags und des AAD-Bindings.
     * Enthält ein Graceful-Fallback für Legacy-Verschlüsselungen zur Vermeidung von Betriebsunterbrechungen.
     */
    public static function decrypt(string $encryptedBase64): string {
        if (empty($encryptedBase64)) {
            return '';
        }
        $data = base64_decode($encryptedBase64, true);
        if ($data === false || strlen($data) < 28) { // 12 Bytes IV + 16 Bytes Tag
            return '';
        }
        
        $method = 'aes-256-gcm';
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);
        
        // 1. Moderne Entschlüsselung mit striktem AAD-Site-Binding
        $decrypted = openssl_decrypt(
            $ciphertext,
            $method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::getAAD()
        );

        // 2. Abwärtskompatible Überprüfung (Graceful Upgrade Path für Bestandsdaten)
        if ($decrypted === false) {
            $decrypted = openssl_decrypt(
                $ciphertext,
                $method,
                self::getLegacyKey(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '' // Legacy besaß kein AAD-Binding
            );
        }

        return $decrypted === false ? '' : $decrypted;
    }
}

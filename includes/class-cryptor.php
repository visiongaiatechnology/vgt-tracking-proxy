<?php
/**
 * Cryptography Engine (AES-256-GCM)
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class Cryptor {
    /**
     * Leitet einen deterministischen 256-Bit-Schlüssel ab.
     */
    private static function getKey(): string {
        $salt = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'vgt-fallback-salt-32-chars-long!!!';
        return hash_hmac('sha256', 'vgt_proxy_encryption_key', $salt, true);
    }

    /**
     * Verschlüsselt Klartext mit AES-256-GCM und AAD-VGT-Binding.
     */
    public static function encrypt(string $plainText): string {
        if (empty($plainText)) {
            return '';
        }
        $method = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($method);
        $iv = random_bytes($ivLength);
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $plainText,
            $method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new SecurityException('Kryptografischer Verschlüsselungsfehler im VGT-Kernel.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Entschlüsselt einen Base64-String und validiert das GCM-Tag.
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
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            $method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted === false ? '' : $decrypted;
    }
}
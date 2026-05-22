<?php
/**
 * WSS Crypto — AES-256-CBC helpers for at-rest secret storage.
 *
 * Uses a key derived from WordPress AUTH_KEY. The derivation matches the one
 * in WSS_Google_OAuth so credentials remain cross-compatible between the two.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Crypto
{
    /**
     * Encrypt a plaintext value for DB storage.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $key    = self::storage_key();
        $iv     = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a value previously written by encrypt(). Returns '' on failure.
     */
    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $key  = self::storage_key();
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 17) {
            return '';
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Decrypt, falling back to the original value when it was stored as plaintext.
     */
    public static function decrypt_maybe_plain(string $value): string
    {
        $decoded = self::decrypt($value);
        if ($decoded !== '') {
            return $decoded;
        }

        return $value;
    }

    /**
     * Derive a 32-byte key from WordPress AUTH_KEY for local DB encryption.
     */
    private static function storage_key(): string
    {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'wss-fallback-key-change-me';
        return hash('sha256', $salt . 'wss_credentials', true);
    }
}

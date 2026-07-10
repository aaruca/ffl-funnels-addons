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
    /** Marker for encrypted values using HMAC (prevents fallback). */
    const ENCRYPTED_PREFIX = 'WSS1:';

    /**
     * Encrypt a plaintext value for DB storage.
     *
     * Uses AES-256-CBC with HMAC-SHA256 (encrypt-then-MAC pattern) for
     * authenticated encryption. Format: WSS1:base64(iv.ciphertext.hmac)
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

        $payload = $iv . $cipher;
        $hmac = hash_hmac('sha256', $payload, $key, true);

        return self::ENCRYPTED_PREFIX . base64_encode($payload . $hmac);
    }

    /**
     * Decrypt a value previously written by encrypt(). Returns '' on failure.
     *
     * Verifies HMAC before decrypting. Fails if HMAC is invalid (tampering detected).
     */
    public static function decrypt(string $encoded): string
    {
        if ($encoded === '' || strpos($encoded, self::ENCRYPTED_PREFIX) !== 0) {
            return '';
        }

        $key  = self::storage_key();
        $data = base64_decode(substr($encoded, strlen(self::ENCRYPTED_PREFIX)), true);

        if ($data === false || strlen($data) < 48) {
            return '';
        }

        $payload = substr($data, 0, -32);
        $hmac    = substr($data, -32);

        $expected_hmac = hash_hmac('sha256', $payload, $key, true);
        if (!hash_equals($hmac, $expected_hmac)) {
            return '';
        }

        $iv     = substr($payload, 0, 16);
        $cipher = substr($payload, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Decrypt, falling back to the original value when it was stored as plaintext.
     *
     * DEPRECATED: Only for emergency migration from plaintext-stored credentials.
     * Once all credentials are encrypted with HMAC, this can be removed.
     *
     * Use decrypt() instead for all new code.
     */
    public static function decrypt_maybe_plain(string $value): string
    {
        if (strpos($value, self::ENCRYPTED_PREFIX) === 0) {
            return self::decrypt($value);
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

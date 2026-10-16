<?php
/**
 * security.php - app-level crypto helpers.
 *
 * The only thing stored here is an API secret, and it is never stored in the
 * clear: encrypt_secret() seals it with AES-256-GCM under APP_KEY, and the
 * result is base64(iv || tag || ciphertext). The plaintext is only ever held
 * in memory for the moment it is written, and is never rendered back out.
 */
declare(strict_types=1);

/** The raw 32-byte key, or null when APP_KEY is missing/invalid. */
function app_key_bytes(): ?string
{
    if (APP_KEY === '') {
        return null;
    }

    $raw = base64_decode(APP_KEY, true);
    if ($raw === false || strlen($raw) !== 32) {
        return null;
    }

    return $raw;
}

function crypto_available(): bool
{
    return app_key_bytes() !== null;
}

/**
 * Seal a secret. Returns null when no valid APP_KEY is configured, so the
 * caller can refuse the write instead of storing plaintext.
 */
function encrypt_secret(string $plaintext): ?string
{
    $key = app_key_bytes();
    if ($key === null) {
        return null;
    }

    $iv  = random_bytes(12);                       // GCM standard nonce
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($cipher === false) {
        return null;
    }

    return base64_encode($iv . $tag . $cipher);
}

/** Open a sealed secret. Returns null when the key is wrong or data is corrupt. */
function decrypt_secret(string $sealed): ?string
{
    $key = app_key_bytes();
    if ($key === null) {
        return null;
    }

    $blob = base64_decode($sealed, true);
    if ($blob === false || strlen($blob) < 12 + 16) {
        return null;
    }

    $iv     = substr($blob, 0, 12);
    $tag    = substr($blob, 12, 16);
    $cipher = substr($blob, 28);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return $plain === false ? null : $plain;
}

/**
 * The only representation of a secret the UI ever sees:
 * "sk-" + 18 dots + the last four characters.
 */
function mask_secret(string $last4): string
{
    return 'sk-' . str_repeat('.', 18) . $last4;
}

/**
 * Describe a secret for the server log without revealing it.
 *
 * Returns length only (and, for a value whose prefix is itself non-secret, that
 * prefix). Never return any part of the secret body - a log line must not be
 * enough to reconstruct or partially guess a credential.
 */
function secret_shape(string $secret): string
{
    if ($secret === '') {
        return 'empty';
    }
    return 'len:' . strlen($secret);
}

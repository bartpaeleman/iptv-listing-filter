<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\EnvLoader;
use RuntimeException;

final class CryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(?string $hexKey = null)
    {
        $rawHex = $hexKey ?? (EnvLoader::get('APP_KEY') ?? '');
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $rawHex)) {
            throw new RuntimeException('Invalid APP_KEY: expected 64-character hex string.');
        }

        $binaryKey = hex2bin($rawHex);
        if ($binaryKey === false || strlen($binaryKey) !== 32) {
            throw new RuntimeException('Failed to decode APP_KEY.');
        }

        $this->key = $binaryKey;
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLen === false || $ivLen <= 0) {
            throw new RuntimeException('Unable to determine cipher IV length.');
        }

        $iv = random_bytes($ivLen);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new RuntimeException('Invalid base64 payload for decryption.');
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLen === false || strlen($raw) < ($ivLen + self::TAG_LENGTH)) {
            throw new RuntimeException('Encrypted payload is too short.');
        }

        $iv = substr($raw, 0, $ivLen);
        $tag = substr($raw, $ivLen, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLen + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed or authentication tag mismatch.');
        }

        return $plaintext;
    }
}

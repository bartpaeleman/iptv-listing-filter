<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\EnvLoader;
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
            throw new RuntimeException('Ongeldige APP_KEY: 64-char hex vereist.');
        }

        $binaryKey = hex2bin($rawHex);
        if ($binaryKey === false || strlen($binaryKey) !== 32) {
            throw new RuntimeException('Kan APP_KEY niet decoderen.');
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
            throw new RuntimeException('Kan cipher IV-lengte niet bepalen.');
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
            throw new RuntimeException('Encryptie mislukt.');
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
            throw new RuntimeException('Ongeldige base64 payload.');
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLen === false || strlen($raw) < ($ivLen + self::TAG_LENGTH)) {
            throw new RuntimeException('Versleutelde payload is te kort.');
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
            throw new RuntimeException('Decryptie mislukt.');
        }

        return $plaintext;
    }
}

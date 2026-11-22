<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpClient
{
    public function downloadToFile(string $url, string $destPath, int $timeoutSeconds = 300): void
    {
        if (is_file($url) && is_readable($url)) {
            if (!copy($url, $destPath)) {
                throw new RuntimeException('Kan lokaal bronbestand niet kopiëren.');
            }
            return;
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Kan tijdelijk downloadbestand niet aanmaken.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new RuntimeException('cURL initialisatie mislukt.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'IPTV-EPG-Manager/1.1',
            CURLOPT_ENCODING => '',
            CURLOPT_FAILONERROR => true,
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok) {
            @unlink($destPath);
            throw new RuntimeException('Download mislukt: ' . $err);
        }
    }
}

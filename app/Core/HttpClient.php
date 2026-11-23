<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpClient
{
    public function get(string $url, int $timeoutSeconds = 60): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'iptv_http_');
        if ($tmpFile === false) {
            throw new RuntimeException('Kan tijdelijk bestand niet aanmaken voor HTTP-verzoek.');
        }

        try {
            $this->downloadToFile($url, $tmpFile, $timeoutSeconds, false);
            $content = file_get_contents($tmpFile);
            if ($content === false) {
                throw new RuntimeException('Kan gedownload bestand niet lezen.');
            }
            return $content;
        } finally {
            @unlink($tmpFile);
        }
    }

    public function downloadToFile(string $url, string $destPath, int $timeoutSeconds = 300, bool $gunzipIfGz = false): void
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

        $isGzUrl = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz');
        $downloadTarget = ($gunzipIfGz && $isGzUrl) ? ($destPath . '.gz.tmp') : $destPath;

        $fp = fopen($downloadTarget, 'wb');
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
            CURLOPT_FAILONERROR => false,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errno !== 0) {
            @unlink($downloadTarget);
            if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
                throw new RuntimeException('DNS-fout: hostnaam niet gevonden (' . (parse_url($url, PHP_URL_HOST) ?: $url) . ').');
            }
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException("Time-out na {$timeoutSeconds}s bij verbinden met bron.");
            }
            throw new RuntimeException("Netwerkfout (cURL #{$errno}): {$err}");
        }

        if ($httpCode >= 400) {
            @unlink($downloadTarget);
            $hint = match ($httpCode) {
                401, 403 => ' (ongeldige gebruikersnaam/wachtwoord of toegang geweigerd)',
                404 => ' (bestand of endpoint niet gevonden)',
                default => '',
            };
            throw new RuntimeException("HTTP {$httpCode}{$hint}");
        }

        if ($gunzipIfGz && $isGzUrl) {
            $this->streamGunzip($downloadTarget, $destPath);
            @unlink($downloadTarget);
        }
    }

    private function streamGunzip(string $gzPath, string $destPath): void
    {
        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Kan .gz bestand niet openen voor decompressie.');
        }
        $out = fopen($destPath, 'wb');
        if ($out === false) {
            gzclose($gz);
            throw new RuntimeException('Kan doelbestand voor decompressie niet aanmaken.');
        }

        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false) {
                gzclose($gz);
                fclose($out);
                throw new RuntimeException('Fout tijdens streaming .gz decompressie.');
            }
            fwrite($out, $chunk);
        }

        gzclose($gz);
        fclose($out);
    }
}

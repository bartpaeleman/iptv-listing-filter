<?php

declare(strict_types=1);

namespace App\Services;

final class XtreamUrlBuilder
{
    public static function buildM3uPlusUrl(string $host, string $username, string $password): string
    {
        $base = rtrim(trim($host), '/');
        return sprintf(
            '%s/get.php?username=%s&password=%s&type=m3u_plus&output=ts',
            $base,
            rawurlencode(trim($username)),
            rawurlencode($password)
        );
    }

    public static function buildXmltvUrl(string $host, string $username, string $password): string
    {
        $base = rtrim(trim($host), '/');
        return sprintf(
            '%s/xmltv.php?username=%s&password=%s',
            $base,
            rawurlencode(trim($username)),
            rawurlencode($password)
        );
    }

    /**
     * @return array{host: string, username: string, password: string, xmltv_url: string}|null
     */
    public static function parseFromM3uUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);
        $username = isset($query['username']) ? (string) $query['username'] : '';
        $password = isset($query['password']) ? (string) $query['password'] : '';

        if ($username === '' || $password === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $host = $parts['scheme'] . '://' . $parts['host'] . $port;

        return [
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'xmltv_url' => self::buildXmltvUrl($host, $username, $password),
        ];
    }
}

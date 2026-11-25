<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\EnvLoader;
use App\Repositories\SettingsRepository;

final class NetworkGuard
{
    public function verifyLanOrToken(?string $token): bool
    {
        $settings = new SettingsRepository();
        $expectedToken = (string) $settings->get('output_token', '');
        $enforceLanRaw = $settings->get('enforce_lan_ip', '1');
        $enforceLan = !in_array(strtolower(trim((string) ($enforceLanRaw ?? ''))), ['0', 'false', ''], true);

        if ($expectedToken !== '') {
            if (!is_string($token) || $token === '' || !hash_equals($expectedToken, $token)) {
                return false;
            }
        }

        if ($enforceLan) {
            $clientIp = self::resolveClientIp();
            if (!self::isIpInAllowedSubnets($clientIp)) {
                return false;
            }
        }

        return true;
    }

    private static function resolveClientIp(): string
    {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // Only trust proxy headers when REMOTE_ADDR is localhost or a Docker bridge network (172.16.0.0/12)
        if ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || self::ipInCidr($remoteAddr, '172.16.0.0/12')) {
            $forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwardedFor !== '') {
                $firstIp = trim(explode(',', $forwardedFor)[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP) !== false) {
                    return $firstIp;
                }
            }

            $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
                return $realIp;
            }
        }

        return $remoteAddr;
    }

    public static function isIpInAllowedSubnets(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1' || self::ipInCidr($ip, '127.0.0.0/8')) {
            return true;
        }

        if (str_contains($ip, ':')) {
            $lower = strtolower($ip);
            // IPv6 ULA (fc00::/7 -> fc.. or fd..) and link-local (fe80::/10 -> fe8., fe9., fea., feb.)
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')
                || str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9')
                || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                return true;
            }
        }

        $defaultSubnets = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10';
        $subnetsRaw = EnvLoader::get('LAN_SUBNET_ALLOW', $defaultSubnets);
        $subnets = array_filter(array_map('trim', explode(',', (string) $subnetsRaw)));

        foreach ($subnets as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskInt = (int) $mask;

        if ($ipLong === false || $subnetLong === false || $maskInt < 0 || $maskInt > 32) {
            return false;
        }

        $maskLong = $maskInt === 0 ? 0 : (~0 << (32 - $maskInt));
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}

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
        $enforceLan = (string) $settings->get('enforce_lan_ip', '1') === '1';

        if ($expectedToken !== '') {
            if (!is_string($token) || $token === '' || !hash_equals($expectedToken, $token)) {
                return false;
            }
        }

        if ($enforceLan) {
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            if (!self::isIpInAllowedSubnets($clientIp)) {
                return false;
            }
        }

        return true;
    }

    public static function isIpInAllowedSubnets(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        $subnetsRaw = EnvLoader::get('LAN_SUBNET_ALLOW', '192.168.0.0/16,10.0.0.0/8');
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

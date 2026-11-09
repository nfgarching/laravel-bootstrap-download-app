<?php

namespace App\Support;

/**
 * Kürzt IP-Adressen für das Protokoll: IPv4 ohne letztes Oktett, IPv6 auf /48.
 */
class IpAnonymizer
{
    public static function anonymize(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return null;
            }

            return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)) ?: null;
        }

        return null;
    }
}

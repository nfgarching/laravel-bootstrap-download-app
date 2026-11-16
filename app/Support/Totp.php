<?php

namespace App\Support;

/**
 * Einmalcodes für Authenticator-Apps nach RFC 6238 (TOTP, SHA-1, 6 Stellen, 30 Sekunden).
 *
 * Bewusst ohne Fremdbibliothek: Das Verfahren ist kurz und lässt sich gegen
 * die Testvektoren aus RFC 6238 Anhang B prüfen (tests/Unit/TotpTest.php).
 * Hängt nicht von Laravel ab.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Neues Geheimnis: 20 Byte, Base32 (32 Zeichen). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Code für einen Zeitschritt (Unix-Zeit / 30). */
    public static function code(string $secret, int $step, int $digits = self::DIGITS, string $algorithm = 'sha1'): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac($algorithm, pack('J', $step), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Prüft einen Code mit einem Zeitschritt Toleranz in beide Richtungen.
     * Gibt den passenden Zeitschritt zurück (zum Sperren gegen Wiederverwendung)
     * oder null. Codes aus $lastStep und früher werden abgelehnt.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $current = self::currentStep($time);

        foreach ([0, -1, 1] as $drift) {
            $step = $current + $drift;

            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }

            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Adresse für den QR-Code der Authenticator-App. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper((string) preg_replace('/[\s=-]/', '', $data));
        $bits = '';

        foreach (str_split($data) as $char) {
            $value = strpos(self::BASE32, $char);

            if ($value === false) {
                return '';
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}

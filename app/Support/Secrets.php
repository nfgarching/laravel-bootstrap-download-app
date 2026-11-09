<?php

namespace App\Support;

/**
 * Erzeugt Link-Token und Passwörter.
 */
class Secrets
{
    // Ohne leicht verwechselbare Zeichen (0/O, 1/I/L).
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * 256 Bit Zufall, URL-sicher kodiert (43 Zeichen).
     */
    public static function linkToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Passwort in der Form XXXX-XXXX-XXXX (rund 59 Bit).
     */
    public static function password(): string
    {
        $groups = [];
        $max = strlen(self::PASSWORD_ALPHABET) - 1;

        for ($group = 0; $group < 3; $group++) {
            $part = '';

            for ($i = 0; $i < 4; $i++) {
                $part .= self::PASSWORD_ALPHABET[random_int(0, $max)];
            }

            $groups[] = $part;
        }

        return implode('-', $groups);
    }

    /**
     * Vereinheitlicht die Eingabe: Groß-/Kleinschreibung, Leerzeichen und
     * Bindestriche spielen beim Eintippen keine Rolle.
     */
    public static function normalizePassword(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }
}

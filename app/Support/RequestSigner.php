<?php

namespace App\Support;

/**
 * Signaturverfahren der API (siehe docs/api.md).
 *
 * Signiert wird die Zeichenkette
 *
 *     METHODE \n PFAD-MIT-QUERY \n ZEITSTEMPEL \n SHA256-DES-INHALTS
 *
 * mit HMAC-SHA256 und dem geheimen Schlüssel des API-Clients. Das Ergebnis
 * steht hexadezimal im Kopf X-Signature. Das Client-Package setzt dasselbe
 * Verfahren getrennt um; die Testvektoren in docs/api.md gelten für beide.
 */
class RequestSigner
{
    public static function canonical(string $method, string $pathWithQuery, string $timestamp, string $contentSha256): string
    {
        return implode("\n", [strtoupper($method), $pathWithQuery, $timestamp, strtolower($contentSha256)]);
    }

    public static function sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $contentSha256): string
    {
        return hash_hmac('sha256', self::canonical($method, $pathWithQuery, $timestamp, $contentSha256), $secret);
    }
}

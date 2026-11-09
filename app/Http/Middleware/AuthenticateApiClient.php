<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Support\RequestSigner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft Bearer-Token, Zeitstempel und HMAC-Signatur eines API-Aufrufs.
 *
 * Verwendung in Routen: ->middleware('portal.api:shipments:write')
 * Für Datei-Uploads:    ->middleware('portal.api:shipments:write,stream')
 *
 * Im Modus "stream" wird der Inhalt nicht vorab gelesen. Die Signatur deckt
 * dann den im Kopf X-Content-SHA256 angegebenen Wert; der Controller
 * vergleicht ihn beim Verschlüsseln mit dem tatsächlichen Inhalt.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next, string $ability, string $mode = 'buffered'): Response
    {
        $token = (string) $request->bearerToken();
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = strtolower((string) $request->header('X-Signature'));
        $contentHash = strtolower((string) $request->header('X-Content-SHA256'));

        if ($token === ''
            || ! ctype_digit($timestamp)
            || ! preg_match('/^[0-9a-f]{64}$/', $signature)
            || ! preg_match('/^[0-9a-f]{64}$/', $contentHash)) {
            return $this->deny();
        }

        $client = ApiClient::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($client === null) {
            return $this->deny();
        }

        $tolerance = (int) config('portal.signature_tolerance', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->deny();
        }

        if ($mode !== 'stream' && ! hash_equals(hash('sha256', $request->getContent()), $contentHash)) {
            return $this->deny();
        }

        $expected = RequestSigner::sign(
            $client->hmac_secret,
            $request->getMethod(),
            $request->getRequestUri(),
            $timestamp,
            $contentHash,
        );

        if (! hash_equals($expected, $signature)) {
            return $this->deny();
        }

        // Jede Signatur gilt genau einmal: ein abgefangener Aufruf lässt sich
        // auch innerhalb des Zeitfensters nicht wiedereinspielen.
        if (! Cache::add('portal:signature:'.$signature, true, $tolerance * 2)) {
            return $this->deny();
        }

        if (! $client->allows($ability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_client', $client);

        return $next($request);
    }

    private function deny(): Response
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}

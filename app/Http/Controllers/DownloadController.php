<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Account\SetupController;
use App\Models\Account;
use App\Models\ShipmentRecipient;
use App\Services\DownloadService;
use App\Services\EventLogger;
use App\Support\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abruf einer Sendung über den persönlichen Link.
 *
 * Unbekannte, abgelaufene, widerrufene und gesperrte Links führen alle zur
 * selben neutralen Seite, damit sich aus der Antwort nichts ableiten lässt.
 */
class DownloadController extends Controller
{
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly EventLogger $events,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        $shipment = $recipient->shipment;

        // Angemeldet mit dem Konto dieser Adresse: direkt in den Kontobereich
        $account = Auth::user();

        if ($account instanceof Account && $account->email === $recipient->email) {
            return redirect()->route('account.shipment', ['recipient' => $recipient->uuid]);
        }

        if ($recipient->first_opened_at === null) {
            $recipient->forceFill(['first_opened_at' => now()])->save();
        }

        $this->events->log(EventLogger::LINK_OPENED, $shipment, $recipient, ip: $request->ip());

        $hasAccount = $this->accountFor($recipient) !== null;

        if ($recipient->require_login && $hasAccount) {
            $request->session()->put('url.intended', route('account.shipment', ['recipient' => $recipient->uuid]));

            return response()->view('download.login-required', [
                'sender' => config('portal.sender_name'),
            ]);
        }

        // Vor der Passworteingabe wird nur der Absender gezeigt, weder Betreff
        // noch Dateinamen: Beides kann schon vertraulich sein.
        if (! $this->isUnlocked($request, $recipient)) {
            return response()->view('download.password', [
                'token' => $token,
                'sender' => config('portal.sender_name'),
            ]);
        }

        return response()->view('download.show', [
            'token' => $token,
            'sender' => config('portal.sender_name'),
            'shipment' => $shipment,
            'files' => $this->downloads->files($recipient),
            'offerAccount' => config('portal.accounts.enabled') && ! $hasAccount,
            'hasAccount' => $hasAccount,
        ]);
    }

    public function unlock(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $recipient->hasPassword()) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        $shipment = $recipient->shipment;
        $limiterKey = 'portal:password:'.$recipient->id;

        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('portal.password.attempts_per_hour'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            return redirect()->route('download.show', ['token' => $token])
                ->with('portal_error', __('portal.too_many_attempts', ['minutes' => max(1, $minutes)]));
        }

        $input = Secrets::normalizePassword((string) $request->input('password'));

        if ($input !== '' && Hash::driver('argon2id')->check($input, $recipient->password_hash)) {
            RateLimiter::clear($limiterKey);
            $recipient->forceFill(['failed_attempts' => 0])->save();

            $request->session()->regenerate();
            $request->session()->put($this->sessionKey($recipient), true);

            $this->events->log(EventLogger::PASSWORD_OK, $shipment, $recipient, ip: $request->ip());

            return redirect()->route('download.show', ['token' => $token]);
        }

        RateLimiter::hit($limiterKey, 3600);
        $recipient->increment('failed_attempts');

        $this->events->log(EventLogger::PASSWORD_FAILED, $shipment, $recipient, ip: $request->ip());

        if ($recipient->failed_attempts >= (int) config('portal.password.lock_after')) {
            $recipient->forceFill(['locked_at' => now()])->save();

            $this->events->log(EventLogger::LINK_LOCKED, $shipment, $recipient, ip: $request->ip());

            return $this->unavailable();
        }

        return redirect()->route('download.show', ['token' => $token])
            ->with('portal_error', __('portal.wrong_password'));
    }

    /**
     * Der Download startet erst nach einem Klick (POST). Mail-Scanner und
     * Link-Vorschauen, die den Link nur öffnen, verbrauchen so keinen Abruf.
     */
    public function download(Request $request, string $token, string $file): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $recipient) || $this->loginRequired($recipient)) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        return $this->downloads->file($request, $recipient, $file, route('download.show', ['token' => $token]))
            ?? $this->unavailable();
    }

    /**
     * Alle Dateien der Sendung als ZIP (siehe DownloadService::zip).
     */
    public function downloadZip(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $recipient) || $this->loginRequired($recipient)) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        return $this->downloads->zip($request, $recipient, route('download.show', ['token' => $token]))
            ?? $this->unavailable();
    }

    /**
     * „Sicheren Zugang einrichten“ von der Abrufseite: nur mit gültigem und
     * (falls geschützt) entsperrtem Link. Die Adresse des Kontos ist die
     * Empfängeradresse dieser Sendung; sie wird anschließend per Code bestätigt.
     */
    public function startAccount(Request $request, string $token, SetupController $setup): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null || ! config('portal.accounts.enabled')) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $recipient)) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        return $setup->begin($request, $recipient->email, $recipient->name);
    }

    /**
     * Die Kanzlei kann je Empfänger verlangen, dass nur nach Anmeldung abgerufen
     * wird. Das greift erst, wenn für die Adresse ein Konto besteht – sonst
     * bleibt der Link (mit Passwort) der einzige Weg.
     */
    private function loginRequired(ShipmentRecipient $recipient): bool
    {
        return $recipient->require_login && $this->accountFor($recipient) !== null;
    }

    private function accountFor(ShipmentRecipient $recipient): ?Account
    {
        return config('portal.accounts.enabled')
            ? Account::where('email', $recipient->email)->first()
            : null;
    }

    private function resolve(string $token): ?ShipmentRecipient
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $recipient = ShipmentRecipient::with('shipment')
            ->where('token_hash', ShipmentRecipient::hashToken($token))
            ->first();

        if ($recipient === null || $recipient->isLocked() || ! $recipient->shipment->isAvailable()) {
            return null;
        }

        return $recipient;
    }

    private function isUnlocked(Request $request, ShipmentRecipient $recipient): bool
    {
        return ! $recipient->hasPassword() || $request->session()->get($this->sessionKey($recipient)) === true;
    }

    private function sessionKey(ShipmentRecipient $recipient): string
    {
        return 'portal.unlocked.'.$recipient->id;
    }

    private function unavailable(): Response
    {
        return response()->view('download.unavailable', [], 404);
    }
}

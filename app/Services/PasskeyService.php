<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Passkey;
use Illuminate\Contracts\Session\Session;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use RuntimeException;

/**
 * Passkeys (WebAuthn) über lbuchs/webauthn.
 *
 * Die Optionen gehen als JSON an public/js/passkey.js; Binärwerte sind dort
 * im Format "=?BINARY?B?…?=" kodiert und werden im Browser umgewandelt.
 * Die Challenge liegt bis zur Antwort in der Sitzung und gilt einmal.
 *
 * Attestation "none": Das Portal braucht keinen Herstellernachweis des
 * Geräts, und Browser fragen dann nicht nach einer Freigabe dafür.
 */
class PasskeyService
{
    private const SESSION_KEY = 'portal.webauthn.challenge';

    private const TIMEOUT = 120;

    public function __construct(private readonly Session $session) {}

    /**
     * Optionen für navigator.credentials.create().
     *
     * @param  list<string>  $excludeCredentialIds  Base64, bereits registrierte Passkeys
     */
    public function registrationOptions(string $handleHex, string $email, ?string $name, array $excludeCredentialIds = []): object
    {
        $webAuthn = $this->webAuthn();

        $args = $webAuthn->getCreateArgs(
            hex2bin($handleHex),
            $email,
            $name ?: $email,
            self::TIMEOUT,
            true,   // als Passkey auf dem Gerät speichern (resident key) – Anmeldung ohne E-Mail-Eingabe
            true,   // Nutzerprüfung (Fingerabdruck, Gesicht, PIN) verlangen
            null,   // Plattform- und externe Authenticatoren zulassen
            array_map(fn (string $id) => base64_decode($id), $excludeCredentialIds),
        );

        $this->remember($webAuthn->getChallenge());

        return $args;
    }

    /**
     * Prüft die Antwort des Browsers und gibt die Daten für einen neuen Passkey zurück.
     *
     * @return array{credential_id: string, public_key: string, sign_count: int}
     */
    public function verifyRegistration(string $clientDataJson, string $attestationObject): array
    {
        $challenge = $this->pullChallenge();

        try {
            $data = $this->webAuthn()->processCreate(
                $this->decode($clientDataJson),
                $this->decode($attestationObject),
                $challenge,
                true,   // Nutzerprüfung verlangen
                true,   // Anwesenheit verlangen
                false,  // keine Herstellerzertifikate prüfen (Attestation "none")
                false,
            );
        } catch (WebAuthnException $e) {
            throw new RuntimeException('Der Passkey konnte nicht geprüft werden: '.$e->getMessage(), 0, $e);
        }

        return [
            'credential_id' => base64_encode($data->credentialId),
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
        ];
    }

    /** Optionen für navigator.credentials.get() – ohne Liste: der Browser bietet gespeicherte Passkeys an. */
    public function loginOptions(): object
    {
        $webAuthn = $this->webAuthn();

        $args = $webAuthn->getGetArgs([], self::TIMEOUT, true, true, true, true, true, true);

        $this->remember($webAuthn->getChallenge());

        return $args;
    }

    /**
     * Prüft eine Anmeldung und gibt den verwendeten Passkey zurück.
     */
    public function verifyLogin(string $credentialId, string $clientDataJson, string $authenticatorData, string $signature): Passkey
    {
        $challenge = $this->pullChallenge();

        $passkey = Passkey::with('account')
            ->where('credential_id', base64_encode($this->decode($credentialId)))
            ->first();

        if ($passkey === null) {
            throw new RuntimeException('Dieser Passkey ist hier nicht bekannt.');
        }

        $webAuthn = $this->webAuthn();

        try {
            $webAuthn->processGet(
                $this->decode($clientDataJson),
                $this->decode($authenticatorData),
                $this->decode($signature),
                $passkey->public_key,
                $challenge,
                $passkey->sign_count > 0 ? $passkey->sign_count : null,
                true,
                true,
            );
        } catch (WebAuthnException $e) {
            throw new RuntimeException('Die Anmeldung mit dem Passkey ist fehlgeschlagen: '.$e->getMessage(), 0, $e);
        }

        $passkey->forceFill([
            'sign_count' => (int) ($webAuthn->getSignatureCounter() ?? $passkey->sign_count),
            'last_used_at' => now(),
        ])->save();

        return $passkey;
    }

    /** @param  array{credential_id: string, public_key: string, sign_count: int}  $data */
    public function store(Account $account, array $data, string $name): Passkey
    {
        return $account->passkeys()->create([
            ...$data,
            'name' => mb_substr(trim($name) ?: 'Passkey', 0, 100),
        ]);
    }

    private function webAuthn(): WebAuthn
    {
        $rpId = (string) config('portal.webauthn.rp_id');

        if ($rpId === '') {
            throw new RuntimeException('PORTAL_WEBAUTHN_RP_ID bzw. PORTAL_PUBLIC_URL ist nicht gesetzt.');
        }

        return new WebAuthn((string) config('portal.webauthn.rp_name'), $rpId, ['none']);
    }

    private function remember(ByteBuffer $challenge): void
    {
        $this->session->put(self::SESSION_KEY, [
            'challenge' => base64_encode($challenge->getBinaryString()),
            'expires' => time() + self::TIMEOUT + 30,
        ]);
    }

    private function pullChallenge(): ByteBuffer
    {
        $stored = $this->session->pull(self::SESSION_KEY);

        if (! is_array($stored) || ($stored['expires'] ?? 0) < time()) {
            throw new RuntimeException('Die Anfrage ist abgelaufen. Bitte noch einmal versuchen.');
        }

        return new ByteBuffer(base64_decode($stored['challenge']));
    }

    /** Werte aus dem Browser kommen Base64-kodiert (Standard oder URL-sicher). */
    private function decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $decoded = base64_decode($value.str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            throw new RuntimeException('Ungültige Antwort des Browsers.');
        }

        return $decoded;
    }
}

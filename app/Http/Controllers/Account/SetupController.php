<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AccountLogin;
use App\Services\AccountMailer;
use App\Services\PasskeyService;
use App\Support\Secrets;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Einrichtung eines Empfängerkontos.
 *
 * Ablauf: Abruf über den Link → „Sicheren Zugang einrichten“ (startet hier über
 * DownloadController::startAccount) → Code an die Empfängeradresse → Passkey
 * oder Passwort plus Authenticator-App → Wiederherstellungscodes.
 *
 * Der Zwischenstand liegt in der Sitzung unter portal.setup:
 *   email, name, handle (WebAuthn-Kennung), code_hash, code_expires,
 *   code_attempts, verified, totp_secret
 */
class SetupController extends Controller
{
    public const SESSION_KEY = 'portal.setup';

    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly AccountLogin $login,
    ) {}

    /**
     * Wird aus DownloadController::startAccount aufgerufen, nachdem der Link
     * geprüft und (falls nötig) mit Passwort entsperrt ist.
     */
    public function begin(Request $request, string $email, ?string $name): Response
    {
        if (Account::where('email', $email)->exists()) {
            return redirect()->route('account.login')
                ->with('portal_notice', __('Für diese Adresse besteht bereits ein Zugang. Bitte melden Sie sich an.'));
        }

        $request->session()->put(self::SESSION_KEY, [
            'email' => $email,
            'name' => $name,
            'handle' => bin2hex(random_bytes(16)),
            'verified' => false,
        ]);

        return $this->sendCode($request);
    }

    public function show(Request $request): Response
    {
        $setup = $this->setup($request);

        if ($setup === null) {
            return redirect()->route('home')->with('portal_error', __('Die Einrichtung ist abgelaufen. Bitte öffnen Sie den Link aus Ihrer E-Mail erneut.'));
        }

        if (! $setup['verified']) {
            return response()->view('account.setup-code', ['email' => $setup['email']]);
        }

        // Geheimnis für die Authenticator-App erst nach der Bestätigung erzeugen
        if (empty($setup['totp_secret'])) {
            $setup['totp_secret'] = Totp::generateSecret();
            $request->session()->put(self::SESSION_KEY, $setup);
        }

        $uri = Totp::uri($setup['totp_secret'], $setup['email'], (string) config('portal.sender_name'));

        return response()->view('account.setup-method', [
            'email' => $setup['email'],
            'totpSecret' => trim(chunk_split($setup['totp_secret'], 4, ' ')),
            'qrCode' => $this->qrCode($uri),
            'minLength' => (int) config('portal.accounts.password_min_length', 12),
        ]);
    }

    public function resend(Request $request): Response
    {
        if ($this->setup($request) === null) {
            return redirect()->route('home');
        }

        return $this->sendCode($request);
    }

    public function verifyCode(Request $request): Response
    {
        $setup = $this->setup($request);

        if ($setup === null) {
            return redirect()->route('home');
        }

        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';

        if (($setup['code_expires'] ?? 0) < time()) {
            return back()->with('portal_error', __('Der Code ist abgelaufen. Bitte fordern Sie einen neuen an.'));
        }

        if (($setup['code_attempts'] ?? 0) >= (int) config('portal.accounts.code_attempts', 5)) {
            return back()->with('portal_error', __('Zu viele Fehlversuche. Bitte fordern Sie einen neuen Code an.'));
        }

        if (! hash_equals((string) ($setup['code_hash'] ?? ''), hash('sha256', $code))) {
            $setup['code_attempts'] = ($setup['code_attempts'] ?? 0) + 1;
            $request->session()->put(self::SESSION_KEY, $setup);

            return back()->with('portal_error', __('Der Code ist nicht richtig.'));
        }

        $setup['verified'] = true;
        unset($setup['code_hash'], $setup['code_expires'], $setup['code_attempts']);
        $request->session()->put(self::SESSION_KEY, $setup);

        return redirect()->route('account.setup');
    }

    // ------------------------------------------------------------------ Passkey

    public function passkeyOptions(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $setup = $this->verifiedSetup($request);

        return response()->json($passkeys->registrationOptions($setup['handle'], $setup['email'], $setup['name']));
    }

    public function passkeyStore(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $setup = $this->verifiedSetup($request);

        $data = $request->validate([
            'clientDataJSON' => ['required', 'string', 'max:10000'],
            'attestationObject' => ['required', 'string', 'max:100000'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $credential = $passkeys->verifyRegistration($data['clientDataJSON'], $data['attestationObject']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('Der Passkey konnte nicht eingerichtet werden. Bitte versuchen Sie es erneut.')], 422);
        }

        try {
            $account = DB::transaction(function () use ($setup, $credential, $data, $passkeys) {
                $account = $this->createAccount($setup);
                $passkeys->store($account, $credential, $data['name'] ?? $this->deviceName($setup));

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => __('Für diese Adresse besteht bereits ein Zugang.')], 409);
        }

        return response()->json(['redirect' => $this->finish($request, $account, 'passkey')]);
    }

    // ------------------------------------------------------------------ Passwort + Authenticator-App

    public function passwordStore(Request $request): Response
    {
        $setup = $this->verifiedSetup($request);

        $request->validate([
            'password' => ['required', 'string', 'confirmed', 'min:'.(int) config('portal.accounts.password_min_length', 12), 'max:500'],
            'code' => ['required', 'string'],
        ], [
            'password.required' => __('Bitte ein Passwort eingeben.'),
            'password.confirmed' => __('Die beiden Passwörter stimmen nicht überein.'),
            'password.min' => __('Das Passwort muss mindestens :min Zeichen lang sein.'),
            'code.required' => __('Bitte den Code aus der App eingeben.'),
        ]);

        $step = Totp::verify((string) $setup['totp_secret'], (string) $request->input('code'));

        if ($step === null) {
            return back()->withErrors(['code' => __('Der Code aus der App ist nicht richtig. Bitte prüfen Sie die Uhrzeit Ihres Geräts.')]);
        }

        try {
            $account = DB::transaction(function () use ($setup, $request, $step) {
                $account = $this->createAccount($setup);
                $account->forceFill([
                    'password' => Hash::make($request->input('password')),
                    'totp_secret' => $setup['totp_secret'],
                    'totp_last_step' => $step,
                ])->save();

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('account.login')
                ->with('portal_notice', __('Für diese Adresse besteht bereits ein Zugang. Bitte melden Sie sich an.'));
        }

        return redirect($this->finish($request, $account, 'password'));
    }

    // ------------------------------------------------------------------ Hilfen

    private function sendCode(Request $request): Response
    {
        $setup = $this->setup($request);
        $key = 'portal:setup-code:'.hash('sha256', $setup['email']);

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return redirect()->route('account.setup')
                ->with('portal_error', __('Es wurden bereits mehrere Codes verschickt. Bitte warten Sie einige Minuten.'));
        }

        RateLimiter::hit($key, 3600);

        $code = Secrets::emailCode();

        $setup['code_hash'] = hash('sha256', $code);
        $setup['code_expires'] = time() + 60 * (int) config('portal.accounts.code_ttl_minutes', 15);
        $setup['code_attempts'] = 0;
        $request->session()->put(self::SESSION_KEY, $setup);

        try {
            $this->mailer->code($setup['email'], $code);
        } catch (Throwable) {
            return redirect()->route('account.setup')
                ->with('portal_error', __('Die E-Mail mit dem Code konnte nicht verschickt werden. Bitte versuchen Sie es später erneut.'));
        }

        return redirect()->route('account.setup')
            ->with('portal_notice', __('Wir haben einen Code an :email geschickt.', ['email' => $setup['email']]));
    }

    private function createAccount(array $setup): Account
    {
        return Account::create([
            'email' => $setup['email'],
            'name' => $setup['name'],
            'webauthn_handle' => $setup['handle'],
        ]);
    }

    /** Anmelden, Wiederherstellungscodes erzeugen, Einrichtung beenden. */
    private function finish(Request $request, Account $account, string $method): string
    {
        $request->session()->forget(self::SESSION_KEY);
        $this->login->login($request, $account, $method);

        $request->session()->put('portal.recovery_codes', $account->regenerateRecoveryCodes());
        $request->session()->put('portal.recovery_codes_first', true);

        return route('account.recovery-codes');
    }

    private function setup(Request $request): ?array
    {
        $setup = $request->session()->get(self::SESSION_KEY);

        return is_array($setup) && isset($setup['email']) ? $setup : null;
    }

    private function verifiedSetup(Request $request): array
    {
        $setup = $this->setup($request);

        abort_if($setup === null || ! $setup['verified'], 403);

        return $setup;
    }

    private function deviceName(array $setup): string
    {
        return __('Passkey vom :date', ['date' => now()->timezone(config('portal.display_timezone'))->format('d.m.Y')]);
    }

    /** QR-Code als SVG; die Attribute sind Präsentationsattribute, die CSP lässt sie zu. */
    private function qrCode(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($text);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}

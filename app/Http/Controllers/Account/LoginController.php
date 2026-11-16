<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AccountLogin;
use App\Services\AccountMailer;
use App\Services\PasskeyService;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anmeldung mit Passkey, mit Passwort plus Code aus der Authenticator-App
 * oder mit einem Wiederherstellungscode.
 *
 * Fehlermeldungen sagen nicht, ob es ein Konto zu einer Adresse gibt.
 */
class LoginController extends Controller
{
    public function __construct(private readonly AccountLogin $login) {}

    public function show(): Response
    {
        return response()->view('account.login');
    }

    public function passkeyOptions(PasskeyService $passkeys): JsonResponse
    {
        return response()->json($passkeys->loginOptions());
    }

    public function passkey(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:2000'],
            'clientDataJSON' => ['required', 'string', 'max:10000'],
            'authenticatorData' => ['required', 'string', 'max:10000'],
            'signature' => ['required', 'string', 'max:10000'],
        ]);

        $key = 'portal:login:passkey:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json(['message' => __('Zu viele Versuche. Bitte warten Sie einige Minuten.')], 429);
        }

        try {
            $passkey = $passkeys->verifyLogin($data['id'], $data['clientDataJSON'], $data['authenticatorData'], $data['signature']);
        } catch (Throwable $e) {
            RateLimiter::hit($key, 900);
            report($e);

            return response()->json(['message' => __('Die Anmeldung mit dem Passkey ist fehlgeschlagen.')], 422);
        }

        $this->login->login($request, $passkey->account, 'passkey');

        return response()->json(['redirect' => redirect()->intended(route('account.index'))->getTargetUrl()]);
    }

    /**
     * E-Mail, Passwort und Code (aus der App oder Wiederherstellungscode).
     */
    public function password(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:500'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $email = strtolower(trim($data['email']));
        $key = 'portal:login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, (int) config('portal.accounts.login_attempts', 5))) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->withInput($request->only('email'))
                ->with('portal_error', __('Zu viele Fehlversuche. Bitte versuchen Sie es in :minutes Minuten erneut.', ['minutes' => max(1, $minutes)]));
        }

        $account = Account::where('email', $email)->first();

        // Gleiche Rechenzeit, ob es das Konto gibt oder nicht
        $passwordOk = $account?->password !== null
            ? Hash::check($data['password'], $account->password)
            : (Hash::make($data['password']) && false);

        $method = null;

        if ($account !== null && $passwordOk && $account->hasPasswordLogin()) {
            $step = Totp::verify((string) $account->totp_secret, $data['code'], $account->totp_last_step);

            if ($step !== null) {
                $account->forceFill(['totp_last_step' => $step])->save();
                $method = 'password';
            } elseif ($account->useRecoveryCode($data['code'])) {
                $method = 'recovery';
            }
        }

        if ($method === null) {
            RateLimiter::hit($key, 900);

            return back()->withInput($request->only('email'))
                ->with('portal_error', __('Die Anmeldung ist fehlgeschlagen. Bitte prüfen Sie Ihre Angaben.'));
        }

        RateLimiter::clear($key);
        $this->login->login($request, $account, $method);

        if ($method === 'recovery') {
            try {
                app(AccountMailer::class)->securityNotice($account, __('Anmeldung mit einem Wiederherstellungscode'));
            } catch (Throwable) {
                // Hinweis-Mail ist nicht entscheidend für die Anmeldung
            }

            return redirect()->route('account.security')
                ->with('portal_notice', __('Sie haben einen Wiederherstellungscode verwendet. Es verbleiben :count.', ['count' => $account->remainingRecoveryCodes()]));
        }

        return redirect()->intended(route('account.index'));
    }

    /**
     * Anmeldung nur mit E-Mail und Wiederherstellungscode – für Konten mit
     * Passkey, deren Gerät verloren ist.
     */
    public function showRecovery(): Response
    {
        return response()->view('account.recovery');
    }

    public function recovery(Request $request, AccountMailer $mailer): Response
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $email = strtolower(trim($data['email']));
        $key = 'portal:login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, (int) config('portal.accounts.login_attempts', 5))) {
            return back()->withInput($request->only('email'))
                ->with('portal_error', __('Zu viele Fehlversuche. Bitte versuchen Sie es später erneut.'));
        }

        $account = Account::where('email', $email)->first();

        if ($account === null || ! $account->useRecoveryCode($data['code'])) {
            RateLimiter::hit($key, 900);

            return back()->withInput($request->only('email'))
                ->with('portal_error', __('Die Anmeldung ist fehlgeschlagen. Bitte prüfen Sie Ihre Angaben.'));
        }

        RateLimiter::clear($key);
        $this->login->login($request, $account, 'recovery');

        try {
            $mailer->securityNotice($account, __('Anmeldung mit einem Wiederherstellungscode'));
        } catch (Throwable) {
            // Hinweis-Mail ist nicht entscheidend für die Anmeldung
        }

        return redirect()->route('account.security')
            ->with('portal_notice', __('Sie haben einen Wiederherstellungscode verwendet. Es verbleiben :count. Richten Sie jetzt am besten einen neuen Passkey ein.', ['count' => $account->remainingRecoveryCodes()]));
    }

    public function logout(Request $request): Response
    {
        $this->login->logout($request);

        return redirect()->route('account.login')->with('portal_notice', __('Sie sind abgemeldet.'));
    }
}

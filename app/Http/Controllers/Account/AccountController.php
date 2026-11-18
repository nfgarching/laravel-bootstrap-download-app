<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ShipmentRecipient;
use App\Models\UploadRequest;
use App\Services\AccountLogin;
use App\Services\AccountMailer;
use App\Services\DownloadService;
use App\Services\EventLogger;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bereich für angemeldete Empfänger: gültige Sendungen, Abruf, Sicherheit.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly EventLogger $events,
        private readonly AccountMailer $mailer,
    ) {}

    public function index(Request $request): Response
    {
        $recipients = $this->account($request)->recipients()->latest('id')->get();

        $requests = config('portal.uploads.enabled')
            ? UploadRequest::with('apiClient')
                ->where('email', $this->account($request)->email)
                ->whereNull('closed_at')->whereNull('locked_at')
                ->where('expires_at', '>', now())
                ->latest('id')->get()
                ->filter(fn (UploadRequest $r) => filled($r->apiClient?->upload_public_key) && $r->remainingFiles() > 0)
            : collect();

        return response()->view('account.index', [
            'account' => $this->account($request),
            'recipients' => $recipients,
            'requests' => $requests,
        ]);
    }

    public function shipment(Request $request, string $recipient): Response
    {
        $recipient = $this->findRecipient($request, $recipient);

        $this->events->log(EventLogger::LINK_OPENED, $recipient->shipment, $recipient, meta: ['via' => 'account'], ip: $request->ip());

        if ($recipient->first_opened_at === null) {
            $recipient->forceFill(['first_opened_at' => now()])->save();
        }

        return response()->view('account.shipment', [
            'recipient' => $recipient,
            'shipment' => $recipient->shipment,
            'sender' => config('portal.sender_name'),
            'files' => $this->downloads->files($recipient),
        ]);
    }

    public function download(Request $request, string $recipient, string $file): Response
    {
        $recipient = $this->findRecipient($request, $recipient);
        $back = route('account.shipment', ['recipient' => $recipient->uuid]);

        return $this->downloads->file($request, $recipient, $file, $back, ['via' => 'account'])
            ?? redirect($back)->with('portal_error', __('Diese Datei ist nicht mehr verfügbar.'));
    }

    public function zip(Request $request, string $recipient): Response
    {
        $recipient = $this->findRecipient($request, $recipient);
        $back = route('account.shipment', ['recipient' => $recipient->uuid]);

        return $this->downloads->zip($request, $recipient, $back, ['via' => 'account'])
            ?? redirect($back)->with('portal_error', __('Zu dieser Sendung sind keine Dateien mehr vorhanden.'));
    }

    // ------------------------------------------------------------------ Sicherheit

    public function security(Request $request): Response
    {
        $account = $this->account($request);

        return response()->view('account.security', [
            'account' => $account,
            'passkeys' => $account->passkeys()->orderBy('id')->get(),
        ]);
    }

    public function passkeyOptions(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $account = $this->account($request);

        return response()->json($passkeys->registrationOptions(
            $account->webauthn_handle,
            $account->email,
            $account->name,
            $account->passkeys()->pluck('credential_id')->all(),
        ));
    }

    public function passkeyStore(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'clientDataJSON' => ['required', 'string', 'max:10000'],
            'attestationObject' => ['required', 'string', 'max:100000'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        if ($account->passkeys()->count() >= 10) {
            return response()->json(['message' => __('Höchstens zehn Passkeys je Zugang.')], 422);
        }

        try {
            $credential = $passkeys->verifyRegistration($data['clientDataJSON'], $data['attestationObject']);
            $passkey = $passkeys->store($account, $credential, ($data['name'] ?? null) ?: __('Passkey vom :date', ['date' => now()->timezone(config('portal.display_timezone'))->format('d.m.Y')]));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('Der Passkey konnte nicht eingerichtet werden. Bitte versuchen Sie es erneut.')], 422);
        }

        $this->notify($account, __('Neuer Passkey „:name“ hinzugefügt', ['name' => $passkey->name]));

        $request->session()->flash('portal_notice', __('Passkey hinzugefügt.'));

        return response()->json(['redirect' => route('account.security')]);
    }

    public function passkeyDestroy(Request $request, int $passkey): Response
    {
        $account = $this->account($request);
        $passkey = $account->passkeys()->findOrFail($passkey);

        // Der letzte Anmeldeweg bleibt erhalten
        if (! $account->hasPasswordLogin() && $account->passkeys()->count() <= 1) {
            return back()->with('portal_error', __('Der letzte Passkey kann nicht entfernt werden – sonst könnten Sie sich nicht mehr anmelden.'));
        }

        $passkey->delete();

        $this->notify($account, __('Passkey „:name“ entfernt', ['name' => $passkey->name]));

        return back()->with('portal_notice', __('Passkey entfernt.'));
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $account = $this->account($request);

        $request->session()->put('portal.recovery_codes', $account->regenerateRecoveryCodes());

        $this->notify($account, __('Neue Wiederherstellungscodes erzeugt; die bisherigen gelten nicht mehr'));

        return redirect()->route('account.recovery-codes');
    }

    /** Zeigt neu erzeugte Wiederherstellungscodes genau einmal. */
    public function recoveryCodes(Request $request): Response
    {
        $codes = $request->session()->pull('portal.recovery_codes');

        if (! is_array($codes)) {
            return redirect()->route('account.security');
        }

        return response()->view('account.recovery-codes', [
            'codes' => $codes,
            'first' => (bool) $request->session()->pull('portal.recovery_codes_first', false),
        ]);
    }

    public function notifications(Request $request): Response
    {
        $account = $this->account($request);
        $account->forceFill(['notify_new_shipments' => $request->boolean('notify')])->save();

        return back()->with('portal_notice', __('Einstellung gespeichert.'));
    }

    public function destroy(Request $request, AccountLogin $login): Response
    {
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => __('Bitte bestätigen Sie das Löschen.'),
        ]);

        $account = $this->account($request);
        $email = $account->email;

        $login->logout($request);
        $account->delete();

        try {
            $this->mailer->securityNotice(new Account(['email' => $email]), __('Zugang gelöscht'));
        } catch (Throwable) {
            // nicht entscheidend
        }

        return redirect()->route('home')->with('portal_notice', __('Ihr Zugang wurde gelöscht. Dokumente erhalten Sie weiterhin per Link.'));
    }

    // ------------------------------------------------------------------ Hilfen

    private function account(Request $request): Account
    {
        return $request->user();
    }

    /** Nur Sendungen an die eigene Adresse, die noch abrufbar sind. */
    private function findRecipient(Request $request, string $uuid): ShipmentRecipient
    {
        abort_unless(Str::isUuid($uuid), 404);

        return $this->account($request)->recipients()->where('uuid', $uuid)->firstOrFail();
    }

    private function notify(Account $account, string $what): void
    {
        try {
            $this->mailer->securityNotice($account, $what);
        } catch (Throwable) {
            // Hinweis-Mail ist nicht entscheidend
        }
    }
}

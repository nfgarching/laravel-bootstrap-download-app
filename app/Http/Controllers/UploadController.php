<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\UploadRequest;
use App\Services\AccountMailer;
use App\Services\EventLogger;
use App\Services\UploadService;
use App\Support\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Upload über den Link einer Upload-Anforderung (/u/{token}).
 *
 * Wie beim Abruf: unbekannte, abgelaufene, geschlossene und gesperrte Links
 * führen zur selben neutralen Seite. Angemeldete Kontoinhaber mit derselben
 * Adresse brauchen kein Passwort.
 */
class UploadController extends Controller
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly EventLogger $events,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $uploadRequest = $this->resolve($token);

        if ($uploadRequest === null) {
            return $this->unavailable();
        }

        if ($uploadRequest->first_opened_at === null) {
            $uploadRequest->forceFill(['first_opened_at' => now()])->save();
            $this->events->logUpload(EventLogger::UPLOAD_REQUEST_OPENED, $uploadRequest->api_client_id, [
                'request_id' => $uploadRequest->uuid,
            ], $request->ip());
        }

        if (! $this->isUnlocked($request, $uploadRequest)) {
            return response()->view('upload.password', [
                'action' => route('upload.unlock', ['token' => $token]),
                'sender' => config('portal.sender_name'),
            ]);
        }

        return $this->form($uploadRequest, route('upload.store', ['token' => $token]));
    }

    public function unlock(Request $request, string $token): Response
    {
        $uploadRequest = $this->resolve($token);

        if ($uploadRequest === null) {
            return $this->unavailable();
        }

        if (! $uploadRequest->hasPassword()) {
            return redirect()->route('upload.show', ['token' => $token]);
        }

        $key = 'portal:upload-password:'.$uploadRequest->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('portal.password.attempts_per_hour', 5))) {
            return redirect()->route('upload.show', ['token' => $token])
                ->with('portal_error', __('portal.too_many_attempts', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));
        }

        $input = Secrets::normalizePassword((string) $request->input('password'));

        if ($input !== '' && Hash::driver('argon2id')->check($input, $uploadRequest->password_hash)) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            $request->session()->put($this->sessionKey($uploadRequest), true);

            return redirect()->route('upload.show', ['token' => $token]);
        }

        RateLimiter::hit($key, 3600);
        $uploadRequest->increment('failed_attempts');

        if ($uploadRequest->failed_attempts >= (int) config('portal.password.lock_after', 10)) {
            $uploadRequest->forceFill(['locked_at' => now()])->save();

            return $this->unavailable();
        }

        return redirect()->route('upload.show', ['token' => $token])->with('portal_error', __('portal.wrong_password'));
    }

    public function store(Request $request, string $token, AccountMailer $mailer): Response
    {
        $uploadRequest = $this->resolve($token);

        if ($uploadRequest === null) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $uploadRequest)) {
            return redirect()->route('upload.show', ['token' => $token]);
        }

        return $this->accept($request, $uploadRequest, route('upload.show', ['token' => $token]), $mailer);
    }

    /**
     * Gemeinsam für Link und Konto: Dateien prüfen, speichern, bestätigen.
     */
    public function accept(Request $request, UploadRequest $uploadRequest, string $back, AccountMailer $mailer): Response
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'files.required' => __('Bitte wählen Sie mindestens eine Datei aus.'),
        ]);

        $files = array_values((array) $request->file('files'));
        $remaining = $uploadRequest->remainingFiles();

        if (count($files) > $remaining) {
            return redirect($back)->with('portal_error', trans_choice(
                '{0} Es können keine weiteren Dateien hochgeladen werden.|{1} Es kann nur noch eine Datei hochgeladen werden.|[2,*] Es können nur noch :count Dateien hochgeladen werden.',
                $remaining, ['count' => $remaining],
            ));
        }

        // Nur als Konto-Upload kennzeichnen, wenn das Konto zur angeforderten Adresse gehört
        $user = Auth::user();
        $account = $user instanceof Account && $user->email === $uploadRequest->email ? $user : null;

        try {
            $stored = $this->uploads->store($files, $uploadRequest->apiClient, [
                'email' => $uploadRequest->email,
                'name' => $uploadRequest->name,
                'subject' => $uploadRequest->subject,
                'message' => $request->input('message'),
            ], $uploadRequest, $account, $request->ip());
        } catch (RuntimeException $e) {
            report($e);

            return redirect($back)->with('portal_error', $e->getMessage());
        }

        try {
            $mailer->uploadReceipt($uploadRequest->email, $stored);
        } catch (Throwable) {
            // Die Bestätigung auf der Seite genügt; die Mail ist zusätzlich
        }

        return redirect($back)
            ->with('portal_receipt', $stored->map(fn ($u) => ['name' => $u->name, 'size' => $u->size, 'sha256' => $u->sha256])->all())
            ->with('portal_receipt_time', now()->timezone(config('portal.display_timezone'))->format('d.m.Y H:i'));
    }

    public function form(UploadRequest $uploadRequest, string $action): Response
    {
        return response()->view('upload.form', [
            'uploadRequest' => $uploadRequest,
            'action' => $action,
            'sender' => config('portal.sender_name'),
            'remaining' => $uploadRequest->remainingFiles(),
            'received' => $uploadRequest->uploads()->orderBy('id')->get(['name', 'size', 'created_at']),
            'maxMb' => (int) config('portal.uploads.max_file_mb'),
            'extensions' => (array) config('portal.uploads.extensions'),
        ]);
    }

    private function resolve(string $token): ?UploadRequest
    {
        if (! config('portal.uploads.enabled') || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $uploadRequest = UploadRequest::with('apiClient')->where('token_hash', hash('sha256', $token))->first();

        if ($uploadRequest === null || ! $uploadRequest->isOpen() || ! $this->uploads->acceptsUploads($uploadRequest->apiClient)) {
            return null;
        }

        return $uploadRequest;
    }

    private function isUnlocked(Request $request, UploadRequest $uploadRequest): bool
    {
        if (! $uploadRequest->hasPassword()) {
            return true;
        }

        $account = Auth::user();

        if ($account instanceof Account && $account->email === $uploadRequest->email) {
            return true;
        }

        return $request->session()->get($this->sessionKey($uploadRequest)) === true;
    }

    private function sessionKey(UploadRequest $uploadRequest): string
    {
        return 'portal.upload_unlocked.'.$uploadRequest->id;
    }

    private function unavailable(): Response
    {
        return response()->view('download.unavailable', [], 404);
    }
}

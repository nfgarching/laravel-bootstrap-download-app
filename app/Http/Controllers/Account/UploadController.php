<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UploadController as LinkUploadController;
use App\Models\Account;
use App\Models\ApiClient;
use App\Models\ShipmentRecipient;
use App\Models\Upload;
use App\Models\UploadRequest;
use App\Services\AccountMailer;
use App\Services\UploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Uploads aus dem Empfängerkonto: zu einer offenen Anforderung der Kanzlei
 * oder frei mit eigenem Betreff (mit Tageslimit).
 */
class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    // ------------------------------------------------------------------ Anforderung

    public function showRequest(Request $request, string $uuid, LinkUploadController $link): Response
    {
        return $link->form($this->findRequest($request, $uuid), route('account.request.store', ['uuid' => $uuid]));
    }

    public function storeRequest(Request $request, string $uuid, LinkUploadController $link, AccountMailer $mailer): Response
    {
        return $link->accept($request, $this->findRequest($request, $uuid), route('account.request', ['uuid' => $uuid]), $mailer);
    }

    // ------------------------------------------------------------------ Frei

    public function create(Request $request): Response
    {
        return response()->view('account.upload', [
            'available' => $this->clientFor($this->account($request)) !== null,
            'maxMb' => (int) config('portal.uploads.max_file_mb'),
            'extensions' => (array) config('portal.uploads.extensions'),
        ]);
    }

    public function store(Request $request, AccountMailer $mailer): Response
    {
        $account = $this->account($request);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('portal.uploads.max_files_per_request', 20)],
            'files.*' => ['file'],
        ], [
            'subject.required' => __('Bitte geben Sie einen Betreff an, z. B. das Aktenzeichen oder worum es geht.'),
            'files.required' => __('Bitte wählen Sie mindestens eine Datei aus.'),
            'files.max' => __('Höchstens :max Dateien auf einmal.'),
        ]);

        $client = $this->clientFor($account);

        if ($client === null) {
            return back()->with('portal_error', __('Das Hochladen ist derzeit nicht möglich. Bitte wenden Sie sich an uns.'));
        }

        $files = array_values((array) $request->file('files'));
        $size = array_sum(array_map(fn ($f) => (int) $f->getSize(), $files));
        $today = (int) Upload::where('account_id', $account->id)->where('created_at', '>=', now()->subDay())->sum('size');
        $limit = (int) config('portal.uploads.account_daily_mb', 1024) * 1024 * 1024;

        if ($today + $size > $limit) {
            return back()->withInput()->with('portal_error', __('Das Tageslimit von :mb MB ist erreicht. Bitte versuchen Sie es morgen erneut oder wenden Sie sich an uns.', ['mb' => config('portal.uploads.account_daily_mb')]));
        }

        try {
            $stored = $this->uploads->store($files, $client, [
                'email' => $account->email,
                'name' => $account->name,
                'subject' => $data['subject'],
                'message' => $data['message'] ?? null,
            ], null, $account, $request->ip());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        try {
            $mailer->uploadReceipt($account->email, $stored);
        } catch (Throwable) {
            // nicht entscheidend
        }

        try {
            $mailer->uploadNotice($stored);
        } catch (Throwable) {
            // Die Kanzlei sieht den Eingang auch über die API
        }

        return redirect()->route('account.upload')
            ->with('portal_receipt', $stored->map(fn ($u) => ['name' => $u->name, 'size' => $u->size, 'sha256' => $u->sha256])->all())
            ->with('portal_receipt_time', now()->timezone(config('portal.display_timezone'))->format('d.m.Y H:i'));
    }

    // ------------------------------------------------------------------ Hilfen

    private function account(Request $request): Account
    {
        return $request->user();
    }

    private function findRequest(Request $request, string $uuid): UploadRequest
    {
        abort_unless(Str::isUuid($uuid), 404);

        $uploadRequest = UploadRequest::with('apiClient')
            ->where('uuid', $uuid)
            ->where('email', $this->account($request)->email)
            ->firstOrFail();

        abort_unless($uploadRequest->isOpen() && $this->uploads->acceptsUploads($uploadRequest->apiClient), 404);

        return $uploadRequest;
    }

    /**
     * Freie Uploads gehen an die Anwendung, die dieser Adresse zuletzt etwas
     * geschickt oder Unterlagen angefordert hat.
     */
    private function clientFor(Account $account): ?ApiClient
    {
        $fromShipment = ShipmentRecipient::query()
            ->where('email', $account->email)
            ->whereNotNull('token_hash')
            ->latest('id')
            ->first()?->shipment?->apiClient;

        $fromRequest = UploadRequest::where('email', $account->email)->latest('id')->first()?->apiClient;

        foreach ([$fromShipment, $fromRequest] as $client) {
            if ($this->uploads->acceptsUploads($client)) {
                return $client;
            }
        }

        return null;
    }
}

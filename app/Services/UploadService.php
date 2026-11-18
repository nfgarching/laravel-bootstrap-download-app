<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ApiClient;
use App\Models\Upload;
use App\Models\UploadRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LengthException;
use RuntimeException;
use Throwable;

/**
 * Nimmt Uploads an: prüfen, verschlüsseln, Schlüssel versiegeln, protokollieren.
 *
 * Verschlüsselung: Jede Datei bekommt einen eigenen Schlüssel (secretstream wie
 * bei Sendungen). Dieser wird mit dem öffentlichen Schlüssel der internen
 * Anwendung versiegelt (sodium_crypto_box_seal) und danach verworfen. Das
 * Portal kann eingegangene Dateien damit nicht mehr entschlüsseln.
 */
class UploadService
{
    public function __construct(
        private readonly Vault $vault,
        private readonly EventLogger $events,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  array{email: string, name: ?string, subject: ?string, message: ?string}  $sender
     * @return Collection<int, Upload>
     *
     * @throws RuntimeException mit einer Meldung für die Oberfläche
     */
    public function store(array $files, ApiClient $client, array $sender, ?UploadRequest $request, ?Account $account, ?string $ip): Collection
    {
        $publicKey = $this->publicKey($client);
        $batch = (string) Str::uuid();
        $stored = collect();

        foreach ($files as $file) {
            $this->check($file);
        }

        try {
            foreach ($files as $file) {
                $stored->push($this->storeOne($file, $client, $publicKey, $batch, $sender, $request, $account));
            }
        } catch (Throwable $e) {
            // Alles oder nichts: bereits abgelegte Dateien dieses Vorgangs entfernen
            foreach ($stored as $upload) {
                $this->purge($upload, Upload::STATUS_EXPIRED);
                $upload->delete();
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Die Dateien konnten nicht gespeichert werden.', 0, $e);
        }

        foreach ($stored as $upload) {
            $this->events->logUpload(EventLogger::UPLOAD_RECEIVED, $client->id, [
                'upload_id' => $upload->uuid,
                'request_id' => $request?->uuid,
                'batch' => $batch,
                'name' => $upload->name,
                'size' => $upload->size,
                'via' => $account ? 'account' : 'link',
            ], $ip);
        }

        return $stored;
    }

    /** Prüft Endung, Inhaltstyp und Größe; wirft RuntimeException mit Meldung. */
    public function check(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();

        if (! $file->isValid()) {
            throw new RuntimeException(__('„:name“ wurde nicht vollständig übertragen.', ['name' => $name]));
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, (array) config('portal.uploads.extensions', []), true)) {
            throw new RuntimeException(__('„:name“: Dieser Dateityp ist nicht zugelassen.', ['name' => $name]));
        }

        if (in_array($file->getMimeType(), (array) config('portal.uploads.blocked_mime_types', []), true)) {
            throw new RuntimeException(__('„:name“: Dieser Dateityp ist nicht zugelassen.', ['name' => $name]));
        }

        if ($file->getSize() > $this->maxBytes()) {
            throw new RuntimeException(__('„:name“ ist größer als :mb MB.', ['name' => $name, 'mb' => config('portal.uploads.max_file_mb')]));
        }

        if ($file->getSize() === 0) {
            throw new RuntimeException(__('„:name“ ist leer.', ['name' => $name]));
        }
    }

    public function maxBytes(): int
    {
        return (int) config('portal.uploads.max_file_mb', 200) * 1024 * 1024;
    }

    /** Entfernt die verschlüsselte Datei und den versiegelten Schlüssel. */
    public function purge(Upload $upload, string $status): void
    {
        if ($upload->path) {
            $this->vault->delete($upload->path);
        }

        $upload->forceFill([
            'status' => $status,
            'path' => null,
            'sealed_key' => null,
            'purged_at' => now(),
            'fetched_at' => $status === Upload::STATUS_FETCHED ? now() : $upload->fetched_at,
        ])->save();
    }

    /** Hat die interne Anwendung schon einen Schlüssel hinterlegt? */
    public function acceptsUploads(?ApiClient $client): bool
    {
        return $client !== null && $client->revoked_at === null && filled($client->upload_public_key);
    }

    private function storeOne(UploadedFile $file, ApiClient $client, string $publicKey, string $batch, array $sender, ?UploadRequest $request, ?Account $account): Upload
    {
        $uuid = (string) Str::uuid();
        $path = 'uploads/'.$client->id.'/'.$uuid.'.bin';
        $fileKey = $this->vault->generateFileKey();

        $input = fopen($file->getRealPath(), 'rb');

        if ($input === false) {
            throw new RuntimeException(__('„:name“ konnte nicht gelesen werden.', ['name' => $file->getClientOriginalName()]));
        }

        try {
            $result = $this->vault->encryptStream($input, $path, $fileKey, $this->maxBytes());
        } catch (LengthException) {
            throw new RuntimeException(__('„:name“ ist zu groß.', ['name' => $file->getClientOriginalName()]));
        } finally {
            fclose($input);
        }

        $sealed = base64_encode(sodium_crypto_box_seal($fileKey, $publicKey));
        sodium_memzero($fileKey);

        return DB::transaction(fn () => Upload::create([
            'uuid' => $uuid,
            'api_client_id' => $client->id,
            'upload_request_id' => $request?->id,
            'account_id' => $account?->id,
            'batch' => $batch,
            'sender_email' => $sender['email'],
            'sender_name' => $sender['name'] ?? null,
            'subject' => $sender['subject'] ?? $request?->subject,
            'message' => $sender['message'] ?? null,
            'name' => $this->sanitizeFilename($file->getClientOriginalName()),
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 150) ?: 'application/octet-stream',
            'size' => $result['size'],
            'sha256' => $result['sha256'],
            'path' => $path,
            'sealed_key' => $sealed,
            'status' => Upload::STATUS_RECEIVED,
        ]));
    }

    private function publicKey(ApiClient $client): string
    {
        $key = base64_decode((string) $client->upload_public_key, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException(__('Das Hochladen ist derzeit nicht möglich. Bitte wenden Sie sich an uns.'));
        }

        return $key;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, " .\t");

        return mb_substr($name !== '' ? $name : 'Datei', 0, 200);
    }
}

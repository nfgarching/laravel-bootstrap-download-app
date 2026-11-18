<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Upload;
use App\Services\EventLogger;
use App\Services\UploadService;
use App\Services\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abholung eingegangener Dateien durch die interne Anwendung.
 *
 * Ablauf: GET /uploads → je Datei GET /uploads/{id}/content (verschlüsselt,
 * Format wie im Vault) → intern entschlüsseln und prüfen → POST /uploads/{id}/ack.
 * Erst die Quittung löscht die Datei im Portal.
 */
class UploadController extends ApiController
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly Vault $vault,
        private readonly EventLogger $events,
    ) {}

    /** Öffentlicher Schlüssel für die Versiegelung (X25519, Base64). */
    public function key(Request $request): JsonResponse
    {
        $data = $request->validate(['public_key' => ['required', 'string', 'max:100']]);
        $key = base64_decode($data['public_key'], true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return response()->json(['message' => 'Ungültiger öffentlicher Schlüssel (32 Byte, Base64).'], 422);
        }

        $client = $this->client($request);
        $client->forceFill(['upload_public_key' => $data['public_key']])->save();

        return response()->json(['data' => ['fingerprint' => substr(hash('sha256', $key), 0, 16)]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $uploads = Upload::with('uploadRequest:id,uuid,reference')
            ->where('api_client_id', $this->client($request)->id)
            ->where('status', Upload::STATUS_RECEIVED)
            ->orderBy('id')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['data' => $uploads->map(fn (Upload $u) => $this->present($u))->values()->all()]);
    }

    /** Verschlüsselter Inhalt; der Schlüssel steht versiegelt in sealed_key. */
    public function content(Request $request, string $id): Response
    {
        $upload = $this->find($request, $id);

        abort_if($upload->status !== Upload::STATUS_RECEIVED || ! $upload->path || ! $this->vault->exists($upload->path), 410);

        $path = $this->vault->absolutePath($upload->path);

        return new StreamedResponse(function () use ($path) {
            $handle = fopen($path, 'rb');
            fpassthru($handle);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Empfang quittieren: Datei und versiegelter Schlüssel werden gelöscht. */
    public function ack(Request $request, string $id): JsonResponse
    {
        $upload = $this->find($request, $id);

        if ($upload->status === Upload::STATUS_RECEIVED) {
            $this->uploads->purge($upload, Upload::STATUS_FETCHED);
            $this->events->logUpload(EventLogger::UPLOAD_FETCHED, $upload->api_client_id, ['upload_id' => $upload->uuid]);
        }

        return response()->json(['data' => $this->present($upload->fresh())]);
    }

    private function find(Request $request, string $id): Upload
    {
        abort_unless(Str::isUuid($id), 404);

        return Upload::with('uploadRequest:id,uuid,reference')
            ->where('api_client_id', $this->client($request)->id)
            ->where('uuid', $id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Upload $upload): array
    {
        return [
            'id' => $upload->uuid,
            'status' => $upload->status,
            'request_id' => $upload->uploadRequest?->uuid,
            'reference' => $upload->uploadRequest?->reference,
            'batch' => $upload->batch,
            'via' => $upload->account_id ? 'account' : 'link',
            'sender_email' => $upload->sender_email,
            'sender_name' => $upload->sender_name,
            'subject' => $upload->subject,
            'message' => $upload->message,
            'name' => $upload->name,
            'mime_type' => $upload->mime_type,
            'size' => $upload->size,
            'sha256' => $upload->sha256,
            'sealed_key' => $upload->sealed_key,
            'created_at' => $upload->created_at->toIso8601String(),
            'fetched_at' => $upload->fetched_at?->toIso8601String(),
        ];
    }
}

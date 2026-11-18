<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UploadRequest;
use App\Services\EventLogger;
use App\Support\Secrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Upload-Anforderungen: Die Kanzlei bittet eine Adresse, Unterlagen hochzuladen.
 */
class UploadRequestController extends ApiController
{
    public function __construct(private readonly EventLogger $events) {}

    public function store(Request $request): JsonResponse
    {
        $client = $this->client($request);

        if (blank($client->upload_public_key)) {
            return response()->json(['message' => 'Für diesen API-Client ist kein Schlüssel für Uploads hinterlegt (PUT /client/upload-key).'], 409);
        }

        $maxExpiry = now()->addDays((int) config('portal.defaults.max_expires_days', 90));

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.$maxExpiry->toIso8601String()],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('portal.uploads.max_files_per_request', 20)],
        ]);

        $token = Secrets::linkToken();
        $password = ($data['password'] ?? false) ? Secrets::password() : null;

        $uploadRequest = UploadRequest::create([
            'api_client_id' => $client->id,
            'reference' => $data['reference'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'] ?? null,
            'email' => strtolower($data['email']),
            'name' => $data['name'] ?? null,
            'token_hash' => hash('sha256', $token),
            'password_hash' => $password !== null ? Hash::driver('argon2id')->make(Secrets::normalizePassword($password)) : null,
            'max_files' => $data['max_files'] ?? 10,
            'expires_at' => isset($data['expires_at'])
                ? Carbon::parse($data['expires_at'])->setTimezone(config('app.timezone'))
                : now()->addDays((int) config('portal.uploads.request_expires_days', 14)),
        ]);

        $this->events->logUpload(EventLogger::UPLOAD_REQUEST_CREATED, $client->id, ['request_id' => $uploadRequest->uuid]);

        return response()->json(['data' => [
            ...$this->presentRequest($uploadRequest),
            'url' => config('portal.public_url').'/u/'.$token,
            'password' => $password,
        ]], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->presentRequest($this->find($request, $id))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $uploadRequest = $this->find($request, $id);

        if ($uploadRequest->closed_at === null) {
            $uploadRequest->forceFill(['closed_at' => now()])->save();
            $this->events->logUpload(EventLogger::UPLOAD_REQUEST_CLOSED, $uploadRequest->api_client_id, ['request_id' => $uploadRequest->uuid]);
        }

        return response()->json(['data' => $this->presentRequest($uploadRequest)]);
    }

    private function find(Request $request, string $id): UploadRequest
    {
        abort_unless(Str::isUuid($id), 404);

        return UploadRequest::where('api_client_id', $this->client($request)->id)->where('uuid', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function presentRequest(UploadRequest $uploadRequest): array
    {
        return [
            'id' => $uploadRequest->uuid,
            'status' => $uploadRequest->status(),
            'reference' => $uploadRequest->reference,
            'subject' => $uploadRequest->subject,
            'email' => $uploadRequest->email,
            'name' => $uploadRequest->name,
            'password_protected' => $uploadRequest->hasPassword(),
            'max_files' => $uploadRequest->max_files,
            'uploads' => $uploadRequest->uploads()->count(),
            'opened_at' => $uploadRequest->first_opened_at?->toIso8601String(),
            'expires_at' => $uploadRequest->expires_at->toIso8601String(),
            'created_at' => $uploadRequest->created_at->toIso8601String(),
        ];
    }
}

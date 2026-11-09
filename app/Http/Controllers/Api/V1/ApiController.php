<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class ApiController extends Controller
{
    protected function client(Request $request): ApiClient
    {
        return $request->attributes->get('api_client');
    }

    /**
     * Ein Client sieht nur seine eigenen Sendungen; fremde gelten als nicht vorhanden.
     * Bewusst ohne Route-Model-Binding: Die Suche läuft erst nach der Anmeldung.
     */
    protected function findShipment(Request $request, string $id): Shipment
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->client($request)->shipments()->where('uuid', $id)->firstOrFail();
    }

    /**
     * @param  array<string, array{url: string, password: ?string}>  $secrets  je Empfänger-UUID, nur direkt nach der Freigabe
     * @return array<string, mixed>
     */
    protected function present(Shipment $shipment, array $secrets = []): array
    {
        $shipment->loadMissing(['files', 'recipients.downloads']);

        return [
            'id' => $shipment->uuid,
            'status' => $shipment->status,
            'reference' => $shipment->reference,
            'subject' => $shipment->subject,
            'message' => $shipment->message,
            'max_downloads' => $shipment->max_downloads,
            'expires_at' => $shipment->expires_at->toIso8601String(),
            'released_at' => $shipment->released_at?->toIso8601String(),
            'created_at' => $shipment->created_at->toIso8601String(),
            'files' => $shipment->files->map(fn ($file) => [
                'id' => $file->uuid,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'sha256' => $file->sha256,
                'purged' => $file->isPurged(),
            ])->all(),
            'recipients' => $shipment->recipients->map(function ($recipient) use ($secrets) {
                $data = [
                    'id' => $recipient->uuid,
                    'email' => $recipient->email,
                    'name' => $recipient->name,
                    'password_protected' => $recipient->wants_password,
                    'require_login' => $recipient->require_login,
                    'opened_at' => $recipient->first_opened_at?->toIso8601String(),
                    'locked' => $recipient->isLocked(),
                    'failed_attempts' => $recipient->failed_attempts,
                    'downloads' => (int) $recipient->downloads->sum('download_count'),
                ];

                if (isset($secrets[$recipient->uuid])) {
                    $data['url'] = $secrets[$recipient->uuid]['url'];
                    $data['password'] = $secrets[$recipient->uuid]['password'];
                }

                return $data;
            })->all(),
        ];
    }
}

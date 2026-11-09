<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Shipment;
use App\Models\ShipmentRecipient;
use App\Services\EventLogger;
use App\Services\ShipmentPurger;
use App\Support\Secrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ShipmentController extends ApiController
{
    public function __construct(
        private readonly EventLogger $events,
        private readonly ShipmentPurger $purger,
    ) {}

    /**
     * Sendung als Entwurf anlegen.
     */
    public function store(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 100) {
            return response()->json(['message' => 'Idempotency-Key ist zu lang.'], 422);
        }

        // Wiederholung nach Zeitüberschreitung: dieselbe Sendung zurückgeben statt einer zweiten.
        if ($idempotencyKey !== null) {
            $existing = $client->shipments()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return response()->json(['data' => $this->present($existing)]);
            }
        }

        $maxExpiry = now()->addDays((int) config('portal.defaults.max_expires_days'));

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.$maxExpiry->toIso8601String()],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:100'],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.password' => ['nullable', 'boolean'],
            'recipients.*.require_login' => ['nullable', 'boolean'],
        ]);

        $shipment = DB::transaction(function () use ($client, $data, $idempotencyKey) {
            $shipment = $client->shipments()->create([
                'reference' => $data['reference'] ?? null,
                'subject' => $data['subject'],
                'message' => $data['message'] ?? null,
                'status' => Shipment::STATUS_DRAFT,
                'max_downloads' => $data['max_downloads'] ?? (int) config('portal.defaults.max_downloads'),
                'expires_at' => isset($data['expires_at'])
                    ? $this->parseDate($data['expires_at'])
                    : now()->addDays((int) config('portal.defaults.expires_days')),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($data['recipients'] as $recipient) {
                $shipment->recipients()->create([
                    'email' => strtolower($recipient['email']),
                    'name' => $recipient['name'] ?? null,
                    'wants_password' => (bool) ($recipient['password'] ?? false),
                    'require_login' => (bool) ($recipient['require_login'] ?? false),
                ]);
            }

            $this->events->log(EventLogger::SHIPMENT_CREATED, $shipment);

            return $shipment;
        });

        return response()->json(['data' => $this->present($shipment)], 201);
    }

    public function show(Request $request, string $shipment): JsonResponse
    {
        $shipment = $this->findShipment($request, $shipment);

        return response()->json(['data' => $this->present($shipment)]);
    }

    /**
     * Ablaufdatum oder Abruflimit ändern, gesperrte Empfänger wieder freigeben.
     */
    public function update(Request $request, string $shipment): JsonResponse
    {
        $shipment = $this->findShipment($request, $shipment);

        if (! in_array($shipment->status, [Shipment::STATUS_DRAFT, Shipment::STATUS_RELEASED], true)) {
            return response()->json(['message' => 'Die Sendung ist widerrufen oder abgelaufen.'], 409);
        }

        $maxExpiry = now()->addDays((int) config('portal.defaults.max_expires_days'));

        $data = $request->validate([
            'expires_at' => ['sometimes', 'date', 'after:now', 'before_or_equal:'.$maxExpiry->toIso8601String()],
            'max_downloads' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unlock_recipients' => ['sometimes', 'array'],
            'unlock_recipients.*' => ['uuid'],
        ]);

        DB::transaction(function () use ($shipment, $data) {
            $changes = [];

            if (isset($data['expires_at'])) {
                $shipment->expires_at = $this->parseDate($data['expires_at']);
                $changes['expires_at'] = $shipment->expires_at->toIso8601String();
            }

            if (isset($data['max_downloads'])) {
                $shipment->max_downloads = $data['max_downloads'];
                $changes['max_downloads'] = $shipment->max_downloads;
            }

            $shipment->save();

            if (! empty($data['unlock_recipients'])) {
                $shipment->recipients()
                    ->whereIn('uuid', $data['unlock_recipients'])
                    ->update(['locked_at' => null, 'failed_attempts' => 0]);

                $changes['unlocked'] = $data['unlock_recipients'];
            }

            if ($changes !== []) {
                $this->events->log(EventLogger::SHIPMENT_UPDATED, $shipment, meta: $changes);
            }
        });

        return response()->json(['data' => $this->present($shipment->fresh())]);
    }

    /**
     * Sendung freigeben. Die Antwort enthält je Empfänger Link und Passwort –
     * nur dieses eine Mal; gespeichert werden ausschließlich Hashwerte.
     */
    public function release(Request $request, string $shipment): JsonResponse
    {
        $shipment = $this->findShipment($request, $shipment);

        if (! $shipment->isDraft()) {
            return response()->json(['message' => 'Die Sendung ist bereits freigegeben oder beendet.'], 409);
        }

        if ($shipment->expires_at->isPast()) {
            return response()->json(['message' => 'Das Ablaufdatum liegt in der Vergangenheit.'], 409);
        }

        if (! $shipment->files()->exists()) {
            return response()->json(['message' => 'Die Sendung enthält keine Datei.'], 422);
        }

        $secrets = DB::transaction(function () use ($shipment) {
            $secrets = [];

            /** @var ShipmentRecipient $recipient */
            foreach ($shipment->recipients()->get() as $recipient) {
                $token = Secrets::linkToken();
                $password = $recipient->wants_password ? Secrets::password() : null;

                $recipient->forceFill([
                    'token_hash' => ShipmentRecipient::hashToken($token),
                    'password_hash' => $password !== null
                        ? Hash::driver('argon2id')->make(Secrets::normalizePassword($password))
                        : null,
                ])->save();

                $secrets[$recipient->uuid] = [
                    'url' => config('portal.public_url').'/d/'.$token,
                    'password' => $password,
                ];
            }

            $shipment->forceFill([
                'status' => Shipment::STATUS_RELEASED,
                'released_at' => now(),
            ])->save();

            $this->events->log(EventLogger::SHIPMENT_RELEASED, $shipment);

            return $secrets;
        });

        return response()->json(['data' => $this->present($shipment->fresh(), $secrets)]);
    }

    /**
     * Zeitangaben mit Zeitzone (z. B. +01:00) in die Zeitzone der Anwendung
     * umrechnen, bevor sie gespeichert werden.
     */
    private function parseDate(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * Sendung widerrufen: Links werden ungültig, Dateien sofort gelöscht.
     */
    public function destroy(Request $request, string $shipment): JsonResponse
    {
        $shipment = $this->findShipment($request, $shipment);

        if ($shipment->status !== Shipment::STATUS_REVOKED) {
            $this->purger->purge($shipment, Shipment::STATUS_REVOKED);
            $this->events->log(EventLogger::SHIPMENT_REVOKED, $shipment);
        }

        return response()->json(['data' => $this->present($shipment->fresh())]);
    }
}

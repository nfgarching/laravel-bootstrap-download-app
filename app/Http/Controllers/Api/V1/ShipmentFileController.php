<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ShipmentFile;
use App\Services\EventLogger;
use App\Services\ShipmentPurger;
use App\Services\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LengthException;

class ShipmentFileController extends ApiController
{
    public function __construct(
        private readonly Vault $vault,
        private readonly EventLogger $events,
    ) {}

    /**
     * Eine Datei zur Sendung hochladen.
     *
     * Der Inhalt kommt roh im Anfragekörper (Content-Type: application/octet-stream),
     * nicht als Formular. So lässt er sich ohne Zwischenspeichern im Datenstrom
     * verschlüsseln, und X-Content-SHA256 ist zugleich die Prüfsumme der Datei.
     *
     * Kopfzeilen: X-Filename (URL-kodiert), X-Mime-Type (optional).
     */
    public function store(Request $request, string $shipment): JsonResponse
    {
        $shipment = $this->findShipment($request, $shipment);

        if (! $shipment->isDraft()) {
            return response()->json(['message' => 'Dateien lassen sich nur zu einem Entwurf hinzufügen.'], 409);
        }

        $name = $this->sanitizeFilename(rawurldecode((string) $request->header('X-Filename')));

        if ($name === '') {
            return response()->json(['message' => 'Kopfzeile X-Filename fehlt.'], 422);
        }

        $maxBytes = (int) config('portal.max_file_size');
        $declaredLength = $request->header('Content-Length');

        if ($declaredLength !== null && (int) $declaredLength > $maxBytes) {
            return response()->json(['message' => 'Die Datei ist zu groß.'], 413);
        }

        $mimeType = (string) $request->header('X-Mime-Type', 'application/octet-stream');

        if (! preg_match('#^[\w.+-]+/[\w.+-]+$#', $mimeType) || strlen($mimeType) > 150) {
            $mimeType = 'application/octet-stream';
        }

        $uuid = (string) Str::uuid();
        $path = ShipmentPurger::directory($shipment).'/'.$uuid.'.bin';
        $fileKey = $this->vault->generateFileKey();

        try {
            $result = $this->vault->encryptStream($request->getContent(true), $path, $fileKey, $maxBytes);
        } catch (LengthException) {
            return response()->json(['message' => 'Die Datei ist zu groß.'], 413);
        }

        $expectedHash = strtolower((string) $request->header('X-Content-SHA256'));

        if (! hash_equals($expectedHash, $result['sha256'])) {
            $this->vault->delete($path);

            return response()->json(['message' => 'Die Prüfsumme stimmt nicht mit dem Inhalt überein.'], 422);
        }

        $file = ShipmentFile::create([
            'uuid' => $uuid,
            'shipment_id' => $shipment->id,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $result['size'],
            'sha256' => $result['sha256'],
            'path' => $path,
            'wrapped_key' => $this->vault->wrapKey($fileKey),
        ]);

        sodium_memzero($fileKey);

        $this->events->log(EventLogger::FILE_ADDED, $shipment, file: $file, meta: ['name' => $name, 'size' => $file->size]);

        return response()->json([
            'data' => [
                'id' => $file->uuid,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'sha256' => $file->sha256,
            ],
        ], 201);
    }

    /**
     * Nur der Dateiname ohne Pfad und ohne Steuerzeichen, höchstens 200 Zeichen.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, " .\t");

        return mb_substr($name, 0, 200);
    }
}

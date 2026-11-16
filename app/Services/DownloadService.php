<?php

namespace App\Services;

use App\Models\FileDownload;
use App\Models\ShipmentFile;
use App\Models\ShipmentRecipient;
use App\Support\ChunkStream;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OverflowException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Abruf von Dateien einer Sendung – gemeinsam für den Link (DownloadController)
 * und das Empfängerkonto (AccountController). Die Prüfung, ob der Abrufende
 * berechtigt ist, liegt beim Aufrufer.
 *
 * $back: Seite, auf die bei erreichtem Limit mit Fehlermeldung zurückgeleitet wird.
 * $meta: zusätzliche Angaben für das Protokoll, z. B. ['via' => 'account'].
 */
class DownloadService
{
    public function __construct(
        private readonly Vault $vault,
        private readonly EventLogger $events,
    ) {}

    /**
     * Dateien mit verbleibenden Abrufen für die Anzeige.
     *
     * @return Collection<int, array{id: string, name: string, size: int, remaining: int}>
     */
    public function files(ShipmentRecipient $recipient): Collection
    {
        $shipment = $recipient->shipment;
        $counts = $recipient->downloads()->pluck('download_count', 'shipment_file_id');

        return $shipment->files()->whereNull('purged_at')->orderBy('id')->get()->map(fn ($file) => [
            'id' => $file->uuid,
            'name' => $file->name,
            'size' => $file->size,
            'remaining' => max(0, $shipment->max_downloads - (int) ($counts[$file->id] ?? 0)),
        ]);
    }

    /**
     * Eine Datei herunterladen. null = Datei nicht verfügbar (neutrale Fehlerseite).
     */
    public function file(Request $request, ShipmentRecipient $recipient, string $file, string $back, array $meta = []): ?Response
    {
        $shipment = $recipient->shipment;

        $shipmentFile = $shipment->files()->where('uuid', $file)->whereNull('purged_at')->first();

        if ($shipmentFile === null || $shipmentFile->isPurged() || ! $this->vault->exists($shipmentFile->path)) {
            return null;
        }

        try {
            $fileKey = $this->vault->unwrapKey($shipmentFile->wrapped_key);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $download = FileDownload::firstOrCreate([
            'shipment_recipient_id' => $recipient->id,
            'shipment_file_id' => $shipmentFile->id,
        ]);

        // Zählen und Limit prüfen in einem Schritt, damit gleichzeitige
        // Anfragen das Limit nicht überschreiten.
        $counted = FileDownload::query()
            ->whereKey($download->id)
            ->where('download_count', '<', $shipment->max_downloads)
            ->increment('download_count', 1, ['last_downloaded_at' => now()]);

        if ($counted === 0) {
            $this->events->log(EventLogger::DOWNLOAD_LIMIT_REACHED, $shipment, $recipient, $shipmentFile, meta: $meta, ip: $request->ip());

            return redirect($back)->with('portal_error', __('portal.limit_reached'));
        }

        $this->events->log(EventLogger::FILE_DOWNLOADED, $shipment, $recipient, $shipmentFile, meta: $meta, ip: $request->ip());

        $vault = $this->vault;
        $path = $shipmentFile->path;

        return response()->streamDownload(
            function () use ($vault, $path, $fileKey) {
                $vault->decryptStream($path, $fileKey, function (string $chunk) {
                    echo $chunk;
                    flush();
                });
            },
            $shipmentFile->name,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $shipmentFile->size,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * Alle Dateien der Sendung als ZIP. Die Dateien werden direkt aus dem Vault
     * entschlüsselt und unkomprimiert in das ZIP gestreamt; Klartext landet
     * dabei nie auf der Platte. Zählt als ein Abruf je enthaltener Datei und
     * gelingt nur, wenn bei allen Dateien noch ein Abruf möglich ist.
     * null = nichts verfügbar.
     */
    public function zip(Request $request, ShipmentRecipient $recipient, string $back, array $meta = []): ?Response
    {
        $shipment = $recipient->shipment;

        $files = $shipment->files()->whereNull('purged_at')->orderBy('id')->get()
            ->filter(fn (ShipmentFile $file) => $this->vault->exists($file->path))
            ->values();

        if ($files->isEmpty()) {
            return null;
        }

        try {
            $keys = $files->mapWithKeys(fn (ShipmentFile $file) => [$file->id => $this->vault->unwrapKey($file->wrapped_key)]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        // Alle Zähler in einer Transaktion: entweder für jede Datei ein Abruf oder für keine.
        try {
            DB::transaction(function () use ($files, $recipient, $shipment) {
                foreach ($files as $file) {
                    $download = FileDownload::firstOrCreate([
                        'shipment_recipient_id' => $recipient->id,
                        'shipment_file_id' => $file->id,
                    ]);

                    $counted = FileDownload::query()
                        ->whereKey($download->id)
                        ->where('download_count', '<', $shipment->max_downloads)
                        ->increment('download_count', 1, ['last_downloaded_at' => now()]);

                    if ($counted === 0) {
                        throw new OverflowException;
                    }
                }
            });
        } catch (OverflowException) {
            $this->events->log(EventLogger::DOWNLOAD_LIMIT_REACHED, $shipment, $recipient, meta: ['zip' => true, ...$meta], ip: $request->ip());

            return redirect($back)->with('portal_error', __('portal.limit_reached_zip'));
        }

        foreach ($files as $file) {
            $this->events->log(EventLogger::FILE_DOWNLOADED, $shipment, $recipient, $file, meta: ['zip' => true, ...$meta], ip: $request->ip());
        }

        $vault = $this->vault;
        $names = $this->uniqueNames($files);

        // ZIP64 nur, wenn das Archiv die 4-GB-Grenze erreichen kann: Ältere
        // Entpacker (z. B. Info-ZIP ohne ZIP64) lehnen sonst jedes Archiv ab.
        $zip64 = $files->sum('size') + $files->count() * 1024 >= 0xFFFFFFFF;

        return response()->streamDownload(
            function () use ($vault, $files, $keys, $names, $zip64) {
                $zip = new ZipStream(
                    outputStream: fopen('php://output', 'wb'),
                    defaultCompressionMethod: CompressionMethod::STORE,
                    enableZip64: $zip64,
                    sendHttpHeaders: false,
                    flushOutput: true,
                );

                foreach ($files as $index => $file) {
                    $zip->addFileFromPsr7Stream(
                        fileName: $names[$index],
                        stream: new ChunkStream($vault->decryptChunks($file->path, $keys[$file->id]), $file->size),
                        lastModificationDateTime: $file->created_at,
                        exactSize: $file->size,
                    );
                }

                $zip->finish();
            },
            $this->zipName($shipment->subject),
            [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * Gleichnamige Dateien bekommen im ZIP einen Zähler: "Brief.pdf", "Brief (2).pdf".
     *
     * @param  Collection<int, ShipmentFile>  $files
     * @return list<string>
     */
    private function uniqueNames(Collection $files): array
    {
        $seen = [];
        $names = [];

        foreach ($files as $file) {
            $name = $file->name;
            $key = mb_strtolower($name);
            $number = 1;

            while (isset($seen[$key])) {
                $number++;
                $info = pathinfo($file->name);
                $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
                $name = $info['filename']." ({$number})".$extension;
                $key = mb_strtolower($name);
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    private function zipName(string $subject): string
    {
        $name = trim((string) preg_replace('/[^\pL\pN ._-]+/u', '', $subject));
        $name = mb_substr($name, 0, 80);

        return ($name === '' ? 'Dokumente' : $name).'.zip';
    }
}

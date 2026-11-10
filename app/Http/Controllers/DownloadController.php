<?php

namespace App\Http\Controllers;

use App\Models\FileDownload;
use App\Models\ShipmentFile;
use App\Models\ShipmentRecipient;
use App\Services\EventLogger;
use App\Services\Vault;
use App\Support\ChunkStream;
use App\Support\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use OverflowException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Abruf einer Sendung über den persönlichen Link.
 *
 * Unbekannte, abgelaufene, widerrufene und gesperrte Links führen alle zur
 * selben neutralen Seite, damit sich aus der Antwort nichts ableiten lässt.
 */
class DownloadController extends Controller
{
    public function __construct(
        private readonly Vault $vault,
        private readonly EventLogger $events,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        $shipment = $recipient->shipment;

        if ($recipient->first_opened_at === null) {
            $recipient->forceFill(['first_opened_at' => now()])->save();
        }

        $this->events->log(EventLogger::LINK_OPENED, $shipment, $recipient, ip: $request->ip());

        // Vor der Passworteingabe wird nur der Absender gezeigt, weder Betreff
        // noch Dateinamen: Beides kann schon vertraulich sein.
        if (! $this->isUnlocked($request, $recipient)) {
            return response()->view('download.password', [
                'token' => $token,
                'sender' => config('portal.sender_name'),
            ]);
        }

        $counts = $recipient->downloads()->pluck('download_count', 'shipment_file_id');

        $files = $shipment->files()->whereNull('purged_at')->orderBy('id')->get()->map(fn ($file) => [
            'id' => $file->uuid,
            'name' => $file->name,
            'size' => $file->size,
            'remaining' => max(0, $shipment->max_downloads - (int) ($counts[$file->id] ?? 0)),
        ]);

        return response()->view('download.show', [
            'token' => $token,
            'sender' => config('portal.sender_name'),
            'shipment' => $shipment,
            'files' => $files,
        ]);
    }

    public function unlock(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $recipient->hasPassword()) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        $shipment = $recipient->shipment;
        $limiterKey = 'portal:password:'.$recipient->id;

        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('portal.password.attempts_per_hour'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            return redirect()->route('download.show', ['token' => $token])
                ->with('portal_error', __('portal.too_many_attempts', ['minutes' => max(1, $minutes)]));
        }

        $input = Secrets::normalizePassword((string) $request->input('password'));

        if ($input !== '' && Hash::driver('argon2id')->check($input, $recipient->password_hash)) {
            RateLimiter::clear($limiterKey);
            $recipient->forceFill(['failed_attempts' => 0])->save();

            $request->session()->regenerate();
            $request->session()->put($this->sessionKey($recipient), true);

            $this->events->log(EventLogger::PASSWORD_OK, $shipment, $recipient, ip: $request->ip());

            return redirect()->route('download.show', ['token' => $token]);
        }

        RateLimiter::hit($limiterKey, 3600);
        $recipient->increment('failed_attempts');

        $this->events->log(EventLogger::PASSWORD_FAILED, $shipment, $recipient, ip: $request->ip());

        if ($recipient->failed_attempts >= (int) config('portal.password.lock_after')) {
            $recipient->forceFill(['locked_at' => now()])->save();

            $this->events->log(EventLogger::LINK_LOCKED, $shipment, $recipient, ip: $request->ip());

            return $this->unavailable();
        }

        return redirect()->route('download.show', ['token' => $token])
            ->with('portal_error', __('portal.wrong_password'));
    }

    /**
     * Der Download startet erst nach einem Klick (POST). Mail-Scanner und
     * Link-Vorschauen, die den Link nur öffnen, verbrauchen so keinen Abruf.
     */
    public function download(Request $request, string $token, string $file): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $recipient)) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        $shipment = $recipient->shipment;

        $shipmentFile = $shipment->files()->where('uuid', $file)->whereNull('purged_at')->first();

        if ($shipmentFile === null || $shipmentFile->isPurged() || ! $this->vault->exists($shipmentFile->path)) {
            return $this->unavailable();
        }

        try {
            $fileKey = $this->vault->unwrapKey($shipmentFile->wrapped_key);
        } catch (Throwable $exception) {
            report($exception);

            return $this->unavailable();
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
            $this->events->log(EventLogger::DOWNLOAD_LIMIT_REACHED, $shipment, $recipient, $shipmentFile, ip: $request->ip());

            return redirect()->route('download.show', ['token' => $token])
                ->with('portal_error', __('portal.limit_reached'));
        }

        $this->events->log(EventLogger::FILE_DOWNLOADED, $shipment, $recipient, $shipmentFile, ip: $request->ip());

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
     */
    public function downloadZip(Request $request, string $token): Response
    {
        $recipient = $this->resolve($token);

        if ($recipient === null) {
            return $this->unavailable();
        }

        if (! $this->isUnlocked($request, $recipient)) {
            return redirect()->route('download.show', ['token' => $token]);
        }

        $shipment = $recipient->shipment;

        $files = $shipment->files()->whereNull('purged_at')->orderBy('id')->get()
            ->filter(fn (ShipmentFile $file) => $this->vault->exists($file->path))
            ->values();

        if ($files->isEmpty()) {
            return $this->unavailable();
        }

        try {
            $keys = $files->mapWithKeys(fn (ShipmentFile $file) => [$file->id => $this->vault->unwrapKey($file->wrapped_key)]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->unavailable();
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
            $this->events->log(EventLogger::DOWNLOAD_LIMIT_REACHED, $shipment, $recipient, meta: ['zip' => true], ip: $request->ip());

            return redirect()->route('download.show', ['token' => $token])
                ->with('portal_error', __('portal.limit_reached_zip'));
        }

        foreach ($files as $file) {
            $this->events->log(EventLogger::FILE_DOWNLOADED, $shipment, $recipient, $file, meta: ['zip' => true], ip: $request->ip());
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

    private function resolve(string $token): ?ShipmentRecipient
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $recipient = ShipmentRecipient::with('shipment')
            ->where('token_hash', ShipmentRecipient::hashToken($token))
            ->first();

        if ($recipient === null || $recipient->isLocked() || ! $recipient->shipment->isAvailable()) {
            return null;
        }

        return $recipient;
    }

    private function isUnlocked(Request $request, ShipmentRecipient $recipient): bool
    {
        return ! $recipient->hasPassword() || $request->session()->get($this->sessionKey($recipient)) === true;
    }

    private function sessionKey(ShipmentRecipient $recipient): string
    {
        return 'portal.unlocked.'.$recipient->id;
    }

    private function unavailable(): Response
    {
        return response()->view('download.unavailable', [], 404);
    }
}

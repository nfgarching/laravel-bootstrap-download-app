<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Event;
use App\Models\Shipment;
use App\Models\Upload;
use App\Models\UploadRequest;
use App\Services\AccountMailer;
use App\Services\EventLogger;
use App\Services\ShipmentPurger;
use App\Services\UploadService;
use Illuminate\Console\Command;
use Throwable;

class PrunePortal extends Command
{
    protected $signature = 'portal:prune';

    protected $description = 'Löscht abgelaufene Sendungen, liegengebliebene Entwürfe und alte Protokolleinträge';

    public function handle(ShipmentPurger $purger, EventLogger $events, AccountMailer $mailer, UploadService $uploads): int
    {
        // 1. Freigegebene Sendungen, deren Frist abgelaufen ist: Dateien und Schlüssel löschen.
        $expired = 0;

        Shipment::query()
            ->where('status', Shipment::STATUS_RELEASED)
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($shipments) use ($purger, $events, &$expired) {
                foreach ($shipments as $shipment) {
                    $purger->purge($shipment, Shipment::STATUS_EXPIRED);
                    $events->log(EventLogger::SHIPMENT_EXPIRED, $shipment);
                    $expired++;
                }
            });

        // 2. Entwürfe, die nie freigegeben wurden (abgebrochene Übergaben).
        $drafts = 0;

        Shipment::query()
            ->where('status', Shipment::STATUS_DRAFT)
            ->where('created_at', '<=', now()->subHours((int) config('portal.draft_ttl_hours')))
            ->chunkById(100, function ($shipments) use ($purger, &$drafts) {
                foreach ($shipments as $shipment) {
                    $purger->purge($shipment, Shipment::STATUS_EXPIRED);
                    $shipment->delete();
                    $drafts++;
                }
            });

        // 3. Metadaten beendeter Sendungen und Protokoll nach Ablauf der Aufbewahrungsfrist.
        $threshold = now()->subDays((int) config('portal.retention_days'));

        $removed = Shipment::query()
            ->whereIn('status', [Shipment::STATUS_EXPIRED, Shipment::STATUS_REVOKED])
            ->where('purged_at', '<=', $threshold)
            ->delete();

        $oldEvents = Event::query()->where('created_at', '<=', $threshold)->delete();

        // 4. Rückkanal: nicht abgeholte Uploads nach Frist löschen, alte Einträge entfernen
        [$expiredUploads, $removedUploads] = $this->pruneUploads($uploads, $events);

        // 5. Zugänge ohne Anmeldung: erst Hinweis per E-Mail, nach der Frist löschen.
        [$noticed, $deletedAccounts] = $this->pruneAccounts($mailer);

        $this->info("Abgelaufen: {$expired}, Entwürfe gelöscht: {$drafts}, Sendungen entfernt: {$removed}, Protokolleinträge entfernt: {$oldEvents}, Zugänge angeschrieben: {$noticed}, Zugänge gelöscht: {$deletedAccounts}, Uploads verfallen: {$expiredUploads}, Uploads entfernt: {$removedUploads}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} angeschrieben, gelöscht
     */
    private function pruneAccounts(AccountMailer $mailer): array
    {
        if (! config('portal.accounts.enabled')) {
            return [0, 0];
        }

        $months = (int) config('portal.accounts.inactive_months', 24);
        $days = (int) config('portal.accounts.inactive_notice_days', 28);
        $inactiveSince = now()->subMonths($months);
        $noticed = 0;

        $candidates = Account::query()
            ->whereNull('inactivity_notice_at')
            ->where(fn ($q) => $q
                ->where('last_login_at', '<=', $inactiveSince)
                ->orWhere(fn ($q) => $q->whereNull('last_login_at')->where('created_at', '<=', $inactiveSince)))
            ->get();

        foreach ($candidates as $account) {
            try {
                $mailer->inactivityNotice($account, $days);
                $account->forceFill(['inactivity_notice_at' => now()])->save();
                $noticed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $deleted = Account::query()
            ->whereNotNull('inactivity_notice_at')
            ->where('inactivity_notice_at', '<=', now()->subDays($days))
            ->delete();

        return [$noticed, $deleted];
    }

    /**
     * @return array{0: int, 1: int} verfallen, entfernt
     */
    private function pruneUploads(UploadService $uploads, EventLogger $events): array
    {
        $expired = 0;

        Upload::query()
            ->where('status', Upload::STATUS_RECEIVED)
            ->where('created_at', '<=', now()->subDays((int) config('portal.uploads.keep_days', 30)))
            ->chunkById(100, function ($items) use ($uploads, $events, &$expired) {
                foreach ($items as $upload) {
                    $uploads->purge($upload, Upload::STATUS_EXPIRED);
                    $events->logUpload(EventLogger::UPLOAD_EXPIRED, $upload->api_client_id, ['upload_id' => $upload->uuid]);
                    $expired++;
                }
            });

        $threshold = now()->subDays((int) config('portal.retention_days', 90));

        $removed = Upload::query()
            ->whereIn('status', [Upload::STATUS_FETCHED, Upload::STATUS_EXPIRED])
            ->where('purged_at', '<=', $threshold)
            ->delete();

        UploadRequest::query()
            ->where('expires_at', '<=', $threshold)
            ->whereDoesntHave('uploads', fn ($q) => $q->where('status', Upload::STATUS_RECEIVED))
            ->delete();

        return [$expired, $removed];
    }
}

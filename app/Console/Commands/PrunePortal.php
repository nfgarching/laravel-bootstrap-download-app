<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Shipment;
use App\Services\EventLogger;
use App\Services\ShipmentPurger;
use Illuminate\Console\Command;

class PrunePortal extends Command
{
    protected $signature = 'portal:prune';

    protected $description = 'Löscht abgelaufene Sendungen, liegengebliebene Entwürfe und alte Protokolleinträge';

    public function handle(ShipmentPurger $purger, EventLogger $events): int
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

        $this->info("Abgelaufen: {$expired}, Entwürfe gelöscht: {$drafts}, Sendungen entfernt: {$removed}, Protokolleinträge entfernt: {$oldEvents}");

        return self::SUCCESS;
    }
}

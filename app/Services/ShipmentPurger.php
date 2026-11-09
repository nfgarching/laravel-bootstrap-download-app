<?php

namespace App\Services;

use App\Models\Shipment;

/**
 * Entfernt die Dateien einer Sendung samt Schlüsseln. Die Metadaten bleiben,
 * bis das Protokoll abgeholt und die Aufbewahrungsfrist abgelaufen ist.
 */
class ShipmentPurger
{
    public function __construct(private readonly Vault $vault) {}

    public function purge(Shipment $shipment, string $status): void
    {
        $this->vault->deleteDirectory(self::directory($shipment));

        $shipment->files()->update(['wrapped_key' => null, 'purged_at' => now()]);

        $shipment->forceFill(['status' => $status, 'purged_at' => now()])->save();
    }

    public static function directory(Shipment $shipment): string
    {
        return 'shipments/'.$shipment->uuid;
    }
}

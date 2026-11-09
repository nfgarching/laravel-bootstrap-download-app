<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Shipment;
use App\Models\ShipmentFile;
use App\Models\ShipmentRecipient;
use App\Support\IpAnonymizer;

/**
 * Schreibt das Protokoll, das die interne Anwendung über die API abholt.
 */
class EventLogger
{
    public const SHIPMENT_CREATED = 'shipment.created';

    public const SHIPMENT_RELEASED = 'shipment.released';

    public const SHIPMENT_UPDATED = 'shipment.updated';

    public const SHIPMENT_REVOKED = 'shipment.revoked';

    public const SHIPMENT_EXPIRED = 'shipment.expired';

    public const FILE_ADDED = 'file.added';

    public const LINK_OPENED = 'link.opened';

    public const LINK_LOCKED = 'link.locked';

    public const PASSWORD_OK = 'password.ok';

    public const PASSWORD_FAILED = 'password.failed';

    public const FILE_DOWNLOADED = 'file.downloaded';

    public const DOWNLOAD_LIMIT_REACHED = 'download.limit_reached';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $type,
        Shipment $shipment,
        ?ShipmentRecipient $recipient = null,
        ?ShipmentFile $file = null,
        array $meta = [],
        ?string $ip = null,
    ): Event {
        return Event::create([
            'api_client_id' => $shipment->api_client_id,
            'type' => $type,
            'shipment_uuid' => $shipment->uuid,
            'recipient_uuid' => $recipient?->uuid,
            'file_uuid' => $file?->uuid,
            'ip' => IpAnonymizer::anonymize($ip),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}

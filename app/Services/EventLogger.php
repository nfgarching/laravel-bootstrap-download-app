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

    public const ACCOUNT_NOTIFIED = 'account.notified';

    public const UPLOAD_REQUEST_CREATED = 'upload_request.created';

    public const UPLOAD_REQUEST_OPENED = 'upload_request.opened';

    public const UPLOAD_REQUEST_CLOSED = 'upload_request.closed';

    public const UPLOAD_RECEIVED = 'upload.received';

    public const UPLOAD_FETCHED = 'upload.fetched';

    public const UPLOAD_EXPIRED = 'upload.expired';

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

    /**
     * Protokoll für den Rückkanal. Diese Einträge gehören zu keiner Sendung;
     * der Bezug steht in meta (request_id, upload_id, batch).
     *
     * @param  array<string, mixed>  $meta
     */
    public function logUpload(string $type, int $apiClientId, array $meta = [], ?string $ip = null): Event
    {
        return Event::create([
            'api_client_id' => $apiClientId,
            'type' => $type,
            'ip' => IpAnonymizer::anonymize($ip),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'api_client_id',
        'reference',
        'subject',
        'message',
        'status',
        'max_downloads',
        'expires_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'max_downloads' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment) {
            $shipment->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ShipmentFile::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ShipmentRecipient::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Freigegeben, nicht abgelaufen: Nur dann darf abgerufen werden.
     */
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_RELEASED && $this->expires_at->isFuture();
    }
}

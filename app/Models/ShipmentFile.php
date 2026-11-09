<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShipmentFile extends Model
{
    protected $fillable = ['uuid', 'shipment_id', 'name', 'mime_type', 'size', 'sha256', 'path', 'wrapped_key'];

    protected $hidden = ['wrapped_key', 'path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShipmentFile $file) {
            $file->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null || $this->wrapped_key === null;
    }
}

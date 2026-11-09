<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShipmentRecipient extends Model
{
    protected $fillable = ['shipment_id', 'email', 'name', 'wants_password', 'require_login'];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'wants_password' => 'boolean',
            'require_login' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'first_opened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShipmentRecipient $recipient) {
            $recipient->uuid ??= (string) Str::uuid();
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(FileDownload::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}

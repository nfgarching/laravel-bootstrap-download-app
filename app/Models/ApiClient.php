<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    public const ABILITIES = ['shipments:write', 'shipments:read', 'events:read'];

    protected $fillable = ['name', 'token_hash', 'hmac_secret', 'abilities'];

    protected $hidden = ['token_hash', 'hmac_secret'];

    protected function casts(): array
    {
        return [
            'hmac_secret' => 'encrypted',
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function allows(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}

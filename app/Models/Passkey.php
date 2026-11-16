<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebAuthn-Anmeldedaten eines Kontos. credential_id ist Base64 (Standard),
 * public_key der öffentliche Schlüssel im PEM-Format.
 */
class Passkey extends Model
{
    protected $fillable = ['account_id', 'credential_id', 'public_key', 'sign_count', 'name'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

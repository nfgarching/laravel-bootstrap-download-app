<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Bitte der Kanzlei, Unterlagen hochzuladen: persönlicher Link an eine Adresse,
 * optional mit Passwort, befristet, mit Höchstzahl an Dateien.
 */
class UploadRequest extends Model
{
    protected $fillable = [
        'api_client_id', 'reference', 'subject', 'message', 'email', 'name',
        'token_hash', 'password_hash', 'max_files', 'expires_at',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'max_files' => 'integer',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_opened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UploadRequest $request) {
            $request->uuid ??= (string) Str::uuid();
        });
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->locked_at === null && $this->expires_at->isFuture();
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    public function remainingFiles(): int
    {
        return max(0, $this->max_files - $this->uploads()->count());
    }

    public function status(): string
    {
        return match (true) {
            $this->closed_at !== null => 'closed',
            $this->locked_at !== null => 'locked',
            $this->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }
}

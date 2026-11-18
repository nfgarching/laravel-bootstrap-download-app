<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine eingegangene Datei. Der Inhalt liegt verschlüsselt im Vault; der
 * Dateischlüssel ist mit dem öffentlichen Schlüssel der internen Anwendung
 * versiegelt (sealed_key) und für das Portal nicht mehr lesbar.
 *
 * status: received (wartet auf Abholung), fetched (abgeholt, gelöscht),
 *         expired (nicht abgeholt, nach Frist gelöscht)
 */
class Upload extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_FETCHED = 'fetched';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid', 'api_client_id', 'upload_request_id', 'account_id', 'batch',
        'sender_email', 'sender_name', 'subject', 'message',
        'name', 'mime_type', 'size', 'sha256', 'path', 'sealed_key', 'status',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'fetched_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Upload $upload) {
            $upload->uuid ??= (string) Str::uuid();
        });
    }

    public function uploadRequest(): BelongsTo
    {
        return $this->belongsTo(UploadRequest::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

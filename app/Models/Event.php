<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['api_client_id', 'type', 'shipment_uuid', 'recipient_uuid', 'file_uuid', 'ip', 'meta'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

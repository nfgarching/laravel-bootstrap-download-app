<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileDownload extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_recipient_id', 'shipment_file_id', 'download_count', 'last_downloaded_at'];

    protected function casts(): array
    {
        return [
            'download_count' => 'integer',
            'last_downloaded_at' => 'datetime',
        ];
    }
}

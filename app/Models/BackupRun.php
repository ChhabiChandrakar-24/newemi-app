<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $fillable = ['type', 'status', 'disk', 'file_name', 'size_bytes', 'checksum', 'error', 'started_at', 'completed_at'];

    protected $hidden = ['file_name', 'error'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}

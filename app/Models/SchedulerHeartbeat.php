<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerHeartbeat extends Model
{
    protected $fillable = ['task', 'status', 'last_started_at', 'last_succeeded_at', 'safe_message'];

    protected function casts(): array
    {
        return ['last_started_at' => 'datetime', 'last_succeeded_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ScheduledReport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'report_type', 'filters', 'format', 'schedule_type', 'schedule_config', 'delivery_channels', 'recipients', 'is_active', 'created_by', 'last_run_at', 'next_run_at'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'schedule_config' => 'array', 'delivery_channels' => 'array', 'recipients' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'deduplication_key', 'alert_type', 'severity', 'title', 'message', 'entity_type', 'entity_id', 'status', 'acknowledged_at', 'acknowledged_by'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}

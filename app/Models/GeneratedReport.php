<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedReport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'report_uuid', 'report_type', 'requested_by', 'format', 'filters', 'status', 'file_path', 'error', 'requested_at', 'started_at', 'completed_at', 'expires_at'];

    protected $hidden = ['file_path', 'error'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'requested_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

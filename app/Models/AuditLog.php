<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'actor_user_id', 'actor_name', 'actor_email', 'action', 'entity_type',
        'entity_id', 'old_values', 'new_values', 'remarks', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}

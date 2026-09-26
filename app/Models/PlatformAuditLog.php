<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'remarks', 'ip_address'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }
}

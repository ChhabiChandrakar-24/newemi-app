<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'category', 'key', 'value', 'is_secret', 'is_enabled', 'metadata', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean', 'is_enabled' => 'boolean', 'metadata' => 'array'];
    }
}

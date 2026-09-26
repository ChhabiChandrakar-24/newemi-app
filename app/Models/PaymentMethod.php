<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'code', 'name', 'is_enabled', 'requires_reference', 'sort_order'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'requires_reference' => 'boolean', 'sort_order' => 'integer'];
    }
}

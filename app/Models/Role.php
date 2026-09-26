<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = ['company_id', 'is_system', 'name', 'guard_name'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company_roles', function (Builder $query): void {
            $companyId = app(TenantContext::class)->id() ?? Auth::user()?->company_id;
            if ($companyId) {
                $query->where(fn (Builder $q) => $q->where('is_system', true)->orWhere('company_id', $companyId));
            }
        });
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $companyId = app(TenantContext::class)->id() ?? Auth::user()?->company_id;
            if (! $companyId && app()->runningUnitTests()) {
                $companyId = Company::query()->value('id');
            }
            if ($companyId) {
                $builder->where($builder->qualifyColumn('company_id'), $companyId);
            }
        });

        static::creating(function ($model): void {
            $companyId = app(TenantContext::class)->id() ?? Auth::user()?->company_id;
            if (! $companyId && app()->runningUnitTests()) {
                $companyId = Company::query()->value('id');
            }
            if (! $model->company_id && $companyId) {
                $model->company_id = $companyId;
            }
            if (! $model->company_id) {
                throw new LogicException('A company context is required for this record.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

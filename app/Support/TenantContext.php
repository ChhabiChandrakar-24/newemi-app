<?php

namespace App\Support;

use App\Models\Company;

class TenantContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->getKey();
    }

    public function run(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}

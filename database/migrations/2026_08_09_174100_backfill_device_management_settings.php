<?php

use App\Services\DeviceManagementSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existingColumns = Schema::getColumnListing('device_management_settings');

        DB::table('companies')->orderBy('id')->get()->each(function ($company) use ($existingColumns): void {
            $filteredDefaults = collect(DeviceManagementSettingsService::DEFAULTS)
                ->filter(fn ($value, $key) => in_array($key, $existingColumns, true))
                ->map(fn ($value) => is_array($value) ? json_encode($value) : $value)
                ->all();

            DB::table('device_management_settings')->insertOrIgnore([
                'company_id' => $company->id,
                ...$filteredDefaults,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void {}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->change();
            $table->uuid('internal_device_uuid')->nullable()->change();
            $table->timestamp('privacy_erased_at')->nullable()->index()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('privacy_erased_at');
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->uuid('internal_device_uuid')->nullable(false)->change();
        });
    }
};

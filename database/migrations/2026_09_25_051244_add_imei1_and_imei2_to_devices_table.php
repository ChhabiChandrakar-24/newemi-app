<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'imei') && !Schema::hasColumn('devices', 'imei1')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->renameColumn('imei', 'imei1');
            });
        }
        
        if (!Schema::hasColumn('devices', 'imei2')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('imei2')->nullable()->after('imei1');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('imei2');
            $table->renameColumn('imei1', 'imei');
        });
    }
};

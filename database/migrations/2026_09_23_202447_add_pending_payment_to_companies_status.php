<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('active', 'inactive', 'suspended', 'closed', 'pending_payment') DEFAULT 'inactive'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('active', 'inactive', 'suspended', 'closed') DEFAULT 'inactive'");
        }
    }
};

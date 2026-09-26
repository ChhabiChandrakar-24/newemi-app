<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('mobile_number', 20)->nullable()->index()->after('email');
            $table->string('status', 20)->default('active')->index()->after('password');
            $table->softDeletes();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['mobile_number']);
            $table->dropIndex(['status']);
            $table->dropIndex(['name']);
            $table->dropColumn(['mobile_number', 'status', 'deleted_at']);
        });
    }
};

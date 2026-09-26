<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lock_policies', fn (Blueprint $table) => $table->softDeletes());
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('requires_reference')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::table('lock_policies', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};

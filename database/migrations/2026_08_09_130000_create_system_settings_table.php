<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 50);
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['category', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};

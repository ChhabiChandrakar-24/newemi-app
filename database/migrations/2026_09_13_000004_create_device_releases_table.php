<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('device_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('emi_account_id')->nullable()->constrained();
            $table->foreignId('released_by')->nullable();

            $table->string('release_reason', 80)->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('release_timestamp')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_releases');
    }
};
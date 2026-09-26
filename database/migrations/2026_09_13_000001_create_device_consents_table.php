<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('enrollment_id')->nullable();
            $table->foreignId('emi_account_id')->nullable()->constrained();

            $table->enum('consent_status', ['accepted', 'rejected', 'withdrawn', 'expired'])
                ->default('accepted')->index();

            $table->timestamp('consent_timestamp')->index();
            $table->string('terms_version', 50)->nullable();
            $table->string('privacy_version', 50)->nullable();
            $table->string('consent_device_id', 191)->nullable()->index();
            $table->string('consent_ip_address', 45)->nullable();
            $table->timestamp('enrollment_timestamp')->nullable();

            $table->boolean('accepted_terms')->default(false);
            $table->boolean('accepted_conditions')->default(false);
            $table->boolean('accepted_privacy')->default(false);

            $table->json('metadata')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'consent_status']);
            $table->index(['company_id', 'device_id', 'consent_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_consents');
    }
};
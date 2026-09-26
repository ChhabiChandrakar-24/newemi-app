<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_code', 32)->unique();
            $table->string('full_name')->index();
            $table->string('mobile_number', 16)->index();
            $table->string('alternate_mobile_number', 16)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable()->index();
            $table->string('state', 100)->nullable()->index();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('India');
            $table->string('identity_type', 50)->nullable();
            $table->string('identity_number', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->boolean('consent_given')->default(false)->index();
            $table->timestamp('consent_given_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

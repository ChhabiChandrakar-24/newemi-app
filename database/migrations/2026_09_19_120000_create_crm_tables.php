<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('lead_code', 32)->index();
            $table->string('customer_name', 120);
            $table->string('shop_name', 150)->nullable();
            $table->string('phone', 20)->index();
            $table->string('alternate_phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('lead_type', 50)->default('shop_retailer'); // shop_retailer, direct_customer, software_project, device_lock_partner
            $table->string('status', 40)->default('new'); // new, contacted, visit_scheduled, visited, proposal_sent, negotiation, won, lost
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('estimated_devices')->nullable();
            $table->decimal('estimated_budget', 12, 2)->nullable();
            $table->dateTime('next_followup_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->dateTime('converted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'next_followup_at']);
        });

        Schema::create('crm_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('visit_code', 32)->index();
            $table->foreignId('sales_person_id')->constrained('users')->cascadeOnDelete();
            $table->string('shop_name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->dateTime('visit_date');
            $table->string('purpose', 50)->default('introductory'); // introductory, demo, deal_closing, device_setup, collection_recovery, support
            $table->string('outcome', 50)->default('positive'); // positive, deal_closed, follow_up_needed, not_interested, shop_closed, rescheduled
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->float('accuracy_meters')->nullable();
            $table->text('location_address')->nullable();
            $table->json('photos')->nullable(); // Array of image URLs / filepaths
            $table->string('next_action', 150)->nullable();
            $table->dateTime('next_followup_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'visit_date']);
            $table->index(['company_id', 'sales_person_id']);
        });

        Schema::create('crm_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('project_code', 32)->index();
            $table->string('client_name', 120);
            $table->string('client_phone', 20)->nullable();
            $table->string('client_email', 120)->nullable();
            $table->string('title', 150);
            $table->string('project_type', 50)->default('custom_software'); // mobile_app, web_application, emi_lock_customization, ecommerce, erp_crm, custom_api
            $table->text('description')->nullable();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('status', 40)->default('requirement_gathering'); // requirement_gathering, quoted, in_progress, testing, completed, delivered, on_hold, cancelled
            $table->date('start_date')->nullable();
            $table->date('delivery_deadline')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('milestones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('crm_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_name', 120)->nullable();
            $table->string('channel', 20)->default('sms'); // sms, email, whatsapp
            $table->string('gateway_provider', 40)->default('fast2sms'); // fast2sms, twilio, simulation, custom
            $table->unsignedInteger('recipient_count')->default(1);
            $table->json('recipients');
            $table->text('message_body');
            $table->string('status', 30)->default('sent'); // queued, sending, sent, partially_failed, failed
            $table->json('response_payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
        Schema::dropIfExists('crm_projects');
        Schema::dropIfExists('crm_visits');
        Schema::dropIfExists('crm_leads');
    }
};

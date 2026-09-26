<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $ownedTables = [
        'customers', 'emi_accounts', 'payments', 'devices', 'device_enrollments',
        'device_api_credentials', 'device_events', 'device_commands', 'device_push_tokens',
        'device_push_attempts', 'device_locations', 'lock_policies', 'audit_logs',
        'generated_reports', 'scheduled_reports', 'system_alerts', 'system_settings',
    ];

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('company_code', 32)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('support_email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('India');
            $table->string('logo_path')->nullable();
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('currency', 3)->default('INR');
            $table->enum('status', ['active', 'inactive', 'suspended', 'closed'])->default('inactive')->index();
            $table->string('plan')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_devices')->nullable();
            $table->string('subscription_status')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('remarks')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
        });

        $defaultId = DB::table('companies')->insertGetId([
            'company_code' => 'CMP-000001', 'name' => 'Default Company', 'status' => 'active',
            'subscription_status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('company_id')->index();
            $table->index(['company_id', 'status']);
        });
        DB::table('users')->update(['company_id' => $defaultId]);

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_name_guard_name_unique');
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_system')->default(false)->after('company_id')->index();
            $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
        });
        DB::table('roles')->whereIn('name', ['super-admin', 'admin', 'manager', 'staff', 'auditor'])->update(['is_system' => true]);

        foreach ($this->ownedTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                $table->index(['company_id', 'created_at'], $tableName.'_company_created_index');
            });
            DB::table($tableName)->update(['company_id' => $defaultId]);
            Schema::table($tableName, fn (Blueprint $table) => $table->foreignId('company_id')->nullable(false)->change());
        }

        foreach (['customers', 'emi_accounts', 'payments', 'devices', 'lock_policies'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'status')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index(['company_id', 'status'], $tableName.'_company_status_index'));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->ownedTables) as $tableName) {
            if (Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    if (Schema::hasColumn($tableName, 'status') && in_array($tableName, ['customers', 'emi_accounts', 'payments', 'devices', 'lock_policies'], true)) {
                        $table->dropIndex($tableName.'_company_status_index');
                    }
                    $table->dropIndex($tableName.'_company_created_index');
                    $table->dropConstrainedForeignId('company_id');
                });
            }
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['is_platform_admin']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('is_platform_admin');
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_company_name_guard_unique');
            $table->dropIndex(['is_system']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('is_system');
            $table->unique(['name', 'guard_name']);
        });
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('companies');
    }
};

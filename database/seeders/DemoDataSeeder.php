<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\DeviceEvent;
use App\Models\GeneratedReport;
use App\Models\LockPolicy;
use App\Models\PaymentGateway;
use App\Models\ScheduledReport;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\DeviceService;
use App\Services\EmiAccountService;
use App\Services\PaymentReversalService;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;

class DemoDataSeeder extends Seeder
{
    private const COMPANIES = [
        ['CMP-000001', 'Company Alpha Finance', 'alpha'],
        ['CMP-000002', 'Company Beta Mobiles', 'beta'],
        ['CMP-000003', 'Company Gamma Retail', 'gamma'],
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('DEMO_SEED_ALLOWED', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Demo data is disabled in production. Set DEMO_SEED_ALLOWED=true only for an intentional disposable demo environment.');
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $password = (string) env('DEMO_PASSWORD', 'Demo@12345678');
        $platform = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => (string) env('DEMO_PLATFORM_ADMIN_EMAIL', 'demo.superadmin@example.test')],
            ['company_id' => null, 'name' => 'Demo Platform Super Admin', 'password' => Hash::make($password), 'status' => 'active', 'is_platform_admin' => true]
        );
        $platform->syncRoles(['super-admin']);

        foreach (self::COMPANIES as $index => [$code, $name, $slug]) {
            $company = Company::withTrashed()->updateOrCreate(['company_code' => $code], [
                'name' => $name, 'legal_name' => $name.' Private Limited',
                'email' => "support@{$slug}.example.test", 'support_email' => "help@{$slug}.example.test",
                'phone' => '+91900000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'city' => ['Jaipur', 'Indore', 'Lucknow'][$index], 'state' => ['Rajasthan', 'Madhya Pradesh', 'Uttar Pradesh'][$index],
                'status' => 'active', 'plan' => 'demo', 'subscription_status' => 'demo',
                'max_users' => 100, 'max_devices' => 500, 'activated_at' => now(),
                'settings' => ['demo' => true, 'branding' => ['primary_color' => ['#2563eb', '#059669', '#7c3aed'][$index]]],
            ]);
            if ($company->trashed()) $company->restore();
            app(TenantContext::class)->run($company, fn () => $this->seedCompany($company, $slug, $index, $password));
        }
    }

    private function seedCompany(Company $company, string $slug, int $companyIndex, string $password): void
    {
        $users = [];
        foreach ([['owner', 'admin'], ['manager', 'manager'], ['staff1', 'staff'], ['staff2', 'staff'], ['auditor', 'auditor']] as [$key, $role]) {
            $user = User::withTrashed()->updateOrCreate(['email' => "demo.{$key}.{$slug}@example.test"], [
                'company_id' => $company->id, 'name' => ucfirst($slug).' '.ucfirst($key),
                'password' => Hash::make($password), 'status' => 'active', 'is_platform_admin' => false,
            ]);
            if ($user->trashed()) $user->restore();
            $user->syncRoles([$role]);
            $users[$key] = $user;
        }

        $customRole = \App\Models\Role::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Collection Agent', 'guard_name' => 'web'], ['is_system' => false]
        );
        $customRole->syncPermissions(Permission::query()->whereIn('name', ['customers.view', 'emi.view', 'payments.view', 'payments.create'])->get());

        $policies = collect([
            ['Standard EMI Policy', true, true, 1, 4, 8],
            ['Grace Policy', false, true, 3, 8, 15],
            ['Strict Collection Policy', false, $companyIndex !== 2, 0, 2, 5],
        ])->map(fn ($p) => LockPolicy::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'name' => $p[0]],
            ['is_default' => $p[1], 'is_active' => $p[2], 'warning_after_overdue_days' => $p[3], 'partial_lock_after_overdue_days' => $p[4], 'full_lock_after_overdue_days' => $p[5], 'unlock_on_payment_clearance' => true, 'offline_behavior' => 'defer', 'created_by' => $users['owner']->id, 'updated_by' => $users['owner']->id]
        ));

        $customerService = app(CustomerService::class);
        $customers = collect();
        $names = ['Aarav Sharma','Diya Verma','Vivaan Gupta','Ananya Singh','Aditya Patel','Meera Joshi','Arjun Yadav','Kavya Nair','Rohan Mishra','Isha Kapoor'];
        foreach ($names as $i => $name) {
            $email = "customer.".($companyIndex + 1).'.'.($i + 1).'@example.test';
            $customer = Customer::withTrashed()->where('company_id', $company->id)->where('email', $email)->first();
            if (! $customer) {
                $customer = $customerService->create([
                    'full_name' => $name, 'mobile_number' => '900'.($companyIndex + 1).str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                    'email' => $email, 'city' => $company->city, 'state' => $company->state,
                    'identity_type' => 'demo_reference', 'identity_number' => 'DEMO-'.($companyIndex + 1).'-'.($i + 1),
                    'consent_given' => $i % 3 !== 0, 'status' => 'active', 'notes' => 'DEMO / TEST record only',
                ], $users['owner']);
            }
            $customers->push($customer);
        }
        $customers[8]->update(['status' => 'inactive']);
        $customers[9]->update(['status' => 'closed']);

        $emiService = app(EmiAccountService::class);
        $accounts = collect();
        for ($i = 0; $i < 12; $i++) {
            $invoice = strtoupper($slug).'-DEMO-EMI-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $account = \App\Models\EmiAccount::where('invoice_number', $invoice)->first();
            if (! $account) {
                $customer = $customers[$i % 8];
                $account = $emiService->create([
                    'customer_id' => $customer->id, 'invoice_number' => $invoice, 'invoice_date' => now()->subMonths(4)->toDateString(),
                    'product_description' => ['Samsung Galaxy Demo','Xiaomi Redmi Demo','Vivo Demo Phone'][$i % 3],
                    'financed_amount' => (string) (18000 + $i * 1000), 'down_payment' => '2000', 'total_installments' => 6,
                    'emi_start_date' => now()->subMonths(3)->startOfMonth()->addDays(4)->toDateString(), 'grace_period_days' => 5,
                    'interest_amount' => '1200', 'processing_fee' => '300', 'other_charges' => '0', 'status' => 'active', 'auto_lock_enabled' => true,
                ], $users['owner']);
            }
            $accounts->push($account);
        }
        foreach ($accounts as $i => $account) {
            if ($i === 0) $account->update(['status' => 'draft']);
            elseif ($i === 8) $account->update(['status' => 'cancelled']);
            elseif ($i === 9) $account->update(['status' => 'closed']);
            elseif ($i === 10) $account->update(['status' => 'overdue']);
        }

        $paymentService = app(PaymentService::class);
        foreach ($accounts->slice(1, 7) as $accountIndex => $account) {
            for ($j = 0; $j < 3; $j++) {
                $key = "demo-{$company->id}-{$account->id}-{$j}";
                if (\App\Models\Payment::where('idempotency_key', $key)->exists()) continue;
                $amount = $j === 0 ? number_format((float) $account->installment_amount / 2, 2, '.', '') : (string) $account->installment_amount;
                $result = $paymentService->create([
                    'emi_account_id' => $account->id, 'amount' => $amount, 'payment_date' => now()->subDays(20 - $j * 7)->toDateString(),
                    'payment_method' => ['cash','upi','bank_transfer'][$j], 'payment_type' => $j === 2 ? 'installment' : 'emi',
                    'status' => $j === 1 ? 'pending' : 'verified', 'notes' => 'DEMO / TEST payment',
                ], $users['owner'], $key);
                if ($j === 1 && $accountIndex % 3 === 0) app(PaymentService::class)->cancel($result['payment'], $users['owner'], 'Demo cancelled payment');
                if ($j === 2 && $accountIndex === 1) app(PaymentReversalService::class)->reverse($result['payment'], $users['owner'], 'Demo reversal');
            }
        }

        $deviceService = app(DeviceService::class);
        $devices = collect();
        for ($i = 0; $i < 15; $i++) {
            $invoice = strtoupper($slug).'-DEMO-DEV-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $device = Device::withTrashed()->where('invoice_number', $invoice)->first();
            if (! $device) {
                $customer = $customers[$i % 8];
                $account = $accounts->first(fn ($a) => $a->customer_id === $customer->id && ! in_array($a->status, ['cancelled','closed'])) ?? $accounts[1];
                $device = $deviceService->register(['customer_id' => $customer->id, 'emi_account_id' => $account->id, 'display_name' => "Demo Phone ".($i + 1), 'brand' => ['Samsung','Xiaomi','Vivo'][$i % 3], 'model' => 'Demo Model '.($i + 1), 'invoice_number' => $invoice], $users['owner']);
            }
            $device->update([
                'lock_policy_id' => $policies[$i % 3]->id, 'management_mode' => ['device_owner','fully_managed','unmanaged','unknown'][$i % 4],
                'enrollment_status' => $i < 3 ? 'pending' : ($i === 14 ? 'released' : 'enrolled'),
                'control_status' => ['active','warning','partial_lock','full_lock','unlocked','released'][$i % 6],
                'connectivity_status' => $i % 4 === 0 ? 'offline' : 'online', 'compliance_status' => $i % 5 === 0 ? 'non_compliant' : 'compliant',
                'app_version' => $i % 4 === 0 ? '0.9.0' : '1.0.0', 'last_seen_at' => now()->subMinutes($i * 20),
                'capabilities' => ['demo_record' => true, 'can_enforce_lock_task' => $i % 4 < 2, 'can_apply_device_restrictions' => $i % 4 < 2],
            ]);
            $devices->push($device);
        }

        foreach ($devices as $i => $device) {
            foreach (['pending','used','expired','revoked'] as $j => $status) DeviceEnrollment::firstOrCreate(
                ['enrollment_code' => "DEMO-ENR-{$company->id}-{$device->id}-{$j}"],
                ['company_id' => $company->id, 'device_id' => $device->id, 'token_hash' => hash('sha256', "non-reusable-demo-{$company->id}-{$device->id}-{$j}"), 'expires_at' => $status === 'expired' ? now()->subDay() : now()->addDay(), 'used_at' => $status === 'used' ? now() : null, 'revoked_at' => $status === 'revoked' ? now() : null, 'status' => $status, 'created_by' => $users['owner']->id, 'metadata' => ['demo' => true]]
            );
            foreach (['show_warning','partial_lock','full_lock','unlock','policy_sync','refresh_status'] as $j => $type) DeviceCommand::firstOrCreate(
                ['idempotency_key' => "demo-command-{$company->id}-{$device->id}-{$j}"],
                ['company_id' => $company->id, 'command_uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'command_type' => $type, 'status' => ['queued','dispatched','received','applied','failed','expired','cancelled'][$j % 7], 'priority' => 50, 'source' => 'demo', 'requested_by' => $users['owner']->id, 'requested_at' => now()->subHours($j), 'available_at' => now()->subHours($j), 'expires_at' => now()->addDay(), 'payload' => ['demo' => true]]
            );
            foreach (['device_offline','device_online','management_changed','sim_changed','device_non_compliant','command_failed','agent_outdated','possible_tamper'] as $j => $type) DeviceEvent::firstOrCreate(
                ['company_id' => $company->id, 'device_id' => $device->id, 'event_type' => $type, 'event_time' => now()->subMinutes($j + $i * 10)],
                ['severity' => ['info','warning','high','critical'][$j % 4], 'payload' => ['demo' => true, 'safe_note' => 'Synthetic event; no real telemetry']]
            );
        }

        foreach (['info','warning','high','critical'] as $i => $severity) SystemAlert::updateOrCreate(
            ['deduplication_key' => "demo-alert-{$company->id}-{$i}"],
            ['company_id' => $company->id, 'alert_type' => 'demo_security', 'severity' => $severity, 'title' => ucfirst($severity).' demo alert', 'message' => 'Synthetic test alert only.', 'status' => $i % 2 ? 'acknowledged' : 'open', 'acknowledged_at' => $i % 2 ? now() : null, 'acknowledged_by' => $i % 2 ? $users['manager']->id : null]
        );

        foreach ([['razorpay','Demo Razorpay Test'],['cashfree','Demo Cashfree Test']] as $i => [$provider,$display]) PaymentGateway::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'display_name' => $display],
            ['webhook_key' => (string) Str::uuid(), 'provider' => $provider, 'environment' => 'test', 'is_enabled' => false, 'is_default' => $i === 0, 'public_key' => 'demo_public_identifier_not_functional', 'encrypted_secret' => 'DEMO_NOT_A_REAL_SECRET', 'config' => ['demo' => true], 'status' => 'demo', 'created_by' => $users['owner']->id, 'updated_by' => $users['owner']->id]
        );

        foreach (['portfolio','collections','device_compliance'] as $i => $type) {
            ScheduledReport::firstOrCreate(['company_id' => $company->id, 'name' => ucfirst(str_replace('_',' ',$type)).' Demo'], ['report_type' => $type, 'filters' => [], 'format' => 'xlsx', 'schedule_type' => 'weekly', 'schedule_config' => ['day' => 1], 'delivery_channels' => ['email'], 'recipients' => [$company->support_email], 'is_active' => $i !== 2, 'created_by' => $users['owner']->id, 'next_run_at' => now()->addWeek()]);
            GeneratedReport::firstOrCreate(['company_id' => $company->id, 'report_uuid' => "demo-report-{$company->id}-{$i}"], ['report_type' => $type, 'requested_by' => $users['owner']->id, 'format' => 'xlsx', 'filters' => ['demo' => true], 'status' => 'queued', 'requested_at' => now()->subDays($i)]);
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\LockPolicy;
use App\Models\Payment;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\DeviceManagementSettingsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()->withCount('users');
        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('company_code', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->latest()->paginate(min($request->integer('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $owner = $request->validate($this->ownerRules())['owner'];

        $company = DB::transaction(function () use ($request, $data, $owner): Company {
            $company = Company::create($data + ['company_code' => $this->nextCode()]);
            $user = User::create([
                'company_id' => $company->id, 'name' => $owner['name'], 'email' => $owner['email'],
                'mobile_number' => $owner['mobile_number'] ?? null, 'password' => Hash::make($owner['password']), 'status' => 'active',
            ]);
            $user->syncRoles(['admin']);
            app(TenantContext::class)->run($company, function () use ($user): void {
                LockPolicy::create([
                    'name' => 'Default Policy', 'is_default' => true, 'is_active' => true,
                    'warning_after_overdue_days' => 1, 'partial_lock_after_overdue_days' => 3,
                    'full_lock_after_overdue_days' => 7, 'unlock_on_payment_clearance' => true,
                    'offline_behavior' => 'keep_current_state', 'created_by' => $user->id,
                ]);
                app(DeviceManagementSettingsService::class)->current();
            });
            $this->audit($request, 'company.created', $company, null, $company->toArray());

            return $company;
        });

        return response()->json(['message' => 'Company and owner created.', 'company' => $company->load('users')], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $counts = [
            'users' => User::where('company_id', $company->id)->count(),
            'customers' => Customer::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'active_emi' => EmiAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->count(),
            'overdue_emi' => EmiAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('overdue_amount', '>', 0)->count(),
            'devices' => Device::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'locked_devices' => Device::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('control_status', ['partial_lock', 'full_lock'])->count(),
            'payments' => Payment::withoutGlobalScopes()->where('company_id', $company->id)->count(),
        ];

        return response()->json(['company' => $company, 'counts' => $counts, 'owners' => User::where('company_id', $company->id)->role('admin')->get()]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $old = $company->toArray();
        $company->update($request->validate($this->rules($company)));
        $this->audit($request, 'company.updated', $company, $old, $company->fresh()->toArray());

        return response()->json(['message' => 'Company updated.', 'company' => $company->fresh()]);
    }

    public function status(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive', 'suspended', 'closed'])], 'reason' => ['required', 'string', 'max:1000']]);
        $old = $company->toArray();
        $timestamps = match ($data['status']) {
            'active' => ['activated_at' => now(), 'suspended_at' => null, 'closed_at' => null],
            'suspended' => ['suspended_at' => now()],
            'closed' => ['closed_at' => now()],
            default => [],
        };
        $company->update(['status' => $data['status']] + $timestamps);
        $this->audit($request, 'company.status_changed', $company, $old, $company->fresh()->toArray(), $data['reason']);

        return response()->json(['message' => 'Company status updated.', 'company' => $company->fresh()]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($company->status !== 'closed', 422, 'Close the company before archiving it.');
        $company->delete();
        $this->audit($request, 'company.archived', $company, null, null, $data['reason']);

        return response()->json(['message' => 'Company archived. Historical data was retained.']);
    }

    public function restore(Request $request, int $company): JsonResponse
    {
        $model = Company::onlyTrashed()->findOrFail($company);
        $model->restore();
        $this->audit($request, 'company.restored', $model);

        return response()->json(['message' => 'Company restored.', 'company' => $model]);
    }

    private function rules(?Company $company = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:32'],
            'support_phone' => ['nullable', 'string', 'max:32'], 'support_email' => ['nullable', 'email'],
            'address_line_1' => ['nullable', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'country' => ['sometimes', 'string', 'max:100'],
            'timezone' => ['sometimes', 'timezone'], 'currency' => ['sometimes', 'string', 'size:3'],
            'plan' => ['nullable', 'string', 'max:100'], 'max_users' => ['nullable', 'integer', 'min:1'],
            'max_devices' => ['nullable', 'integer', 'min:1'], 'subscription_status' => ['nullable', 'string', 'max:100'],
            'trial_ends_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'], 'settings' => ['nullable', 'array'],
        ];
    }

    private function ownerRules(): array
    {
        return ['owner' => ['required', 'array'], 'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', 'unique:users,email'], 'owner.mobile_number' => ['nullable', 'string', 'max:20'],
            'owner.password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]];
    }

    private function nextCode(): string
    {
        $id = (int) Company::withTrashed()->max('id') + 1;

        return 'CMP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function audit(Request $request, string $action, Company $company, ?array $old = null, ?array $new = null, ?string $remarks = null): void
    {
        PlatformAuditLog::create(['actor_user_id' => $request->user()->id, 'action' => $action, 'entity_type' => Company::class,
            'entity_id' => $company->id, 'old_values' => $old, 'new_values' => $new, 'remarks' => $remarks, 'ip_address' => $request->ip()]);
    }
}

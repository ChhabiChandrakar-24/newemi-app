<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Requests\Api\V1\UpdateCustomerStatusRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,closed'],
            'consent_given' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:created_at,full_name,customer_code'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $customers = Customer::query()
            ->when($request->boolean('archived'), fn ($query) => $query->onlyTrashed())
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('customer_code', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('mobile_number', 'like', "%{$search}%")
                        ->orWhere('alternate_mobile_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(array_key_exists('consent_given', $validated), fn ($query) => $query->where('consent_given', $request->boolean('consent_given')))
            ->when($validated['city'] ?? null, fn ($query, string $city) => $query->where('city', $city))
            ->when($validated['state'] ?? null, fn ($query, string $state) => $query->where('state', $state))
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function summary(Request $request, Customer $customer): JsonResponse
    {
        $auditEvents = AuditLog::query()
            ->where('entity_type', $customer->getMorphClass())
            ->where('entity_id', (string) $customer->getKey())
            ->count();

        return response()->json([
            'data' => [
                'customer' => (new CustomerResource($customer))->resolve($request),
                'statistics' => [
                    'audit_events_count' => $auditEvents,
                    'days_as_customer' => (int) $customer->created_at->diffInDays(now()),
                ],
            ],
        ]);
    }

    public function history(Request $request, Customer $customer): JsonResponse
    {
        $customer->load([
            'emiAccounts.devices',
            'emiAccounts.schedules',
            'emiAccounts.payments',
        ]);

        $allAccounts = $customer->emiAccounts;
        $activeAccounts = $allAccounts->whereNotIn('status', ['completed', 'cancelled', 'closed']);

        $totalDevices = $allAccounts->flatMap->devices->count();
        $totalPaid = $allAccounts->sum('total_paid');
        $totalOutstanding = $allAccounts->sum('outstanding_amount');
        $totalOverdue = $allAccounts->sum('overdue_amount');

        $activeSchedules = collect();
        $autoPaymentEligible = false;

        foreach ($activeAccounts as $account) {
            $pendingSchedules = $account->schedules->whereIn('status', ['pending', 'due', 'overdue']);
            
            // If any schedule is overdue by more than 2 days, auto payment is theoretically eligible.
            if ($pendingSchedules->where('status', 'overdue')->first(fn($s) => now()->startOfDay()->diffInDays($s->due_date) >= 2)) {
                $autoPaymentEligible = true;
            }

            $activeSchedules->push([
                'emi_account_code' => $account->emi_account_code,
                'pending_installments' => $pendingSchedules->count(),
                'overdue_installments' => $pendingSchedules->where('status', 'overdue')->count(),
                'overdue_amount' => $account->overdue_amount,
                'next_due_date' => $account->next_due_date?->toDateString(),
            ]);
        }

        return response()->json([
            'data' => [
                'customer' => (new CustomerResource($customer))->resolve($request),
                'overall_summary' => [
                    'total_devices' => $totalDevices,
                    'total_emi_accounts' => $allAccounts->count(),
                    'active_emi_accounts' => $activeAccounts->count(),
                    'completed_emi_accounts' => $allAccounts->where('status', 'completed')->count(),
                ],
                'financial_summary' => [
                    'total_paid' => $totalPaid,
                    'total_outstanding' => $totalOutstanding,
                    'total_overdue' => $totalOverdue,
                ],
                'active_emi_details' => $activeSchedules,
                'auto_payment_eligible' => $autoPaymentEligible,
                'history' => App\Http\Resources\Api\V1\EmiAccountResource::collection($allAccounts)->resolve($request),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        return (new CustomerResource($this->customers->create($request->validated(), $request->user())))
            ->additional(['message' => 'Customer created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return (new CustomerResource($this->customers->update(
            $customer,
            $request->validated(),
            $request->user(),
        )))->additional(['message' => 'Customer updated successfully.']);
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, Customer $customer): CustomerResource
    {
        return (new CustomerResource($this->customers->changeStatus(
            $customer,
            $request->validated('status'),
            $request->user(),
        )))->additional(['message' => 'Customer status updated successfully.']);
    }

    public function destroy(Request $request, Customer $customer): Response
    {
        $this->customers->delete($customer, $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, int $customer): CustomerResource
    {
        return (new CustomerResource($this->customers->restore(
            Customer::onlyTrashed()->findOrFail($customer),
            $request->user(),
        )))->additional(['message' => 'Customer restored successfully.']);
    }
}

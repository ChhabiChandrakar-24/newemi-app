<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmiAccountRequest;
use App\Http\Requests\Api\V1\UpdateEmiAccountRequest;
use App\Http\Requests\Api\V1\UpdateEmiAccountStatusRequest;
use App\Http\Resources\Api\V1\EmiAccountResource;
use App\Http\Resources\Api\V1\EmiScheduleResource;
use App\Models\Customer;
use App\Models\EmiAccount;
use App\Services\EmiAccountService;
use App\Services\EmiLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmiAccountController extends Controller
{
    public function __construct(private readonly EmiAccountService $accounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'status' => ['nullable', 'in:draft,active,overdue,completed,cancelled,closed'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'emi_account_code' => ['nullable', 'string', 'max:32'],
            'overdue' => ['nullable', 'boolean'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'in:created_at,emi_start_date,next_due_date,outstanding_amount,overdue_amount'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $accounts = EmiAccount::query()
            ->with('customer')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('emi_account_code', 'like', "%{$search}%")
                        ->orWhere('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%"));
                });
            })
            ->when($validated['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['invoice_number'] ?? null, fn ($query, string $invoice) => $query->where('invoice_number', $invoice))
            ->when($validated['emi_account_code'] ?? null, fn ($query, string $code) => $query->where('emi_account_code', $code))
            ->when($request->boolean('overdue'), fn ($query) => $query->where('overdue_amount', '>', 0))
            ->when(($validated['due_from'] ?? null) || ($validated['due_to'] ?? null), function ($query) use ($validated): void {
                $query->whereHas('schedules', function ($schedule) use ($validated): void {
                    $schedule
                        ->when($validated['due_from'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '>=', $date))
                        ->when($validated['due_to'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '<=', $date));
                });
            })
            ->when($validated['created_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['created_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return EmiAccountResource::collection($accounts);
    }

    public function forCustomer(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return EmiAccountResource::collection(
            $customer->emiAccounts()->with('customer')->latest()->paginate($validated['per_page'] ?? 15),
        );
    }

    public function show(EmiAccount $emiAccount): EmiAccountResource
    {
        return new EmiAccountResource($emiAccount->load('customer'));
    }

    public function store(StoreEmiAccountRequest $request): JsonResponse
    {
        return (new EmiAccountResource($this->accounts->create($request->validated(), $request->user())))
            ->additional(['message' => 'EMI account created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateEmiAccountRequest $request, EmiAccount $emiAccount): EmiAccountResource
    {
        return (new EmiAccountResource($this->accounts->update(
            $emiAccount,
            $request->validated(),
            $request->user(),
        )))->additional(['message' => 'EMI account updated successfully.']);
    }

    public function updateStatus(UpdateEmiAccountStatusRequest $request, EmiAccount $emiAccount): EmiAccountResource
    {
        return (new EmiAccountResource($this->accounts->changeStatus(
            $emiAccount,
            $request->validated('status'),
            $request->user(),
        )->load('customer')))->additional(['message' => 'EMI account status updated successfully.']);
    }

    public function summary(EmiAccount $emiAccount): JsonResponse
    {
        $emiAccount->load(['customer', 'schedules']);

        return response()->json([
            'data' => [
                'account' => (new EmiAccountResource($emiAccount))->resolve(request()),
                'installments' => [
                    'total' => $emiAccount->schedules->count(),
                    'paid' => $emiAccount->schedules->where('status', 'paid')->count(),
                    'pending' => $emiAccount->schedules->whereIn('status', ['pending', 'due', 'partially_paid'])->count(),
                    'overdue' => $emiAccount->schedules->where('status', 'overdue')->count(),
                ],
                'payments' => [
                    'verified_count' => $emiAccount->payments()->where('status', 'verified')->count(),
                    'reversed_count' => $emiAccount->payments()->where('status', 'reversed')->count(),
                    'total_paid' => $emiAccount->total_paid,
                    'outstanding_amount' => $emiAccount->outstanding_amount,
                    'overdue_amount' => $emiAccount->overdue_amount,
                    'last_payment_date' => $emiAccount->last_payment_date?->toDateString(),
                    'next_due_date' => $emiAccount->next_due_date?->toDateString(),
                ],
            ],
        ]);
    }

    public function schedule(EmiAccount $emiAccount): AnonymousResourceCollection
    {
        return EmiScheduleResource::collection($emiAccount->schedules()->orderBy('installment_number')->get());
    }

    /** Marks an EMI fully paid, releases every enrolled device and notifies the customer. */
    public function complete(EmiAccount $emiAccount, EmiLifecycleService $lifecycle): EmiAccountResource
    {
        return (new EmiAccountResource($lifecycle->complete($emiAccount, request()->user())->load('customer')))
            ->additional(['message' => 'EMI completed. All enrolled devices have been released from enforcement.']);
    }
}

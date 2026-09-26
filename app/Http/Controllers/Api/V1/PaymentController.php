<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelPaymentRequest;
use App\Http\Requests\Api\V1\ReversePaymentRequest;
use App\Http\Requests\Api\V1\SettleEmiAccountRequest;
use App\Http\Requests\Api\V1\SettlementPreviewRequest;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Services\PaymentReversalService;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
use App\Services\ReceiptService;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'payment_code' => ['nullable', 'string', 'max:32'],
            'receipt_number' => ['nullable', 'string', 'max:40'],
            'emi_account_id' => ['nullable', 'integer', 'exists:emi_accounts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method' => ['nullable', 'in:cash,upi,bank_transfer,card,cheque,other'],
            'payment_type' => ['nullable', 'in:emi,advance,partial,settlement,adjustment'],
            'status' => ['nullable', 'in:pending,verified,failed,reversed,cancelled'],
            'collected_by' => ['nullable', 'integer', 'exists:users,id'],
            'payment_from' => ['nullable', 'date'],
            'payment_to' => ['nullable', 'date', 'after_or_equal:payment_from'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'in:payment_date,amount,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->with(['emiAccount', 'customer', 'allocations.emiSchedule'])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('payment_code', 'like', "%{$search}%")
                        ->orWhere('receipt_number', 'like', "%{$search}%")
                        ->orWhere('transaction_reference', 'like', "%{$search}%")
                        ->orWhereHas('emiAccount', fn ($account) => $account
                            ->where('emi_account_code', 'like', "%{$search}%")
                            ->orWhere('invoice_number', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%"));
                });
            })
            ->when($validated['payment_code'] ?? null, fn ($query, string $value) => $query->where('payment_code', $value))
            ->when($validated['receipt_number'] ?? null, fn ($query, string $value) => $query->where('receipt_number', $value))
            ->when($validated['emi_account_id'] ?? null, fn ($query, int $value) => $query->where('emi_account_id', $value))
            ->when($validated['customer_id'] ?? null, fn ($query, int $value) => $query->where('customer_id', $value))
            ->when($validated['payment_method'] ?? null, fn ($query, string $value) => $query->where('payment_method', $value))
            ->when($validated['payment_type'] ?? null, fn ($query, string $value) => $query->where('payment_type', $value))
            ->when($validated['status'] ?? null, fn ($query, string $value) => $query->where('status', $value))
            ->when($validated['collected_by'] ?? null, fn ($query, int $value) => $query->where('collected_by', $value))
            ->when($validated['payment_from'] ?? null, fn ($query, string $date) => $query->whereDate('payment_date', '>=', $date))
            ->when($validated['payment_to'] ?? null, fn ($query, string $date) => $query->whereDate('payment_date', '<=', $date))
            ->when($validated['created_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['created_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load(['emiAccount', 'customer', 'allocations.emiSchedule']));
    }

    public function store(StorePaymentRequest $request, PaymentService $payments): JsonResponse
    {
        $result = $payments->create($request->validated(), $request->user(), $request->validated('idempotency_key'));

        return (new PaymentResource($result['payment']))
            ->additional(['message' => $result['created'] ? 'Payment created successfully.' : 'Existing idempotent payment returned.'])
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function verify(Request $request, Payment $payment, PaymentVerificationService $verification): PaymentResource
    {
        return (new PaymentResource($verification->verify($payment, $request->user())))
            ->additional(['message' => 'Payment verified successfully.']);
    }

    public function cancel(CancelPaymentRequest $request, Payment $payment, PaymentService $payments): PaymentResource
    {
        return (new PaymentResource($payments->cancel($payment, $request->user(), $request->validated('remarks'))))
            ->additional(['message' => 'Payment cancelled successfully.']);
    }

    public function reverse(ReversePaymentRequest $request, Payment $payment, PaymentReversalService $reversal): PaymentResource
    {
        return (new PaymentResource($reversal->reverse($payment, $request->user(), $request->validated('reason'))))
            ->additional(['message' => 'Payment reversed successfully.']);
    }

    public function receipt(Payment $payment, ReceiptService $receipts): JsonResponse
    {
        return response()->json(['data' => $receipts->data($payment)]);
    }

    public function forAccount(Request $request, EmiAccount $emiAccount): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']])['per_page'] ?? 15;

        return PaymentResource::collection(
            $emiAccount->payments()->with(['emiAccount', 'customer', 'allocations.emiSchedule'])->latest('payment_date')->paginate($perPage),
        );
    }

    public function forCustomer(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']])['per_page'] ?? 15;

        return PaymentResource::collection(
            $customer->payments()->with(['emiAccount', 'customer', 'allocations.emiSchedule'])->latest('payment_date')->paginate($perPage),
        );
    }

    public function settlementPreview(
        SettlementPreviewRequest $request,
        EmiAccount $emiAccount,
        SettlementService $settlements,
    ): JsonResponse {
        return response()->json([
            'data' => $settlements->preview($emiAccount, $request->validated('discount_amount', '0'), $request->user()),
        ]);
    }

    public function settle(
        SettleEmiAccountRequest $request,
        EmiAccount $emiAccount,
        SettlementService $settlements,
    ): JsonResponse {
        return (new PaymentResource($settlements->settle($emiAccount, $request->validated(), $request->user())))
            ->additional(['message' => 'EMI account settled successfully.'])
            ->response()
            ->setStatusCode(201);
    }
}

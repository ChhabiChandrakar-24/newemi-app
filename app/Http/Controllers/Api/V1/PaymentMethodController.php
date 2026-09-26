<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = PaymentMethod::query()->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')->orderBy('name');

        return response()->json($query->paginate(min($request->integer('per_page', 50), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $method = PaymentMethod::create($request->validate($this->rules()));
        $this->audit->record('payment_method.created', $method, null, $method->toArray());

        return response()->json(['message' => 'Payment method created.', 'data' => $method], 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return response()->json(['data' => $paymentMethod]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $old = $paymentMethod->toArray();
        $paymentMethod->update($request->validate($this->rules($paymentMethod)));
        $this->audit->record('payment_method.updated', $paymentMethod, $old, $paymentMethod->fresh()->toArray());

        return response()->json(['message' => 'Payment method updated.', 'data' => $paymentMethod->fresh()]);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();
        $this->audit->record('payment_method.archived', $paymentMethod, $paymentMethod->toArray(), null);

        return response()->json(['message' => 'Payment method archived.']);
    }

    public function restore(int $paymentMethod): JsonResponse
    {
        $method = PaymentMethod::onlyTrashed()->findOrFail($paymentMethod);
        $method->restore();
        $this->audit->record('payment_method.restored', $method, null, $method->toArray());

        return response()->json(['message' => 'Payment method restored.', 'data' => $method]);
    }

    private function rules(?PaymentMethod $method = null): array
    {
        return ['code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('payment_methods')->where('company_id', auth()->user()->company_id)->ignore($method)],
            'name' => ['required', 'string', 'max:100'], 'is_enabled' => ['required', 'boolean'],
            'requires_reference' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }
}

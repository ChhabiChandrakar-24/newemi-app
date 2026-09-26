<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Services\CrmService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmLeadController extends Controller
{
    public function __construct(private readonly CrmService $crmService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CrmLead::query()->with(['assignedSalesPerson', 'convertedCustomer'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('shop_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('lead_code', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $leads = $query->paginate($perPage);

        // Calculate summary counters for the top cards
        $stats = [
            'total' => CrmLead::count(),
            'new' => CrmLead::where('status', 'new')->count(),
            'in_progress' => CrmLead::whereIn('status', ['contacted', 'visit_scheduled', 'visited', 'proposal_sent', 'negotiation'])->count(),
            'won' => CrmLead::where('status', 'won')->count(),
            'lost' => CrmLead::where('status', 'lost')->count(),
            'due_followups' => CrmLead::where('next_followup_at', '<=', now()->endOfDay())->whereNotIn('status', ['won', 'lost'])->count(),
        ];

        return response()->json([
            'data' => $leads->items(),
            'leads' => $leads,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customerName = $request->input('customer_name') ?? $request->input('name');
        $shopName = $request->input('shop_name') ?? $request->input('business_name');
        $estimatedBudget = $request->input('estimated_budget') ?? $request->input('estimated_value');
        $nextFollowup = $request->input('next_followup_at') ?? $request->input('follow_up_date');

        $request->merge([
            'customer_name' => $customerName,
            'shop_name' => $shopName,
            'estimated_budget' => $estimatedBudget,
            'next_followup_at' => $nextFollowup,
        ]);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'shop_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'lead_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'estimated_devices' => ['nullable', 'integer', 'min:0'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'next_followup_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $lead = CrmLead::create([
            ...$validated,
            'company_id' => $company->id,
            'lead_code' => $this->crmService->generateLeadCode(),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $loaded = $lead->load('assignedSalesPerson');

        return response()->json([
            'message' => 'Lead created successfully.',
            'data' => $loaded,
            'lead' => $loaded,
        ], 201);
    }

    public function show(CrmLead $lead): JsonResponse
    {
        return response()->json([
            'lead' => $lead->load(['assignedSalesPerson', 'convertedCustomer', 'visits.salesPerson', 'projects']),
        ]);
    }

    public function update(Request $request, CrmLead $lead): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['sometimes', 'required', 'string', 'max:120'],
            'shop_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'lead_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['new', 'contacted', 'visit_scheduled', 'visited', 'proposal_sent', 'negotiation', 'won', 'lost'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'estimated_devices' => ['nullable', 'integer', 'min:0'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'next_followup_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lost_reason' => ['nullable', 'string'],
        ]);

        $lead->update([
            ...$validated,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Lead updated successfully.',
            'lead' => $lead->fresh()->load('assignedSalesPerson'),
        ]);
    }

    public function convert(Request $request, CrmLead $lead): JsonResponse
    {
        $customer = $this->crmService->convertLeadToCustomer($lead, $request->user());

        return response()->json([
            'message' => 'Lead successfully converted to Customer account!',
            'customer' => $customer,
            'lead' => $lead->fresh()->load('convertedCustomer'),
        ]);
    }

    public function destroy(CrmLead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json(['message' => 'Lead archived successfully.']);
    }
}

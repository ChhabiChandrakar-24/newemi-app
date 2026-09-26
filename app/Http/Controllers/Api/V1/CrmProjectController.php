<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrmProject;
use App\Services\CrmService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmProjectController extends Controller
{
    public function __construct(private readonly CrmService $crmService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CrmProject::query()->with(['lead', 'assignedDeveloper'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('client_phone', 'like', "%{$search}%")
                    ->orWhere('project_code', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $projects = $query->paginate($perPage);

        $stats = [
            'total_projects' => CrmProject::count(),
            'in_progress' => CrmProject::where('status', 'in_progress')->count(),
            'delivered' => CrmProject::where('status', 'delivered')->count(),
            'total_project_value' => (float) CrmProject::sum('total_cost'),
            'total_collected' => (float) CrmProject::sum('paid_amount'),
            'total_pending' => (float) CrmProject::sum('balance_amount'),
        ];

        return response()->json([
            'data' => $projects->items(),
            'projects' => $projects,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $title = $request->input('title') ?? $request->input('name');
        $totalCost = $request->input('total_cost') ?? $request->input('total_budget');
        $paidAmount = $request->input('paid_amount') ?? $request->input('advance_paid') ?? 0;
        $deliveryDeadline = $request->input('delivery_deadline') ?? $request->input('expected_delivery_date');
        $description = $request->input('description') ?? $request->input('notes');

        $status = $request->input('status');
        $statusMap = [
            'requirements' => 'requirement_gathering',
            'design' => 'quoted',
            'development' => 'in_progress',
            'deployed' => 'delivered',
        ];
        if ($status && isset($statusMap[$status])) {
            $status = $statusMap[$status];
        }

        $request->merge([
            'title' => $title,
            'total_cost' => $totalCost,
            'paid_amount' => $paidAmount,
            'delivery_deadline' => $deliveryDeadline,
            'description' => $description,
            'status' => $status,
        ]);

        $validated = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:crm_leads,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => ['nullable', 'string', 'max:20'],
            'client_email' => ['nullable', 'email', 'max:120'],
            'title' => ['required', 'string', 'max:150'],
            'project_type' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'total_cost' => ['required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'delivery_deadline' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'milestones' => ['nullable', 'array'],
        ]);

        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $totalCost = (float) $validated['total_cost'];
        $paidAmount = (float) ($validated['paid_amount'] ?? 0);
        $balance = max(0, $totalCost - $paidAmount);

        $project = CrmProject::create([
            ...$validated,
            'company_id' => $company->id,
            'project_code' => $this->crmService->generateProjectCode(),
            'total_cost' => $totalCost,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balance,
            'status' => $validated['status'] ?? 'requirement_gathering',
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $loaded = $project->load(['lead', 'assignedDeveloper']);

        return response()->json([
            'message' => 'Client software development project created successfully.',
            'data' => $loaded,
            'project' => $loaded,
        ], 201);
    }

    public function show(CrmProject $project): JsonResponse
    {
        return response()->json([
            'project' => $project->load(['lead', 'assignedDeveloper']),
        ]);
    }

    public function update(Request $request, CrmProject $project): JsonResponse
    {
        $merge = [];
        if ($request->has('name') && !$request->has('title')) $merge['title'] = $request->input('name');
        if ($request->has('total_budget') && !$request->has('total_cost')) $merge['total_cost'] = $request->input('total_budget');
        if ($request->has('advance_paid') && !$request->has('paid_amount')) $merge['paid_amount'] = $request->input('advance_paid');
        if ($request->has('expected_delivery_date') && !$request->has('delivery_deadline')) $merge['delivery_deadline'] = $request->input('expected_delivery_date');
        if ($request->has('notes') && !$request->has('description')) $merge['description'] = $request->input('notes');

        if (!empty($merge)) {
            $request->merge($merge);
        }

        $validated = $request->validate([
            'client_name' => ['sometimes', 'required', 'string', 'max:120'],
            'client_phone' => ['nullable', 'string', 'max:20'],
            'client_email' => ['nullable', 'email', 'max:120'],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'project_type' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'total_cost' => ['sometimes', 'required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'delivery_deadline' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'milestones' => ['nullable', 'array'],
        ]);

        $totalCost = isset($validated['total_cost']) ? (float) $validated['total_cost'] : (float) $project->total_cost;
        $paidAmount = isset($validated['paid_amount']) ? (float) $validated['paid_amount'] : (float) $project->paid_amount;
        $balance = max(0, $totalCost - $paidAmount);

        $project->update([
            ...$validated,
            'total_cost' => $totalCost,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balance,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project->fresh()->load(['lead', 'assignedDeveloper']),
        ]);
    }

    public function addPayment(Request $request, CrmProject $project): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $newPaid = (float) $project->paid_amount + (float) $validated['amount'];
        $newBalance = max(0, (float) $project->total_cost - $newPaid);

        $project->update([
            'paid_amount' => $newPaid,
            'balance_amount' => $newBalance,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => "Payment of ₹{$validated['amount']} recorded successfully against {$project->project_code}.",
            'project' => $project->fresh()->load(['lead', 'assignedDeveloper']),
        ]);
    }

    public function destroy(CrmProject $project): JsonResponse
    {
        $project->delete();

        return response()->json(['message' => 'Project archived successfully.']);
    }
}

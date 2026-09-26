<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmVisit;
use App\Services\CrmService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmVisitController extends Controller
{
    public function __construct(private readonly CrmService $crmService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CrmVisit::query()->with(['salesPerson', 'lead'])->latest('visit_date');

        if ($request->filled('sales_person_id')) {
            $query->where('sales_person_id', $request->integer('sales_person_id'));
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->string('outcome'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('shop_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('location_address', 'like', "%{$search}%")
                    ->orWhere('visit_code', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $visits = $query->paginate($perPage);

        $stats = [
            'total_visits' => CrmVisit::count(),
            'today_visits' => CrmVisit::whereDate('visit_date', today())->count(),
            'deals_closed' => CrmVisit::where('outcome', 'deal_closed')->count(),
            'followup_needed' => CrmVisit::where('outcome', 'follow_up_needed')->count(),
        ];

        return response()->json([
            'data' => $visits->items(),
            'visits' => $visits,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:crm_leads,id'],
            'shop_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'visit_date' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'discussion_summary' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'accuracy_meters' => ['nullable', 'numeric'],
            'location_address' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'next_action' => ['nullable', 'string'],
            'next_followup_at' => ['nullable', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
            'photos' => ['nullable'],
            'photos.*' => ['nullable'],
        ]);

        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        // Extract and store photos
        $incomingPhotos = [];
        if ($request->hasFile('photos')) {
            $incomingPhotos = $request->file('photos');
        } elseif (is_array($request->input('photos'))) {
            $incomingPhotos = $request->input('photos');
        }

        $photoPaths = $this->crmService->storeVisitPhotos($incomingPhotos);

        $userId = $request->user()?->id ?? \App\Models\User::where('company_id', $company->id)->value('id') ?? 1;

        $visit = CrmVisit::create([
            'company_id' => $company->id,
            'lead_id' => $validated['lead_id'] ?? null,
            'visit_code' => $this->crmService->generateVisitCode(),
            'sales_person_id' => $userId,
            'shop_name' => $validated['shop_name'],
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'visit_date' => $validated['visit_date'] ?? now(),
            'purpose' => $validated['purpose'] ?? 'introductory',
            'outcome' => $validated['outcome'] ?? 'positive',
            'notes' => $validated['notes'] ?? $validated['discussion_summary'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'accuracy_meters' => $validated['accuracy_meters'] ?? null,
            'location_address' => $validated['location_address'] ?? $validated['address'] ?? null,
            'photos' => $photoPaths,
            'next_action' => $validated['next_action'] ?? null,
            'next_followup_at' => $validated['next_followup_at'] ?? $validated['next_follow_up_date'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        // Sync lead stage if associated
        if (!empty($validated['lead_id'])) {
            $lead = CrmLead::find($validated['lead_id']);
            if ($lead) {
                $newStatus = ($validated['outcome'] === 'deal_closed') ? 'won' : 'visited';
                $lead->update([
                    'status' => $newStatus,
                    'next_followup_at' => $validated['next_followup_at'] ?? $validated['next_follow_up_date'] ?? $lead->next_followup_at,
                    'updated_by' => $userId,
                ]);
            }
        }

        $loaded = $visit->load(['salesPerson', 'lead']);

        return response()->json([
            'message' => 'Field shop visit recorded with live geo-coordinates and photos.',
            'data' => $loaded,
            'visit' => $loaded,
            'visit_code' => $visit->visit_code,
        ], 201);
    }

    public function show(CrmVisit $visit): JsonResponse
    {
        return response()->json([
            'visit' => $visit->load(['salesPerson', 'lead']),
        ]);
    }

    public function destroy(CrmVisit $visit): JsonResponse
    {
        $visit->delete();

        return response()->json(['message' => 'Visit record deleted successfully.']);
    }
}

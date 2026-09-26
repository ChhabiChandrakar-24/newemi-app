<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptDeviceConsentRequest;
use App\Http\Requests\Api\V1\WithdrawDeviceConsentRequest;
use App\Http\Resources\Api\V1\DeviceConsentResource;
use App\Models\Company;
use App\Models\DeviceConsent;
use App\Models\DeviceEnrollment;
use App\Services\DeviceConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceConsentController extends Controller
{
    public function __construct(private readonly DeviceConsentService $consents) {}

    /**
     * Contract summary shown on the device before consent can be accepted.
     *
     * The company is resolved from the enrollment token when one is supplied;
     * otherwise the first configured company is used (single-tenant deployable).
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_token' => ['nullable', 'string'],
        ]);

        $company = $this->resolveCompany($validated['enrollment_token'] ?? null);
        if (! $company) {
            return response()->json(['message' => 'No company is configured for consent preview.'], 404);
        }

        $token = $validated['enrollment_token'] ?? null;
        $preview = $this->consents->preview($company, $token);
        $preview['consent_required'] = (bool) config('devices.consent.required');

        return response()->json(['data' => $preview]);
    }

    /** Records explicit customer consent bound to a pending enrollment token. */
    public function accept(AcceptDeviceConsentRequest $request): JsonResponse
    {
        if ($request->boolean('preview_only') || $request->boolean('preview')) {
            return $this->preview($request);
        }

        $consent = $this->consents->acceptWithToken(
            $request->validated('enrollment_token'),
            $request->safe()->except('enrollment_token'),
            $request->ip(),
        );

        return (new DeviceConsentResource($consent->load('device', 'customer')))
            ->additional(['message' => 'Consent recorded. You can now complete device enrollment.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Device-initiated withdrawal: disables remote management and revokes credentials. */
    public function withdraw(WithdrawDeviceConsentRequest $request): DeviceConsentResource
    {
        $device = $request->attributes->get('device');
        $consent = $this->consents->withdraw(
            $device,
            null,
            $request->validated('reason') ?? 'customer_request',
        );

        return (new DeviceConsentResource($consent->load('device', 'customer')))
            ->additional(['message' => 'Consent withdrawn. Remote management has been disabled.']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'consent_status' => ['nullable', 'in:accepted,withdrawn'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consents = DeviceConsent::query()
            ->with('device', 'customer')
            ->when($validated['device_id'] ?? null, fn ($query, int $deviceId) => $query->where('device_id', $deviceId))
            ->when($validated['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($validated['consent_status'] ?? null, fn ($query, string $status) => $query->where('consent_status', $status))
            ->when($validated['from'] ?? null, fn ($query, string $date) => $query->where('consent_timestamp', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, string $date) => $query->where('consent_timestamp', '<=', $date))
            ->latest('consent_timestamp')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return DeviceConsentResource::collection($consents);
    }

    public function show(DeviceConsent $consent): DeviceConsentResource
    {
        return new DeviceConsentResource($consent->load('device', 'customer'));
    }

    private function resolveCompany(?string $token): ?Company
    {
        if (is_string($token) && $token !== '') {
            $enrollment = DeviceEnrollment::withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $token))
                ->first(['company_id']);
            if ($enrollment) {
                return Company::query()->find($enrollment->company_id);
            }
        }

        return Company::query()->orderBy('id')->first();
    }
}
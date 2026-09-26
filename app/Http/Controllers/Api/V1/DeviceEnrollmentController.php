<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClaimDeviceEnrollmentRequest;
use App\Http\Requests\Api\V1\CreateEnrollmentTokenRequest;
use App\Http\Requests\Api\V1\ReEnrollDeviceRequest;
use App\Http\Resources\Api\V1\DeviceEnrollmentResource;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\Device;
use App\Models\DeviceEnrollment;
use App\Services\DeviceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceEnrollmentController extends Controller
{
    public function __construct(private readonly DeviceEnrollmentService $enrollments) {}

    /** Device-facing: requests a short-lived re-enrollment token for a supported recovery flow. */
    public function reEnroll(ReEnrollDeviceRequest $request): JsonResponse
    {
        $result = $this->enrollments->createReEnrollToken(
            $request->attributes->get('device'),
            $request->validated('reason'),
        );

        return response()->json([
            'data' => (new DeviceEnrollmentResource($result['enrollment']))->resolve($request),
            'enrollment_token' => $result['token'],
            'message' => 'Re-enrollment token issued. Use it to restore management on this device.',
        ], 201);
    }

    public function index(Device $device): AnonymousResourceCollection
    {
        return DeviceEnrollmentResource::collection($device->enrollments()->latest()->paginate(25));
    }

    public function show(DeviceEnrollment $enrollment): DeviceEnrollmentResource
    {
        return new DeviceEnrollmentResource($enrollment);
    }

    public function createToken(CreateEnrollmentTokenRequest $request, Device $device, DeviceEnrollmentService $service): JsonResponse
    {
        $company = app(\App\Support\TenantContext::class)->company();
        if ($company?->expires_at && $company->expires_at->isPast()) {
            return response()->json(['message' => 'Your company subscription has expired. Please recharge your plan to generate enrollment tokens.'], 403);
        }

        $result = $service->createToken($device, $request->user());

        return response()->json(['data' => (new DeviceEnrollmentResource($result['enrollment']))->resolve($request), 'enrollment_token' => $result['token']], 201);
    }

    public function revoke(CreateEnrollmentTokenRequest $request, DeviceEnrollment $enrollment, DeviceEnrollmentService $service): DeviceEnrollmentResource
    {
        return (new DeviceEnrollmentResource($service->revoke($enrollment, $request->user())))->additional(['message' => 'Enrollment token revoked.']);
    }

    public function claim(ClaimDeviceEnrollmentRequest $request, DeviceEnrollmentService $service): JsonResponse
    {
        $result = $service->claim($request->validated('enrollment_token'), $request->safe()->except('enrollment_token'));

        return response()->json(['data' => ['device' => (new DeviceResource($result['device']))->resolve($request), 'device_credential' => $result['credential'], 'token_type' => 'Bearer']], 201);
    }

    public function latestForDevice(Request $request, Device $device): JsonResponse
    {
        $enrollment = $device->enrollments()->latest()->first();

        return response()->json([
            'data' => $enrollment ? (new DeviceEnrollmentResource($enrollment))->resolve($request) : null,
        ]);
    }
}

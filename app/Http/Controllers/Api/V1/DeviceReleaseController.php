<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceReleaseResource;
use App\Models\DeviceRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceReleaseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'emi_account_id' => ['nullable', 'integer'],
            'release_reason' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $releases = DeviceRelease::query()
            ->with(['device', 'emiAccount', 'customer', 'releasedByUser'])
            ->when($validated['device_id'] ?? null, fn ($query, int $deviceId) => $query->where('device_id', $deviceId))
            ->when($validated['emi_account_id'] ?? null, fn ($query, int $accountId) => $query->where('emi_account_id', $accountId))
            ->when($validated['release_reason'] ?? null, fn ($query, string $reason) => $query->where('release_reason', $reason))
            ->when($validated['from'] ?? null, fn ($query, string $date) => $query->where('release_timestamp', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, string $date) => $query->where('release_timestamp', '<=', $date))
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('release_reason', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('device', fn ($dq) => $dq->where('device_code', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhere('imei1', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($cq) => $cq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%"));
                });
            })
            ->latest('release_timestamp')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return DeviceReleaseResource::collection($releases);
    }

    public function show(DeviceRelease $release): DeviceReleaseResource
    {
        return new DeviceReleaseResource($release->load('device', 'emiAccount', 'customer', 'releasedByUser'));
    }
}
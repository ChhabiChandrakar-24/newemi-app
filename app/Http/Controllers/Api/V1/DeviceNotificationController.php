<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceNotificationResource;
use App\Models\DeviceNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeviceNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'notification_type' => ['nullable', 'string', 'max:64'],
            'recipient_type' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', Rule::in([DeviceNotification::CHANNEL_PUSH, DeviceNotification::CHANNEL_IN_APP, DeviceNotification::CHANNEL_SYSTEM])],
            'delivery_status' => ['nullable', Rule::in([DeviceNotification::QUEUED, DeviceNotification::SENT, DeviceNotification::FAILED, DeviceNotification::SKIPPED])],
            'delivered' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = DeviceNotification::query()
            ->with('device', 'customer', 'emiAccount')
            ->when($validated['notification_type'] ?? null, fn ($query, string $type) => $query->where('notification_type', $type))
            ->when($validated['recipient_type'] ?? null, fn ($query, string $recipient) => $query->where('recipient_type', $recipient))
            ->when($validated['channel'] ?? null, fn ($query, string $channel) => $query->where('channel', $channel))
            ->when($validated['delivery_status'] ?? null, fn ($query, string $status) => $query->where('delivery_status', $status))
            ->when(array_key_exists('delivered', $validated), fn ($query) => $request->boolean('delivered') ? $query->whereNotNull('delivered_at') : $query->whereNull('delivered_at'))
            ->when($validated['from'] ?? null, fn ($query, string $date) => $query->where('created_at', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, string $date) => $query->where('created_at', '<=', $date))
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return DeviceNotificationResource::collection($notifications);
    }

    public function show(DeviceNotification $notification): DeviceNotificationResource
    {
        return new DeviceNotificationResource($notification->load('device', 'customer', 'emiAccount'));
    }
}
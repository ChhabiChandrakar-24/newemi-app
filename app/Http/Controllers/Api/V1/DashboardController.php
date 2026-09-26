<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DataRetentionPolicy;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceConsent;
use App\Models\DeviceEvent;
use App\Models\DeviceNotification;
use App\Models\EmiAccount;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        return response()->json(['data' => [
            'customers' => ['total' => Customer::count(), 'active' => Customer::where('status', 'active')->count()],
            'emi' => ['total' => EmiAccount::count(), 'active' => EmiAccount::where('status', 'active')->count(), 'overdue' => EmiAccount::where('overdue_amount', '>', 0)->count(), 'overdue_amount' => (float) EmiAccount::sum('overdue_amount')],
            'payments' => ['today' => (float) Payment::where('created_at', '>=', $today)->where('status', 'verified')->sum('amount'), 'month' => (float) Payment::where('created_at', '>=', $month)->where('status', 'verified')->sum('amount')],
            'devices' => ['total' => Device::count(), 'enrolled' => Device::where('enrollment_status', 'enrolled')->count(), 'online' => Device::where('connectivity_status', 'online')->count(), 'offline' => Device::where('connectivity_status', 'offline')->count(), 'by_control_status' => Device::selectRaw('control_status, count(*) aggregate')->groupBy('control_status')->pluck('aggregate', 'control_status')],
            'commands' => ['pending' => DeviceCommand::whereIn('status', ['queued', 'dispatched', 'received'])->count(), 'failed' => DeviceCommand::where('status', 'failed')->count()],
            'alerts' => ['critical' => DeviceEvent::where('severity', 'critical')->count(), 'unacknowledged' => DeviceEvent::whereNull('acknowledged_at')->count()],
            'emi_lifecycle' => ['by_status' => EmiAccount::selectRaw('emi_status, count(*) aggregate')->groupBy('emi_status')->pluck('aggregate', 'emi_status'), 'active' => EmiAccount::whereIn('status', ['active', 'overdue'])->count(), 'completed' => EmiAccount::where('status', 'completed')->count()],
            'device_management' => ['by_management_status' => Device::selectRaw('management_status, count(*) aggregate')->groupBy('management_status')->pluck('aggregate', 'management_status'), 'consents_accepted' => DeviceConsent::where('consent_status', 'accepted')->count(), 'consents_withdrawn' => DeviceConsent::where('consent_status', 'withdrawn')->count(), 'consent_required' => (bool) config('devices.consent.required'), 'retention_enabled' => (bool) config('devices.retention.apply_enabled'), 'retention_policies_active' => DataRetentionPolicy::where('is_active', true)->count()],
            'notifications' => ['queued' => DeviceNotification::where('delivery_status', 'queued')->count(), 'sent' => DeviceNotification::where('delivery_status', 'sent')->count(), 'failed' => DeviceNotification::where('delivery_status', 'failed')->count()],
        ]]);
    }
}

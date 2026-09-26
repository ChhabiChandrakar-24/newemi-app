<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformWebhookLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $event = $request->string('event')->trim()->value() ?: null;
        $status = $request->string('status')->trim()->value() ?: null;
        $search = $request->string('search')->trim()->value() ?: null;

        $query = PaymentWebhookLog::query();

        if ($event) {
            $query->where('event', $event);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('event', 'like', "%{$search}%")
                    ->orWhere('payment_id', 'like', "%{$search}%")
                    ->orWhere('order_id', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        $logs = $query->latest('id')->paginate($perPage);

        $stats = [
            'total' => PaymentWebhookLog::count(),
            'processed' => PaymentWebhookLog::where('status', 'processed')->count(),
            'failed' => PaymentWebhookLog::where('status', 'failed')->count(),
            'refunds' => PaymentWebhookLog::where('event', 'like', '%refund%')->count(),
            'payments' => PaymentWebhookLog::where('event', 'like', '%payment%')->count(),
        ];

        return response()->json([
            'logs' => $logs,
            'stats' => $stats,
        ]);
    }

    public function show(PaymentWebhookLog $webhookLog): JsonResponse
    {
        return response()->json([
            'webhook_log' => $webhookLog,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmVisit;
use App\Models\Customer;
use App\Models\EmiAccount;
use App\Services\Messaging\SmsGatewayManager;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CrmMessageController extends Controller
{
    public function __construct(private readonly SmsGatewayManager $smsGateway) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $messages = CrmMessage::query()->with('sender')->latest('id')->paginate($perPage);

        $stats = [
            'total_campaigns' => CrmMessage::count(),
            'total_dispatched' => (int) CrmMessage::sum('recipient_count'),
            'fast2sms_available' => filled(env('FAST2SMS_API_KEY')),
        ];

        return response()->json([
            'data' => $messages->items(),
            'messages' => $messages,
            'stats' => $stats,
        ]);
    }

    public function audiences(): JsonResponse
    {
        $leads = CrmLead::query()
            ->select(['id', 'customer_name', 'shop_name', 'phone', 'email'])
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->latest('id')
            ->get()
            ->map(fn ($l) => [
                'id' => 'lead_' . $l->id,
                'raw_id' => $l->id,
                'name' => $l->customer_name ?: 'Lead #' . $l->id,
                'shop_name' => $l->shop_name ?? 'Retail Shop',
                'phone' => $l->phone,
                'type' => 'lead',
                'group' => 'Shop Leads',
            ]);

        $customers = Customer::query()
            ->select(['id', 'full_name', 'mobile_number', 'email'])
            ->whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '')
            ->latest('id')
            ->get()
            ->map(fn ($c) => [
                'id' => 'cust_' . $c->id,
                'raw_id' => $c->id,
                'name' => $c->full_name ?: 'Customer #' . $c->id,
                'shop_name' => '',
                'phone' => $c->mobile_number,
                'type' => 'customer',
                'group' => 'EMI Customers',
            ]);

        $overdueAccounts = EmiAccount::query()
            ->where('emi_status', 'overdue')
            ->with('customer')
            ->latest('id')
            ->get()
            ->map(fn ($a) => [
                'id' => 'overdue_' . $a->id,
                'raw_id' => $a->id,
                'name' => $a->customer?->full_name ?? 'Valued Customer',
                'phone' => $a->customer?->mobile_number,
                'type' => 'overdue',
                'group' => 'Overdue EMI',
                'amount' => (float) $a->outstanding_amount,
                'due_date' => $a->next_due_date ? $a->next_due_date->format('Y-m-d') : 'Immediate',
            ])
            ->filter(fn ($x) => filled($x['phone']))
            ->values();

        $hasFast2SmsKey = filled($this->smsGateway->resolveApiKey());

        return response()->json([
            'has_fast2sms_key' => $hasFast2SmsKey,
            'leads_count' => $leads->count(),
            'customers_count' => $customers->count(),
            'overdue_emis_count' => $overdueAccounts->count(),
            'lead_samples' => $leads->take(5)->map(fn ($l) => [
                'id' => $l['raw_id'],
                'name' => $l['name'],
                'phone' => $l['phone'],
                'business_name' => $l['shop_name'],
            ])->values(),
            'customer_samples' => $customers->take(5)->map(fn ($c) => [
                'id' => $c['raw_id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
            ])->values(),
            'overdue_samples' => $overdueAccounts->take(5)->map(fn ($o) => [
                'id' => $o['raw_id'],
                'customer_name' => $o['name'],
                'phone' => $o['phone'],
                'amount' => $o['amount'],
                'due_date' => $o['due_date'],
            ])->values(),
            'leads' => $leads,
            'customers' => $customers,
            'overdue' => $overdueAccounts,
            'saved_gateway_url' => \App\Models\SystemSetting::where('category', 'sms')->where('key', 'gateway_url')->value('value'),
            'has_textlocal_key' => filled(\App\Models\SystemSetting::where('category', 'sms')->where('key', 'textlocal_key')->value('value')),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $messageBody = $request->input('message_body') ?? $request->input('template_body');
        if (blank($messageBody)) {
            return response()->json([
                'message' => 'The message content field is required.',
                'errors' => ['message_body' => ['The message content field is required.']],
            ], 422);
        }

        $recipientType = $request->input('recipient_type', 'custom');
        $channel = $request->input('channel', 'sms');
        $provider = $request->input('gateway_provider') ?? $request->input('gateway') ?? 'simulation';

        $recipients = [];

        // 1. Explicit recipients or custom_numbers passed
        $rawRecipients = $request->input('recipients') ?? $request->input('custom_numbers');
        if (is_string($rawRecipients)) {
            $rawRecipients = preg_split('/[\r\n,]+/', $rawRecipients);
        }
        if (is_array($rawRecipients)) {
            foreach ($rawRecipients as $r) {
                if (filled($r)) {
                    $recipients[] = trim((string) $r);
                }
            }
        }

        // 2. If single individual phone passed
        if (empty($recipients) || $recipientType === 'individual') {
            $individualPhone = $request->input('individual_phone') ?? $request->input('phone');
            if (filled($individualPhone)) {
                $recipients = [trim((string) $individualPhone)];
            }
        }

        // 3. Fallback resolution if recipients still empty based on recipient_type
        if (empty($recipients)) {
            if ($recipientType === 'leads') {
                $recipients = CrmLead::query()
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->pluck('phone')
                    ->all();
            } elseif ($recipientType === 'customers') {
                $recipients = Customer::query()
                    ->whereNotNull('mobile_number')
                    ->where('mobile_number', '!=', '')
                    ->pluck('mobile_number')
                    ->all();
            } elseif ($recipientType === 'overdue_emis') {
                $recipients = EmiAccount::query()
                    ->where('emi_status', 'overdue')
                    ->with('customer')
                    ->get()
                    ->pluck('customer.mobile_number')
                    ->filter()
                    ->all();
            }
        }

        // Clean & unique numbers
        $cleanRecipients = array_values(array_unique(array_filter(array_map(function ($num) {
            $n = preg_replace('/[^0-9]/', '', (string) $num);
            if (strlen($n) > 10 && str_starts_with($n, '91')) {
                $n = substr($n, 2);
            }
            return strlen($n) >= 10 ? substr($n, -10) : (strlen($n) > 0 ? $n : null);
        }, $recipients))));

        if (empty($cleanRecipients)) {
            return response()->json([
                'message' => 'No valid recipient phone numbers found. Please select an audience, choose an individual contact, or enter a mobile number.',
                'errors' => ['recipients' => ['The recipients field is required.']],
            ], 422);
        }

        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $config = [
            'gateway_url' => $request->input('gateway_url'),
            'route' => $request->input('route', 'q'),
            'sender_id' => $request->input('sender_id'),
            'textlocal_api_key' => $request->input('textlocal_api_key'),
            'account_sid' => $request->input('account_sid'),
            'auth_token' => $request->input('auth_token'),
            'from_number' => $request->input('from_number'),
        ];

        $apiKey = $request->input('api_key') ?? $request->input('fast2sms_api_key');
        if (filled($apiKey)) {
            $config['api_key'] = trim((string) $apiKey);
        }

        if ($request->boolean('save_api_key')) {
            try {
                if (filled($apiKey)) {
                    \App\Models\SystemSetting::updateOrCreate(
                        ['category' => 'sms', 'key' => 'api_key'],
                        ['value' => \Illuminate\Support\Facades\Crypt::encryptString(trim((string) $apiKey)), 'is_secret' => true, 'updated_by' => $request->user()?->id]
                    );
                }
                if ($request->filled('gateway_url')) {
                    \App\Models\SystemSetting::updateOrCreate(
                        ['category' => 'sms', 'key' => 'gateway_url'],
                        ['value' => trim((string) $request->input('gateway_url')), 'is_secret' => false, 'updated_by' => $request->user()?->id]
                    );
                }
                if ($request->filled('textlocal_api_key')) {
                    \App\Models\SystemSetting::updateOrCreate(
                        ['category' => 'sms', 'key' => 'textlocal_key'],
                        ['value' => \Illuminate\Support\Facades\Crypt::encryptString(trim((string) $request->input('textlocal_api_key'))), 'is_secret' => true, 'updated_by' => $request->user()?->id]
                    );
                }
                \App\Models\SystemSetting::updateOrCreate(
                    ['category' => 'sms', 'key' => 'provider'],
                    ['value' => json_encode($provider), 'is_secret' => false, 'updated_by' => $request->user()?->id]
                );
                \App\Models\SystemSetting::updateOrCreate(
                    ['category' => 'sms', 'key' => 'enabled'],
                    ['value' => json_encode(true), 'is_secret' => false, 'updated_by' => $request->user()?->id]
                );
            } catch (\Throwable) {}
        }

        // Dispatch via gateway manager
        $result = $this->smsGateway->send($cleanRecipients, $messageBody, $provider, $config);

        $recipientName = $request->input('recipient_name');
        $defaultCampaignName = $recipientType === 'individual'
            ? ('Direct to ' . ($recipientName ?: $cleanRecipients[0]))
            : ('Campaign ' . date('d M H:i'));

        $campaignName = $request->input('campaign_name') ?: $defaultCampaignName;

        // Record message log
        $message = CrmMessage::create([
            'company_id' => $company->id,
            'campaign_name' => $campaignName,
            'channel' => $channel,
            'gateway_provider' => $result['provider'] ?? $provider,
            'recipient_count' => count($cleanRecipients),
            'recipients' => $cleanRecipients,
            'message_body' => $messageBody,
            'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
            'response_payload' => array_merge($result, [
                'recipient_type' => $recipientType,
                'recipient_name' => $recipientName,
                'sent_count' => ($result['success'] ?? false) ? count($cleanRecipients) : 0,
                'failed_count' => ($result['success'] ?? false) ? 0 : count($cleanRecipients),
            ]),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'Messages processed successfully.',
            'record' => $message->load('sender'),
            'recharge_required' => $result['recharge_required'] ?? false,
            'recharge_url' => $result['recharge_url'] ?? null,
            'links' => $result['links'] ?? null,
            'primary_link' => $result['primary_link'] ?? null,
            'result' => array_merge($result, [
                'sent_count' => ($result['success'] ?? false) ? count($cleanRecipients) : 0,
                'failed_count' => ($result['success'] ?? false) ? 0 : count($cleanRecipients),
                'gateway' => $result['provider'] ?? $provider,
            ]),
        ], 200);
    }

    public function destroy(CrmMessage $crmMessage): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $crmMessage->company_id !== $company->id, 403, 'Unauthorized.');

        $crmMessage->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message log deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $ids = $request->input('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['message' => 'No message log IDs provided.'], 422);
        }

        $deleted = CrmMessage::where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted {$deleted} message log(s).",
            'deleted_count' => $deleted,
        ]);
    }

    public function testGateway(Request $request): JsonResponse
    {
        $gatewayUrl = $request->input('gateway_url');
        if (blank($gatewayUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an Android Gateway URL to test (e.g. http://192.168.1.15:8080/send)',
            ], 422);
        }

        try {
            $testPhone = $request->input('phone', '9981887943');
            $response = Http::withoutVerifying()
                ->timeout(5)
                ->post($gatewayUrl, [
                    'to' => $testPhone,
                    'number' => $testPhone,
                    'message' => 'PayGuard EMI Control Gateway connection test successful.',
                    'text' => 'PayGuard EMI Control Gateway connection test successful.',
                ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connected successfully! Android phone responded with HTTP 200.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gateway URL responded with HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 120),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not connect to Android Phone Gateway (' . $e->getMessage() . '). Ensure phone and PC are connected to the same Wi-Fi or Mobile Hotspot.',
            ]);
        }
    }
}


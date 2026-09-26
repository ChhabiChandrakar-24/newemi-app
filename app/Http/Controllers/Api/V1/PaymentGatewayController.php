<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Services\AuditService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentGatewayController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $manager, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = PaymentGateway::query()->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($sub) => $sub->where('display_name', 'like', '%'.$request->string('search').'%')->orWhere('provider', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->when($request->filled('enabled'), fn ($q) => $q->where('is_enabled', $request->boolean('enabled')))
            ->latest();

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100))->through(fn (PaymentGateway $gateway) => $gateway->safePayload()));
    }

    public function providers(): JsonResponse
    {
        return response()->json(['data' => collect(PaymentGateway::PROVIDERS)->map(fn ($provider) => ['provider' => $provider, 'fields' => $this->manager->schema($provider)])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $gateway = DB::transaction(function () use ($request, $data): PaymentGateway {
            if ($data['is_default'] ?? false) {
                PaymentGateway::query()->update(['is_default' => false]);
            }
            $gateway = PaymentGateway::create($this->writePayload($data) + [
                'webhook_key' => (string) Str::uuid(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->audit->record('payment_gateway.created', $gateway, null, $gateway->safePayload());

            return $gateway;
        });

        return response()->json(['message' => 'Payment gateway created.', 'data' => $gateway->safePayload()], 201);
    }

    public function show(PaymentGateway $gateway): JsonResponse
    {
        return response()->json(['data' => $gateway->safePayload() + ['provider_fields' => $this->manager->schema($gateway->provider)]]);
    }

    public function update(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate($this->rules($gateway));
        $old = $gateway->safePayload();
        DB::transaction(function () use ($request, $gateway, $data): void {
            if ($data['is_default'] ?? false) {
                PaymentGateway::whereKeyNot($gateway->id)->update(['is_default' => false]);
            }
            $gateway->update($this->writePayload($data, $gateway) + ['updated_by' => $request->user()->id]);
        });
        $this->audit->record('payment_gateway.updated', $gateway, $old, $gateway->fresh()->safePayload());

        return response()->json(['message' => 'Payment gateway updated.', 'data' => $gateway->fresh()->safePayload()]);
    }

    public function status(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $enabled = $request->validate(['is_enabled' => ['required', 'boolean']])['is_enabled'];
        $old = $gateway->safePayload();
        $gateway->update(['is_enabled' => $enabled, 'status' => $enabled ? 'configured' : 'disabled', 'updated_by' => $request->user()->id]);
        if (! $enabled && $gateway->is_default) {
            $gateway->update(['is_default' => false]);
        }
        $this->audit->record('payment_gateway.status_changed', $gateway, $old, $gateway->fresh()->safePayload());

        return response()->json(['message' => 'Gateway status updated.', 'data' => $gateway->fresh()->safePayload()]);
    }

    public function setDefault(Request $request, PaymentGateway $gateway): JsonResponse
    {
        abort_unless($gateway->is_enabled, 422, 'Only an enabled gateway can be the default.');
        DB::transaction(function () use ($gateway): void {
            PaymentGateway::query()->update(['is_default' => false]);
            $gateway->update(['is_default' => true]);
        });
        $this->audit->record('payment_gateway.default_changed', $gateway, null, ['is_default' => true]);

        return response()->json(['message' => 'Default gateway updated.', 'data' => $gateway->fresh()->safePayload()]);
    }

    public function test(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $result = $this->manager->provider($gateway->provider)->testConnection($gateway);
        $gateway->update(['last_tested_at' => now(), 'last_test_status' => $result['status'], 'updated_by' => $request->user()->id]);
        $this->audit->record('payment_gateway.tested', $gateway, null, ['status' => $result['status']]);

        return response()->json(['data' => $result]);
    }

    public function destroy(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $old = $gateway->safePayload();
        $gateway->update(['is_enabled' => false, 'is_default' => false, 'updated_by' => $request->user()->id]);
        $gateway->delete();
        $this->audit->record('payment_gateway.archived', $gateway, $old, null);

        return response()->json(['message' => 'Payment gateway archived.']);
    }

    public function restore(Request $request, int $gateway): JsonResponse
    {
        $model = PaymentGateway::onlyTrashed()->findOrFail($gateway);
        $model->restore();
        $model->update(['is_enabled' => false, 'is_default' => false, 'status' => 'disabled', 'updated_by' => $request->user()->id]);
        $this->audit->record('payment_gateway.restored', $model, null, $model->safePayload());

        return response()->json(['message' => 'Payment gateway restored disabled.', 'data' => $model->safePayload()]);
    }

    private function rules(?PaymentGateway $gateway = null): array
    {
        return [
            'provider' => ['required', Rule::in(PaymentGateway::PROVIDERS)], 'display_name' => ['required', 'string', 'max:255'],
            'environment' => ['required', Rule::in(['test', 'live'])], 'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'], 'public_key' => ['nullable', 'string', 'max:2000'],
            'secret' => [$gateway ? 'nullable' : 'required', 'string', 'max:10000'], 'webhook_secret' => ['nullable', 'string', 'max:10000'],
            'remove_secret' => ['sometimes', 'boolean'], 'remove_webhook_secret' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'], 'config.base_url' => ['nullable', 'url:https', 'max:1000'],
            'config.headers' => ['nullable', 'array', 'max:10'], 'config.headers.*' => ['string', 'max:500'],
        ];
    }

    private function writePayload(array $data, ?PaymentGateway $gateway = null): array
    {
        $payload = collect($data)->only(['provider', 'display_name', 'environment', 'is_enabled', 'is_default', 'public_key', 'config'])->all();
        if (filled($data['secret'] ?? null)) {
            $payload['encrypted_secret'] = $data['secret'];
        } elseif ($data['remove_secret'] ?? false) {
            $payload['encrypted_secret'] = null;
        }
        if (filled($data['webhook_secret'] ?? null)) {
            $payload['encrypted_webhook_secret'] = $data['webhook_secret'];
        } elseif ($data['remove_webhook_secret'] ?? false) {
            $payload['encrypted_webhook_secret'] = null;
        }
        $payload['status'] = filled($payload['encrypted_secret'] ?? $gateway?->encrypted_secret) ? (($payload['is_enabled'] ?? $gateway?->is_enabled) ? 'configured' : 'disabled') : 'not_configured';

        return $payload;
    }
}

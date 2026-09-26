<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const CATEGORIES = ['company', 'location', 'security', 'notifications', 'firebase', 'email', 'sms', 'maps', 'payment', 'webhooks'];

    public function __construct(private readonly SettingsService $settings) {}

    public function show(string $category): JsonResponse
    {
        $this->validateCategory($category);

        return response()->json(['data' => $this->settings->category($category)]);
    }

    public function update(UpdateSettingsRequest $request, string $category): JsonResponse
    {
        $this->validateCategory($category);
        abort_if($category === 'notifications', 403, 'Notification settings are managed by the system and are read-only.');
        if ($category === 'security') {
            abort_unless($request->user()->hasAnyRole(['super-admin', 'admin']), 403, 'Only an administrator may change security settings.');
        }
        $data = $this->settings->update($category, $request->validated('values'), $request->user(), $request->validated('remove_secrets', []));

        if ($category === 'company' && $company = $request->user()->company) {
            $vals = $request->validated('values');
            $company->update(array_filter([
                'name' => $vals['company_name'] ?? null,
                'legal_name' => $vals['legal_name'] ?? null,
                'support_phone' => $vals['support_phone'] ?? null,
                'support_email' => $vals['support_email'] ?? null,
                'address_line_1' => $vals['address'] ?? null,
                'city' => $vals['city'] ?? null,
                'state' => $vals['state'] ?? null,
                'postal_code' => $vals['postal_code'] ?? null,
                'currency' => $vals['currency'] ?? null,
                'timezone' => $vals['timezone'] ?? null,
            ], fn ($v) => $v !== null));
        }

        AuditLog::query()->create(['actor_user_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'actor_email' => $request->user()->email, 'action' => 'settings.updated', 'entity_type' => 'settings', 'entity_id' => $category, 'new_values' => ['keys' => array_keys($request->validated('values')), 'secrets_changed' => array_values(array_intersect(array_keys($request->validated('values')), SettingsService::SECRET_KEYS))], 'remarks' => 'Settings updated; secret values omitted.', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['data' => $data, 'message' => 'Settings updated successfully.']);
    }

    public function integrations(): JsonResponse
    {
        return response()->json(['data' => collect(['firebase', 'email', 'sms', 'maps', 'payment', 'webhooks'])->mapWithKeys(fn ($p) => [$p => $this->safeStatus($p)])]);
    }

    public function setupStatus(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $completed = SystemSetting::query()->where(['category' => 'installation', 'key' => 'completed_at'])->exists();

        return response()->json(['data' => ['completed' => $completed, 'checks' => ['app_key' => filled(config('app.key')), 'database' => true, 'storage_writable' => is_writable(storage_path()), 'company_configured' => SystemSetting::where('category', 'company')->exists(), 'firebase_configured' => SystemSetting::where('category', 'firebase')->whereNotNull('value')->exists(), 'frontend_build' => file_exists(base_path('../frontend/dist/index.html'))]]]);
    }

    public function setupComplete(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        SystemSetting::query()->updateOrCreate(['category' => 'installation', 'key' => 'completed_at'], ['value' => json_encode(now()->toISOString()), 'is_secret' => false, 'updated_by' => $request->user()->id]);

        return response()->json(['message' => 'Installation marked complete. The setup wizard is now locked.']);
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->can('settings.integrations.update'), 403);
        abort_unless(in_array($provider, ['firebase', 'email', 'sms', 'maps', 'payment'], true), 404);
        $status = $this->safeStatus($provider);
        abort_unless($status['configured'], 422, 'Integration is not configured.');

        return response()->json(['data' => ['provider' => $provider, 'success' => true, 'message' => 'Configuration is present. A live provider request is not performed by this safe validation test.']]);
    }

    private function safeStatus(string $provider): array
    {
        $data = $this->settings->category($provider);

        return ['configured' => collect($data)->contains(fn ($v) => is_array($v) ? ($v['configured'] ?? false) : filled($v)), 'enabled' => (bool) ($data['enabled'] ?? false), 'last_tested_at' => null, 'last_test_status' => null];
    }

    private function validateCategory(string $category): void
    {
        validator(['category' => $category], ['category' => [Rule::in(self::CATEGORIES)]])->validate();
    }
}

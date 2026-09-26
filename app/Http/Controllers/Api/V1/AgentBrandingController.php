<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AgentBrandingController extends Controller
{
    /**
     * Get dynamic agent branding, theme colors, logos, and lock screen settings.
     */
    public function show(): JsonResponse
    {
        $settings = SystemSetting::query()
            ->where('category', 'agent_branding')
            ->pluck('value', 'key');

        $data = [
            'brand_name' => $settings->get('brand_name', 'EMI Security Agent'),
            'brand_logo_url' => $settings->get('brand_logo_url', '/logo.png'),
            'app_icon_url' => $settings->get('app_icon_url', '/favicon.ico'),
            'primary_color' => $settings->get('primary_color', '#0f172a'),
            'accent_color' => $settings->get('accent_color', '#06b6d4'),
            'lock_banner_color' => $settings->get('lock_banner_color', '#dc2626'),
            'lock_screen_title' => $settings->get('lock_screen_title', '🚨 DEVICE LOCKED - PENDING EMI'),
            'lock_screen_subtitle' => $settings->get('lock_screen_subtitle', 'This device has been temporarily restricted due to overdue EMI payment.'),
            'support_phone' => $settings->get('support_phone', '+91 9876543210'),
            'emergency_phone_1' => $settings->get('emergency_phone_1', '112'),
            'emergency_phone_2' => $settings->get('emergency_phone_2', '+91 9876543210'),
            'upi_qr_url' => $settings->get('upi_qr_url', ''),
            'allow_sos_calls' => filter_var($settings->get('allow_sos_calls', 'true'), FILTER_VALIDATE_BOOLEAN),
            'allow_incoming_calls' => filter_var($settings->get('allow_incoming_calls', 'true'), FILTER_VALIDATE_BOOLEAN),
            'allow_direct_payment' => filter_var($settings->get('allow_direct_payment', 'true'), FILTER_VALIDATE_BOOLEAN),
            'anti_bypass_strict_mode' => filter_var($settings->get('anti_bypass_strict_mode', 'true'), FILTER_VALIDATE_BOOLEAN),
            'custom_css' => $settings->get('custom_css', ''),
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * Update dynamic agent branding & appearance settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:255'],
            'brand_logo_url' => ['nullable', 'string', 'max:1000'],
            'app_icon_url' => ['nullable', 'string', 'max:1000'],
            'primary_color' => ['nullable', 'string', 'max:50'],
            'accent_color' => ['nullable', 'string', 'max:50'],
            'lock_banner_color' => ['nullable', 'string', 'max:50'],
            'lock_screen_title' => ['nullable', 'string', 'max:255'],
            'lock_screen_subtitle' => ['nullable', 'string', 'max:1000'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'emergency_phone_1' => ['nullable', 'string', 'max:50'],
            'emergency_phone_2' => ['nullable', 'string', 'max:50'],
            'upi_qr_url' => ['nullable', 'string', 'max:1000'],
            'allow_sos_calls' => ['nullable', 'boolean'],
            'allow_incoming_calls' => ['nullable', 'boolean'],
            'allow_direct_payment' => ['nullable', 'boolean'],
            'anti_bypass_strict_mode' => ['nullable', 'boolean'],
            'custom_css' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                SystemSetting::query()->updateOrCreate(
                    ['category' => 'agent_branding', 'key' => $key],
                    [
                        'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                        'is_secret' => false,
                        'updated_by' => $request->user()?->id,
                    ]
                );
            }
        }

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'actor_name' => $request->user()?->name,
            'actor_email' => $request->user()?->email,
            'action' => 'agent_branding.updated',
            'entity_type' => 'settings',
            'entity_id' => 'agent_branding',
            'new_values' => $validated,
            'remarks' => 'Agent branding and lock screen appearance updated.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Agent branding settings updated successfully.']);
    }

    /**
     * Upload custom branding logo or icon file.
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:logo,icon,qr'],
            'file' => ['required', 'image', 'max:10240'], // 10MB max image
        ]);

        $type = $request->input('type');
        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $filename = "branding_{$type}_" . time() . ".{$extension}";

        $targetDir = base_path('../frontend/public/branding');
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $file->move($targetDir, $filename);
        $publicUrl = "/branding/{$filename}";

        $settingKey = match ($type) {
            'logo' => 'brand_logo_url',
            'icon' => 'app_icon_url',
            'qr' => 'upi_qr_url',
        };

        SystemSetting::query()->updateOrCreate(
            ['category' => 'agent_branding', 'key' => $settingKey],
            ['value' => $publicUrl, 'is_secret' => false, 'updated_by' => $request->user()?->id]
        );

        return response()->json([
            'message' => "Successfully uploaded custom {$type} image.",
            'url' => $publicUrl,
        ]);
    }
}

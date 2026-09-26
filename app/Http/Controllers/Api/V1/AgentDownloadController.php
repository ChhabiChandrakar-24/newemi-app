<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class AgentDownloadController extends Controller
{
    /**
     * Get dynamic agent download settings for Android, Windows, iOS, and Custom platforms.
     */
    public function index(): JsonResponse
    {
        $settings = SystemSetting::query()
            ->where('category', 'agent_downloads')
            ->pluck('value', 'key');

        $data = [
            'android' => [
                'enabled' => filter_var($settings->get('android_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
                'name' => $settings->get('android_name', 'Android Device Owner Agent'),
                'file_name' => $settings->get('android_file_name', 'emi-agent-latest.apk'),
                'file_size' => $settings->get('android_file_size', '~23 MB'),
                'version' => $settings->get('android_version', '1.0.0'),
                'min_version' => $settings->get('android_min_version', '1.0.0'),
                'download_url' => $settings->get('android_download_url', '/downloads/emi-agent-latest.apk'),
                'supported_os' => $settings->get('android_supported_os', 'Android 8.0 to 15+'),
                'description' => $settings->get('android_description', 'Official Android Device Owner agent with Knox lockdown, boot auto-start, and offline lock persistence.'),
            ],
            'windows' => [
                'enabled' => filter_var($settings->get('windows_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
                'name' => $settings->get('windows_name', 'Windows Laptop Agent (.exe)'),
                'file_name' => $settings->get('windows_file_name', 'EMIDeviceAgent.exe'),
                'file_size' => $settings->get('windows_file_size', '~25 KB'),
                'version' => $settings->get('windows_version', '1.0.0'),
                'min_version' => $settings->get('windows_min_version', '1.0.0'),
                'download_url' => $settings->get('windows_download_url', '/downloads/EMIDeviceAgent.exe'),
                'supported_os' => $settings->get('windows_supported_os', 'Windows 10 & 11 (64-bit)'),
                'description' => $settings->get('windows_description', 'Native Windows agent for laptops & PCs. Auto-starts on boot, binds system HWID, and enforces un-bypassable lock screen.'),
            ],
            'ios' => [
                'enabled' => filter_var($settings->get('ios_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
                'name' => $settings->get('ios_name', 'Apple iOS Enrollment Profile'),
                'file_name' => $settings->get('ios_file_name', 'EMI_MDM_Profile.mobileconfig'),
                'file_size' => $settings->get('ios_file_size', '~15 KB'),
                'version' => $settings->get('ios_version', '1.0.0'),
                'min_version' => $settings->get('ios_min_version', '1.0.0'),
                'download_url' => $settings->get('ios_download_url', '/downloads/EMI_MDM_Profile.mobileconfig'),
                'supported_os' => $settings->get('ios_supported_os', 'iOS 14.0 to 18+ (iPhone & iPad)'),
                'description' => $settings->get('ios_description', 'Apple iOS Mobile Device Management (MDM) enrollment profile for supervised iPhones and iPads.'),
            ],
            'custom' => [
                'enabled' => filter_var($settings->get('custom_enabled', 'false'), FILTER_VALIDATE_BOOLEAN),
                'name' => $settings->get('custom_name', 'Custom / Linux Agent'),
                'file_name' => $settings->get('custom_file_name', 'agent-installer.sh'),
                'file_size' => $settings->get('custom_file_size', '~5 MB'),
                'version' => $settings->get('custom_version', '1.0.0'),
                'min_version' => $settings->get('custom_min_version', '1.0.0'),
                'download_url' => $settings->get('custom_download_url', ''),
                'supported_os' => $settings->get('custom_supported_os', 'Ubuntu / Debian / RHEL'),
                'description' => $settings->get('custom_description', 'Custom POS terminal or Linux client agent installer script.'),
            ],
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * Update dynamic agent settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'android_enabled' => ['nullable', 'boolean'],
            'android_name' => ['nullable', 'string', 'max:255'],
            'android_version' => ['nullable', 'string', 'max:50'],
            'android_download_url' => ['nullable', 'string', 'max:1000'],
            'android_supported_os' => ['nullable', 'string', 'max:255'],
            'android_description' => ['nullable', 'string', 'max:1000'],

            'windows_enabled' => ['nullable', 'boolean'],
            'windows_name' => ['nullable', 'string', 'max:255'],
            'windows_version' => ['nullable', 'string', 'max:50'],
            'windows_download_url' => ['nullable', 'string', 'max:1000'],
            'windows_supported_os' => ['nullable', 'string', 'max:255'],
            'windows_description' => ['nullable', 'string', 'max:1000'],

            'ios_enabled' => ['nullable', 'boolean'],
            'ios_name' => ['nullable', 'string', 'max:255'],
            'ios_version' => ['nullable', 'string', 'max:50'],
            'ios_download_url' => ['nullable', 'string', 'max:1000'],
            'ios_supported_os' => ['nullable', 'string', 'max:255'],
            'ios_description' => ['nullable', 'string', 'max:1000'],

            'custom_enabled' => ['nullable', 'boolean'],
            'custom_name' => ['nullable', 'string', 'max:255'],
            'custom_version' => ['nullable', 'string', 'max:50'],
            'custom_download_url' => ['nullable', 'string', 'max:1000'],
            'custom_supported_os' => ['nullable', 'string', 'max:255'],
            'custom_description' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                SystemSetting::query()->updateOrCreate(
                    ['category' => 'agent_downloads', 'key' => $key],
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
            'action' => 'agent_downloads.updated',
            'entity_type' => 'settings',
            'entity_id' => 'agent_downloads',
            'new_values' => $validated,
            'remarks' => 'Dynamic agent download settings updated by admin.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Agent download settings updated successfully.']);
    }

    /**
     * Upload an agent binary (.apk, .exe, .mobileconfig, .ipa, .sh)
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => ['required', 'string', 'in:android,windows,ios,custom'],
            'file' => ['required', 'file', 'max:102400'], // max 100MB
        ]);

        $platform = $request->input('platform');
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $fileSizeMB = '~' . round($file->getSize() / (1024 * 1024), 1) . ' MB';

        // Destination dir in public/downloads
        $targetDir = base_path('../frontend/public/downloads');
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $file->move($targetDir, $originalName);
        $publicUrl = '/downloads/' . $originalName;

        // Auto update system settings
        SystemSetting::query()->updateOrCreate(
            ['category' => 'agent_downloads', 'key' => "{$platform}_download_url"],
            ['value' => $publicUrl, 'is_secret' => false, 'updated_by' => $request->user()?->id]
        );
        SystemSetting::query()->updateOrCreate(
            ['category' => 'agent_downloads', 'key' => "{$platform}_file_name"],
            ['value' => $originalName, 'is_secret' => false, 'updated_by' => $request->user()?->id]
        );
        SystemSetting::query()->updateOrCreate(
            ['category' => 'agent_downloads', 'key' => "{$platform}_file_size"],
            ['value' => $fileSizeMB, 'is_secret' => false, 'updated_by' => $request->user()?->id]
        );
        SystemSetting::query()->updateOrCreate(
            ['category' => 'agent_downloads', 'key' => "{$platform}_enabled"],
            ['value' => 'true', 'is_secret' => false, 'updated_by' => $request->user()?->id]
        );

        return response()->json([
            'message' => "Successfully uploaded {$originalName} for {$platform} platform.",
            'url' => $publicUrl,
            'file_name' => $originalName,
            'file_size' => $fileSizeMB,
        ]);
    }

    /**
     * Handle public download redirect or file serving for Android.
     */
    public function downloadAndroid()
    {
        $customUrl = SystemSetting::where('category', 'agent_downloads')->where('key', 'android_download_url')->value('value');
        if (filled($customUrl) && filter_var($customUrl, FILTER_VALIDATE_URL)) {
            return redirect()->away($customUrl);
        }

        $fileName = SystemSetting::where('category', 'agent_downloads')->where('key', 'android_file_name')->value('value') ?: 'emi-agent-latest.apk';
        $path = base_path('../frontend/public/downloads/' . $fileName);
        if (!file_exists($path)) {
            $path = base_path('../frontend/public/downloads/emi-agent-latest.apk');
        }

        if (file_exists($path)) {
            return response()->download($path, basename($path), [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            ]);
        }

        return response()->json(['error' => 'Android APK agent file not found.'], 404);
    }

    /**
     * Handle public download redirect or file serving for Windows.
     */
    public function downloadWindows()
    {
        $customUrl = SystemSetting::where('category', 'agent_downloads')->where('key', 'windows_download_url')->value('value');
        if (filled($customUrl) && filter_var($customUrl, FILTER_VALIDATE_URL)) {
            return redirect()->away($customUrl);
        }

        $fileName = SystemSetting::where('category', 'agent_downloads')->where('key', 'windows_file_name')->value('value') ?: 'EMIDeviceAgent.exe';
        $path = base_path('../frontend/public/downloads/' . $fileName);
        if (!file_exists($path)) {
            $path = base_path('../frontend/public/downloads/EMIDeviceAgent.exe');
        }

        if (file_exists($path)) {
            return response()->download($path, basename($path), [
                'Content-Type' => 'application/x-msdownload',
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            ]);
        }

        return response()->json(['error' => 'Windows Agent EXE file not found.'], 404);
    }

    /**
     * Handle public download redirect or file serving for Apple iOS.
     */
    public function downloadIos()
    {
        $customUrl = SystemSetting::where('category', 'agent_downloads')->where('key', 'ios_download_url')->value('value');
        if (filled($customUrl) && filter_var($customUrl, FILTER_VALIDATE_URL)) {
            return redirect()->away($customUrl);
        }

        $fileName = SystemSetting::where('category', 'agent_downloads')->where('key', 'ios_file_name')->value('value') ?: 'EMI_MDM_Profile.mobileconfig';
        $path = base_path('../frontend/public/downloads/' . $fileName);

        if (file_exists($path)) {
            return response()->download($path, basename($path), [
                'Content-Type' => 'application/x-apple-asn1-signedData',
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            ]);
        }

        return response()->json(['error' => 'Apple iOS MDM profile file not found. Please upload it via admin panel.'], 404);
    }
}

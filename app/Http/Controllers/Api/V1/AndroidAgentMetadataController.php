<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AndroidAgentMetadataController extends Controller
{
    public function show(SettingsService $settings): JsonResponse
    {
        $configured = $settings->category('android-agent');

        return response()->json(['data' => [
            'app_name' => 'EMI Device Agent',
            'package_name' => 'com.example.emiagent',
            'current_version' => $configured['current_version'] ?? '1.0.0',
            'minimum_version' => $configured['minimum_version'] ?? '1.0.0',
            'distribution' => 'authorized_android_enterprise_provisioning',
            'apk_download_available' => false,
        ]]);
    }

    public function update(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'current_version' => ['required', 'string', 'max:30', 'regex:/^\d+\.\d+\.\d+([+-][A-Za-z0-9.-]+)?$/'],
            'minimum_version' => ['required', 'string', 'max:30', 'regex:/^\d+\.\d+\.\d+([+-][A-Za-z0-9.-]+)?$/'],
        ]);

        return response()->json(['message' => 'Android agent version policy updated.', 'data' => $settings->update('android-agent', $data, $request->user())]);
    }
}

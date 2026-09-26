<?php

namespace App\Services;

use App\Contracts\PushProviderInterface;
use Illuminate\Support\Facades\Http;

class FirebasePushProvider implements PushProviderInterface
{
    public function __construct(private readonly FirebaseAccessTokenProvider $access) {}

    public function send(string $token, array $data, bool $highPriority): array
    {
        $project = config('firebase.project_id');
        if (! config('firebase.enabled') || ! $project) {
            return ['status' => 'failed', 'message_id' => null, 'failure_code' => 'firebase_not_configured', 'failure_message' => 'Firebase is disabled or missing project configuration.'];
        }
        try {
            $response = Http::withToken($this->access->token())->acceptJson()->timeout(15)->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                'message' => ['token' => $token, 'data' => $data, 'android' => [
                    'priority' => $highPriority ? 'high' : 'normal',
                    'ttl' => '3600s',
                    'direct_boot_ok' => true,
                ]]]);
            if ($response->successful()) {
                return ['status' => 'sent', 'message_id' => $response->json('name'), 'failure_code' => null, 'failure_message' => null];
            }
            $body = substr($response->body(), 0, 500);
            $invalid = $response->status() === 404 || str_contains($body, 'UNREGISTERED');

            return ['status' => $invalid ? 'invalid_token' : 'failed', 'message_id' => null, 'failure_code' => $invalid ? 'unregistered' : "http_{$response->status()}", 'failure_message' => 'FCM request failed.'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message_id' => null, 'failure_code' => 'transport_error', 'failure_message' => substr($e->getMessage(), 0, 200)];
        }
    }
}

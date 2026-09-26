<?php

namespace App\Services\Messaging;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsGatewayManager
{
    /**
     * Send SMS through configured provider or free services.
     *
     * @param array<string>|string $numbers
     * @param string $message
     * @param string $provider fast2sms|whatsapp_web|android_sim|textlocal|twilio|simulation
     * @param array $config
     * @return array
     */
    public function send(array|string $numbers, string $message, string $provider = 'fast2sms', array $config = []): array
    {
        $numbers = is_array($numbers) ? $numbers : [$numbers];
        $cleanNumbers = array_values(array_filter(array_map(function ($num) {
            $n = preg_replace('/[^0-9]/', '', (string) $num);
            // If Indian number has 91 prefix and is 12 digits, strip 91
            if (strlen($n) > 10 && str_starts_with($n, '91')) {
                $n = substr($n, 2);
            }
            return strlen($n) >= 10 ? substr($n, -10) : $n;
        }, $numbers)));

        if (empty($cleanNumbers)) {
            return [
                'success' => false,
                'message' => 'No valid 10-digit mobile numbers provided.',
                'provider' => $provider,
                'count' => 0,
            ];
        }

        // Resolve API key from config, SystemSettings or .env
        $apiKey = $config['api_key'] ?? $this->resolveApiKey();

        // 1. WhatsApp Web / Direct Links (100% FREE, Zero recharge needed)
        if ($provider === 'whatsapp' || $provider === 'whatsapp_web') {
            return $this->sendWhatsAppWeb($cleanNumbers, $message);
        }

        // 2. Free Android Phone SIM Gateway (Local / Webhook via user's mobile SIM)
        if ($provider === 'android_sim' || $provider === 'webhook') {
            return $this->sendAndroidSimGateway($cleanNumbers, $message, $config);
        }

        // 3. Textlocal India (Offers free signup trial credits)
        if ($provider === 'textlocal') {
            return $this->sendTextlocal($cleanNumbers, $message, $config, $apiKey);
        }

        // 4. Fast2SMS Provider (Real SMS)
        if ($provider === 'fast2sms') {
            if (blank($apiKey)) {
                $sim = $this->sendSimulation($cleanNumbers, $message);
                return [
                    'success' => true,
                    'provider' => 'fast2sms (simulated fallback)',
                    'message' => 'SMS processed and logged in CRM! (Fast2SMS API Key was not entered, dispatched via Sandbox mode).',
                    'count' => count($cleanNumbers),
                    'fallback' => true,
                    'preview' => $sim['preview'] ?? [],
                ];
            }

            return $this->sendFast2Sms($cleanNumbers, $message, $apiKey, $config);
        }

        // 5. Twilio Provider ($15 free trial credit)
        if ($provider === 'twilio') {
            $sid = $config['account_sid'] ?? env('TWILIO_SID');
            $token = $config['auth_token'] ?? env('TWILIO_TOKEN');
            if (blank($sid) || blank($token)) {
                $sim = $this->sendSimulation($cleanNumbers, $message);
                return [
                    'success' => true,
                    'provider' => 'twilio (simulated fallback)',
                    'message' => 'SMS processed and logged in CRM! (Twilio credentials not configured in system, processed via Sandbox engine).',
                    'count' => count($cleanNumbers),
                    'fallback' => true,
                    'preview' => $sim['preview'] ?? [],
                ];
            }
            return $this->sendTwilio($cleanNumbers, $message, $config);
        }

        // 6. Simulator Sandbox (Zero cost testing)
        return $this->sendSimulation($cleanNumbers, $message);
    }

    public function resolveApiKey(): ?string
    {
        // 1. Check SystemSetting database table
        try {
            $setting = SystemSetting::where('category', 'sms')->where('key', 'api_key')->first();
            if ($setting && filled($setting->value)) {
                return $setting->is_secret ? Crypt::decryptString($setting->value) : $setting->value;
            }
        } catch (\Throwable) {
            // Ignore decrypt errors
        }

        // 2. Check .env
        return env('FAST2SMS_API_KEY');
    }

    /**
     * 100% FREE WhatsApp Direct Messaging
     */
    private function sendWhatsAppWeb(array $numbers, string $message): array
    {
        $links = [];
        foreach ($numbers as $num) {
            $encodedText = rawurlencode($message);
            $links[] = [
                'number' => $num,
                'url' => "https://api.whatsapp.com/send?phone=91{$num}&text={$encodedText}",
                'web_url' => "https://web.whatsapp.com/send?phone=91{$num}&text={$encodedText}",
            ];
        }

        return [
            'success' => true,
            'provider' => 'whatsapp_web',
            'message' => 'WhatsApp links ready. You can now chat directly with recipients for FREE with zero balance deducted!',
            'count' => count($numbers),
            'links' => $links,
            'primary_link' => $links[0]['url'] ?? null,
        ];
    }

    /**
     * 100% FREE Android Phone SIM Gateway (Local / Webhook)
     */
    private function sendAndroidSimGateway(array $numbers, string $message, array $config): array
    {
        $gatewayUrl = $config['gateway_url'] ?? env('ANDROID_SMS_GATEWAY_URL');

        if (blank($gatewayUrl)) {
            return [
                'success' => false,
                'provider' => 'android_sim',
                'message' => 'Android Gateway URL is required (e.g. http://192.168.1.15:8080/send or your webhook URL). Please enter the Gateway URL from your Android SMS Gateway app.',
                'count' => 0,
            ];
        }

        try {
            $sentCount = 0;
            $errors = [];

            foreach ($numbers as $phone) {
                $response = Http::withoutVerifying()
                    ->timeout(10)
                    ->post($gatewayUrl, [
                        'to' => $phone,
                        'number' => $phone,
                        'message' => $message,
                        'text' => $message,
                    ]);

                if ($response->successful()) {
                    $sentCount++;
                } else {
                    $errors[] = "Failed for {$phone}: " . Str::limit($response->body(), 100);
                }
            }

            return [
                'success' => $sentCount > 0,
                'provider' => 'android_sim',
                'message' => $sentCount > 0
                    ? "SMS sent to {$sentCount} recipient(s) via your Android Phone SIM card."
                    : 'Failed to send SMS through Android gateway. Check your phone app connection.',
                'count' => $sentCount,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'provider' => 'android_sim',
                'message' => 'Could not connect to Android Phone Gateway: ' . $e->getMessage() . '. Ensure phone and server are on same Wi-Fi or gateway URL is accessible.',
                'count' => 0,
            ];
        }
    }

    /**
     * Textlocal India SMS Gateway
     */
    private function sendTextlocal(array $numbers, string $message, array $config, ?string $apiKey): array
    {
        $key = $config['textlocal_api_key'] ?? $apiKey ?? env('TEXTLOCAL_API_KEY');
        $sender = $config['sender_id'] ?? env('TEXTLOCAL_SENDER', 'TXTLCL');

        if (blank($key)) {
            return [
                'success' => false,
                'provider' => 'textlocal',
                'message' => 'Textlocal API Key is required. Register on textlocal.in to get free trial credits.',
                'count' => 0,
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->asForm()
                ->post('https://api.textlocal.in/send/', [
                    'apikey' => trim($key),
                    'numbers' => implode(',', array_map(fn($n) => '91' . $n, $numbers)),
                    'message' => rawurlencode($message),
                    'sender' => $sender,
                ]);

            $json = $response->json();

            if ($response->successful() && ($json['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'provider' => 'textlocal',
                    'message' => 'SMS successfully sent via Textlocal.',
                    'count' => count($numbers),
                    'raw' => $json,
                ];
            }

            $errMsg = $json['errors'][0]['message'] ?? ($json['message'] ?? 'Textlocal dispatch failed.');

            return [
                'success' => false,
                'provider' => 'textlocal',
                'message' => 'Textlocal Error: ' . $errMsg,
                'raw' => $json,
                'count' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'provider' => 'textlocal',
                'message' => 'Textlocal connection failed: ' . $e->getMessage(),
                'count' => 0,
            ];
        }
    }

    /**
     * Fast2SMS with clear ₹100 recharge notification & route options
     */
    private function sendFast2Sms(array $numbers, string $message, string $apiKey, array $config = []): array
    {
        $route = $config['route'] ?? 'q'; // 'q' = Quick SMS, 'otp' = OTP route

        try {
            $payload = [
                'route' => $route,
                'message' => $message,
                'language' => 'english',
                'flash' => 0,
                'numbers' => implode(',', $numbers),
            ];

            if ($route === 'otp') {
                $payload = [
                    'route' => 'otp',
                    'variables_values' => Str::limit($message, 30),
                    'numbers' => implode(',', $numbers),
                ];
            }

            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withHeaders([
                    'authorization' => trim($apiKey),
                    'Content-Type' => 'application/json',
                ])
                ->post('https://www.fast2sms.com/dev/bulkV2', $payload);

            $json = $response->json();

            if ($response->successful() && ($json['return'] ?? false) === true) {
                $msgText = 'Real SMS successfully dispatched to mobile phone(s) via Fast2SMS.';
                if (isset($json['message'])) {
                    $msgText = is_array($json['message']) ? implode(', ', $json['message']) : (string) $json['message'];
                }

                return [
                    'success' => true,
                    'provider' => 'fast2sms',
                    'message' => $msgText,
                    'request_id' => $json['request_id'] ?? (string) Str::uuid(),
                    'count' => count($numbers),
                    'raw' => $json,
                ];
            }

            // Extract error message cleanly
            $rawMsg = '';
            if (isset($json['message'])) {
                $rawMsg = is_array($json['message']) ? implode(', ', $json['message']) : (string) $json['message'];
            } elseif ($response->body()) {
                $rawMsg = Str::limit($response->body(), 150);
            }

            // Check if account has not completed ₹100 transaction
            $isRechargeNeeded = str_contains($rawMsg, '100 INR') || str_contains($rawMsg, 'transaction of 100');

            if ($isRechargeNeeded) {
                $sim = $this->sendSimulation($numbers, $message);
                return [
                    'success' => true,
                    'provider' => 'fast2sms (simulated fallback)',
                    'message' => 'SMS processed and logged in CRM! (Fast2SMS Notice: Real mobile delivery is locked until a ₹100 wallet recharge is completed at fast2sms.com. Message recorded in CRM).',
                    'recharge_required' => true,
                    'recharge_url' => 'https://www.fast2sms.com/wallet',
                    'count' => count($numbers),
                    'fallback' => true,
                    'preview' => $sim['preview'] ?? [],
                ];
            } else {
                $sim = $this->sendSimulation($numbers, $message);
                return [
                    'success' => true,
                    'provider' => 'fast2sms (simulated fallback)',
                    'message' => 'SMS processed and logged in CRM! (Fast2SMS returned: ' . ($rawMsg ?: 'Error') . ').',
                    'count' => count($numbers),
                    'fallback' => true,
                    'raw' => $json ?? $response->body(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Fast2SMS send error: ' . $e->getMessage());
            $sim = $this->sendSimulation($numbers, $message);

            return [
                'success' => true,
                'provider' => 'fast2sms (simulated fallback)',
                'message' => 'SMS processed and saved in CRM (Fast2SMS connection error, routed via Sandbox engine).',
                'count' => count($numbers),
                'fallback' => true,
            ];
        }
    }

    private function sendTwilio(array $numbers, string $message, array $config): array
    {
        $sid = $config['account_sid'] ?? env('TWILIO_SID');
        $token = $config['auth_token'] ?? env('TWILIO_TOKEN');
        $from = $config['from_number'] ?? env('TWILIO_FROM');

        try {
            $sentCount = 0;
            foreach ($numbers as $to) {
                $fullTo = str_starts_with($to, '+') ? $to : ('+91' . $to);
                $res = Http::withoutVerifying()
                    ->withBasicAuth($sid, $token)
                    ->asForm()
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                        'From' => $from,
                        'To' => $fullTo,
                        'Body' => $message,
                    ]);
                if ($res->successful()) {
                    $sentCount++;
                }
            }

            if ($sentCount > 0) {
                return [
                    'success' => true,
                    'provider' => 'twilio',
                    'message' => "Twilio sent to {$sentCount} recipient(s).",
                    'count' => $sentCount,
                ];
            }

            // If Twilio returned 0 (trial restriction or unverified numbers), fallback to Sandbox
            $sim = $this->sendSimulation($numbers, $message);
            return [
                'success' => true,
                'provider' => 'twilio (simulated fallback)',
                'message' => 'Twilio could not dispatch to live handsets (requires unverified number verification or paid balance). Message logged and saved in CRM Sandbox!',
                'count' => count($numbers),
                'fallback' => true,
                'preview' => $sim['preview'] ?? [],
            ];
        } catch (\Throwable $e) {
            $sim = $this->sendSimulation($numbers, $message);
            return [
                'success' => true,
                'provider' => 'twilio (simulated fallback)',
                'message' => 'SMS processed and saved in CRM (Twilio: ' . $e->getMessage() . ', routed via Sandbox engine).',
                'count' => count($numbers),
                'fallback' => true,
                'preview' => $sim['preview'] ?? [],
            ];
        }
    }

    private function sendSimulation(array $numbers, string $message): array
    {
        $batchId = 'SIM-SMS-' . strtoupper(Str::random(8));

        return [
            'success' => true,
            'provider' => 'simulation',
            'message' => 'Message successfully processed through Simulated Sandbox (No balance deducted, logged in system).',
            'request_id' => $batchId,
            'count' => count($numbers),
            'preview' => [
                'recipient_sample' => array_slice($numbers, 0, 3),
                'total_recipients' => count($numbers),
                'char_length' => strlen($message),
                'sms_parts' => ceil(strlen($message) / 160),
                'sent_at' => now()->toIso8601String(),
            ],
        ];
    }
}


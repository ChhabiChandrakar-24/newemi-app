<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseAccessTokenProvider
{
    public function token(): string
    {
        return Cache::remember('firebase.http_v1.access_token', now()->addMinutes(50), function (): string {
            $path = config('firebase.credentials');
            if (! $path || ! is_readable($path)) {
                throw new RuntimeException('Firebase credentials file is unavailable.');
            }
            $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach (['client_email', 'private_key'] as $key) {
                if (empty($credentials[$key])) {
                    throw new RuntimeException("Firebase credential {$key} is missing.");
                }
            }
            $now = time();
            $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $claim = $this->encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]);
            if (! openssl_sign("{$header}.{$claim}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign Firebase assertion.');
            }
            $assertion = "{$header}.{$claim}.".$this->base64Url($signature);
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion])->throw()->json();

            return $response['access_token'] ?? throw new RuntimeException('Firebase access token missing.');
        });
    }

    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

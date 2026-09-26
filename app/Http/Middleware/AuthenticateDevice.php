<?php

namespace App\Http\Middleware;

use App\Models\DeviceApiCredential;
use App\Services\DeviceEventService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function __construct(private readonly DeviceEventService $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $credential = $token ? DeviceApiCredential::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->with(['device' => fn ($query) => $query->withoutGlobalScopes()])->first() : null;

        if (! $credential || $credential->revoked_at || ($credential->expires_at && $credential->expires_at->isPast())) {
            if ($credential?->device && $credential->revoked_at) {
                $this->events->record($credential->device, 'credential_revoked', 'high', ['code' => 'revoked_credential_used'], true);
            }

            return new JsonResponse(['message' => 'Unauthenticated device.'], 401);
        }

        if (! in_array($credential->device->enrollment_status, ['enrolled', 'released'], true)) {
            return new JsonResponse(['message' => 'Device credential is inactive.'], 401);
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('device', $credential->device);
        $request->attributes->set('device_credential', $credential);
        $company = $credential->company()->firstOrFail();
        app(TenantContext::class)->set($company);

        try {
            return $next($request);
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}

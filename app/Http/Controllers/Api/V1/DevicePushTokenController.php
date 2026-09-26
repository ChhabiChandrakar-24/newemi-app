<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterPushTokenRequest;
use App\Services\DevicePushTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DevicePushTokenController extends Controller
{
    public function __construct(private readonly DevicePushTokenService $tokens) {}

    public function store(RegisterPushTokenRequest $request): JsonResponse
    {
        $token = $this->tokens->register($request->attributes->get('device'), $request->validated());

        return response()->json(['data' => ['registered' => true, 'provider' => $token->provider, 'registered_at' => $token->registered_at?->toISOString()]], 201);
    }

    public function destroy(Request $request): Response
    {
        $this->tokens->revoke($request->attributes->get('device'));

        return response()->noContent();
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $targetCompany = null;
            if ($user->is_platform_admin) {
                $companyId = $request->header('X-Company-Id') ?? $user->company_id;
                if ($companyId === 'null' || $companyId === 'undefined') {
                    $companyId = null;
                }
                
                if (! $companyId && ! $request->is('api/v1/users*') && ! $request->is('api/v1/roles*')) {
                    return new JsonResponse(['message' => 'Select an explicit audited company context before using company APIs.'], 403);
                }
                if ($companyId) {
                    $targetCompany = Company::find($companyId);
                    if (! $targetCompany && ! $request->is('api/v1/users*') && ! $request->is('api/v1/roles*')) {
                        return new JsonResponse(['message' => 'The selected company context is invalid or has been deleted.'], 403);
                    }
                }
            } else {
                $targetCompany = $user->company;
                
                if (! $targetCompany && $user->company_id) {
                    $trashed = Company::withTrashed()->find($user->company_id);
                    if ($trashed) {
                        return new JsonResponse(['message' => 'Your company account has been deleted or closed.'], 403);
                    }
                }
            }

            if (! $targetCompany && ! $user->is_platform_admin) {
                return new JsonResponse(['message' => 'Your account is not assigned to a company.'], 403);
            }
            if ($targetCompany && $targetCompany->status !== 'active' && ! $user->is_platform_admin) {
                return new JsonResponse(['message' => 'Company access is currently suspended or inactive.'], 403);
            }
            if ($targetCompany) {
                app(TenantContext::class)->set($targetCompany);
            }
        }

        try {
            return $next($request);
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}

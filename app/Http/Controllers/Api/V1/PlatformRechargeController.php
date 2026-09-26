<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRecharge;
use App\Services\CompanyRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformRechargeController extends Controller
{
    public function __construct(private readonly CompanyRechargeService $rechargeService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $companyId = $request->filled('company_id') ? $request->integer('company_id') : null;
        $status = $request->string('status')->trim()->value() ?: null;
        $search = $request->string('search')->trim()->value() ?: null;

        $recharges = $this->rechargeService->getAllRecharges($perPage, $companyId, $status, $search);

        return response()->json($recharges);
    }

    public function approve(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $approved = $this->rechargeService->approveRecharge($recharge, $request->user());

        return response()->json([
            'message' => "Recharge {$recharge->recharge_code} approved successfully and company plan activated.",
            'recharge' => $approved,
        ]);
    }

    public function reject(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $rejected = $this->rechargeService->rejectRecharge($recharge, $request->user(), $validated['reason']);

        return response()->json([
            'message' => "Recharge {$recharge->recharge_code} marked as rejected.",
            'recharge' => $rejected,
        ]);
    }

    public function grant(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'device_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'plan_name' => ['nullable', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $recharge = $this->rechargeService->grantSubscriptionByAdmin($company, $request->user(), $validated);

        return response()->json([
            'message' => "Subscription granted successfully for {$company->name}.",
            'recharge' => $recharge,
            'company' => $company->fresh(),
        ], 201);
    }

    public function refund(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $res = $this->rechargeService->refundRecharge($recharge, $request->user(), $validated['reason'] ?? null);

        return response()->json($res);
    }

    public function approveTransfer(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $res = $this->rechargeService->approveTransferQueuedRecharge($recharge, $request->user());

        return response()->json([
            'message' => "Voucher transfer approved. Voucher has been transferred to {$res['target_company']['name']}.",
            'data' => $res,
        ]);
    }

    public function rejectTransfer(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $rejected = $this->rechargeService->rejectTransferQueuedRecharge($recharge, $request->user(), $validated['reason']);

        return response()->json([
            'message' => "Voucher transfer request rejected.",
            'recharge' => $rejected,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Services\ReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatementController extends Controller
{
    public function customer(Request $r, Customer $customer): JsonResponse|Response
    {
        $data = ['customer' => $customer->only(['customer_code', 'full_name', 'mobile_number', 'status']), 'accounts' => $customer->emiAccounts()->with(['schedules', 'payments' => fn ($q) => $q->whereIn('status', ['verified', 'reversed'])])->get(), 'summary' => ['outstanding' => (string) $customer->emiAccounts()->sum('outstanding_amount'), 'overdue' => (string) $customer->emiAccounts()->sum('overdue_amount')]];

        return $this->respond($r, "Customer {$customer->customer_code} Statement", $data);
    }

    public function account(Request $r, EmiAccount $emiAccount): JsonResponse|Response
    {
        $data = ['account' => $emiAccount->load('customer'), 'schedule' => $emiAccount->schedules()->orderBy('installment_number')->get(), 'payments' => $emiAccount->payments()->whereIn('status', ['verified', 'reversed'])->get(), 'summary' => ['paid' => $emiAccount->total_paid, 'outstanding' => $emiAccount->outstanding_amount, 'overdue' => $emiAccount->overdue_amount]];

        return $this->respond($r, "EMI {$emiAccount->emi_account_code} Statement", $data);
    }

    public function device(Device $device): JsonResponse
    {
        return response()->json(['data' => ['device' => $device->only(['device_code', 'brand', 'model', 'management_mode', 'enrollment_status', 'control_status', 'connectivity_status', 'compliance_status', 'created_at', 'activated_at', 'released_at']), 'commands' => $device->commands()->latest()->limit(500)->get(), 'events' => $device->events()->latest('event_time')->limit(500)->get()]]);
    }

    public function receipt(Request $r, Payment $payment, ReceiptService $receipts): Response
    {
        $data = $receipts->data($payment);

        return Pdf::loadView('reports.generic', ['title' => 'Payment Receipt '.$data['receipt_number'], 'summary' => ['amount' => $data['amount'], 'remaining_balance' => $data['remaining_account_balance']], 'rows' => [$data], 'filters' => [], 'generatedAt' => now()])->download('receipt-'.str($data['receipt_number'])->slug().'.pdf');
    }

    private function respond(Request $r, string $title, array $data): JsonResponse|Response
    {
        if ($r->query('format') !== 'pdf') {
            return response()->json(['data' => $data]);
        }

        return Pdf::loadView('reports.generic', ['title' => $title, 'summary' => $data['summary'], 'rows' => isset($data['schedule']) ? $data['schedule']->toArray() : $data['accounts']->toArray(), 'filters' => $r->only(['from_date', 'to_date']), 'generatedAt' => now()])->download(str($title)->slug().'.pdf');
    }
}

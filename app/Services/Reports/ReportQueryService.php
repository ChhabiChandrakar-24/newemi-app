<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\EmiAccount;
use App\Models\EmiSchedule;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReportQueryService
{
    public const TYPES = ['emi-portfolio', 'emi-due', 'overdue', 'overdue-aging', 'payments', 'daily-collections', 'settlements', 'customers', 'devices', 'locked-devices', 'offline-devices', 'device-enrollments', 'security-events', 'device-actions', 'command-performance', 'failed-commands', 'staff-actions', 'audit'];

    public function report(string $type, Request|array $input, bool $all = false): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $f = $input instanceof Request ? $input->all() : $input;

        return match ($type) {
            'emi-portfolio' => $this->portfolio($f, $all), 'emi-due' => $this->due($f, $all), 'overdue' => $this->overdue($f, $all), 'overdue-aging' => $this->aging(),
            'payments' => $this->payments($f, $all), 'daily-collections' => $this->daily($f), 'settlements' => $this->payments(array_merge($f, ['payment_type' => 'settlement']), $all),
            'customers' => $this->customers($f, $all), 'devices' => $this->devices($f, $all), 'locked-devices' => $this->devices(array_merge($f, ['locked' => true]), $all),
            'offline-devices' => $this->devices(array_merge($f, ['connectivity_status' => 'offline']), $all), 'device-enrollments' => $this->devices($f, $all),
            'security-events' => $this->events($f, $all), 'device-actions' => $this->commands($f, $all, true), 'command-performance' => $this->performance($f),
            'failed-commands' => $this->commands(array_merge($f, ['status' => 'failed']), $all), 'staff-actions' => $this->staff($f), 'audit' => $this->audit($f, $all)
        };
    }

    private function portfolio(array $f, bool $all): array
    {
        $q = EmiAccount::query()->with('customer:id,customer_code,full_name,mobile_number')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        return ['summary' => ['total' => (clone $q)->count(), 'active' => (clone $q)->where('status', 'active')->count(), 'overdue' => (clone $q)->where('overdue_amount', '>', 0)->count(), 'completed' => (clone $q)->where('status', 'completed')->count(), 'financed_amount' => (string) (clone $q)->sum('financed_amount'), 'total_payable' => (string) (clone $q)->sum('total_payable'), 'total_collected' => (string) (clone $q)->sum('total_paid'), 'outstanding_amount' => (string) (clone $q)->sum('outstanding_amount'), 'overdue_amount' => (string) (clone $q)->sum('overdue_amount')], 'rows' => $this->rows($q, $all)];
    }

    private function due(array $f, bool $all): array
    {
        $q = EmiSchedule::query()->with('emiAccount.customer:id,customer_code,full_name,mobile_number')->when($f['from_date'] ?? null, fn ($q, $v) => $q->whereDate('due_date', '>=', $v))->when($f['to_date'] ?? null, fn ($q, $v) => $q->whereDate('due_date', '<=', $v))->where('outstanding_amount', '>', 0)->orderBy('due_date');

        return ['summary' => ['count' => (clone $q)->count(), 'amount' => (string) (clone $q)->sum('outstanding_amount')], 'rows' => $this->rows($q, $all)];
    }

    private function overdue(array $f, bool $all): array
    {
        $q = EmiAccount::query()->with(['customer:id,customer_code,full_name,mobile_number', 'devices:id,device_code,emi_account_id,control_status'])->where('overdue_amount', '>', 0)->orderByDesc('overdue_amount');

        return ['summary' => ['accounts' => (clone $q)->count(), 'overdue_amount' => number_format((float) (clone $q)->sum('overdue_amount'), 2, '.', ''), 'outstanding_amount' => number_format((float) (clone $q)->sum('outstanding_amount'), 2, '.', ''), 'fully_locked' => (clone $q)->whereHas('devices', fn ($d) => $d->where('control_status', 'full_lock'))->count()], 'rows' => $this->rows($q, $all)];
    }

    private function aging(): array
    {
        $b = [['1-7', 1, 7], ['8-15', 8, 15], ['16-30', 16, 30], ['31-60', 31, 60], ['61-90', 61, 90], ['90+', 91, 99999]];

        return ['summary' => ['overdue_amount' => (string) EmiSchedule::sum('overdue_amount')], 'rows' => collect($b)->map(function ($x) {
            $q = EmiSchedule::where('overdue_amount', '>', 0)->whereBetween('due_date', [now()->subDays($x[2])->toDateString(), now()->subDays($x[1])->toDateString()]);

            return ['bucket' => $x[0], 'count' => $q->count(), 'overdue_amount' => (string) $q->sum('overdue_amount'), 'outstanding_amount' => (string) $q->sum('outstanding_amount')];
        })->all()];
    }

    private function payments(array $f, bool $all): array
    {
        $q = Payment::query()->with(['customer:id,customer_code,full_name', 'emiAccount:id,emi_account_code', 'collector:id,name'])->when($f['from_date'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))->when($f['to_date'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))->when($f['payment_type'] ?? null, fn ($q, $v) => $q->where('payment_type', $v))->latest('payment_date');

        return ['summary' => ['count' => (clone $q)->count(), 'verified' => (string) (clone $q)->where('status', 'verified')->sum('amount'), 'pending' => (string) (clone $q)->where('status', 'pending')->sum('amount'), 'reversed' => (string) (clone $q)->where('status', 'reversed')->sum('amount')], 'rows' => $this->rows($q, $all)];
    }

    private function daily(array $f): array
    {
        $q = Payment::query()->selectRaw('payment_date, count(*) payment_count, sum(case when status="verified" then amount else 0 end) gross_verified, sum(case when status="reversed" then amount else 0 end) reversals')->when($f['from_date'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))->when($f['to_date'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))->groupBy('payment_date')->orderBy('payment_date');

        return ['summary' => ['days' => $q->count()], 'rows' => $q->get()];
    }

    private function customers(array $f, bool $all): array
    {
        $q = Customer::query()->withCount(['emiAccounts', 'devices'])->withSum('emiAccounts', 'outstanding_amount')->withSum('emiAccounts', 'overdue_amount')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        return ['summary' => ['total' => (clone $q)->count(), 'active' => (clone $q)->where('status', 'active')->count(), 'consent_given' => (clone $q)->where('consent_given', true)->count()], 'rows' => $this->rows($q, $all)];
    }

    private function devices(array $f, bool $all): array
    {
        $q = Device::query()->with(['customer:id,customer_code,full_name', 'emiAccount:id,emi_account_code,overdue_amount'])->withCount(['commands as pending_commands_count' => fn ($q) => $q->whereIn('status', DeviceCommand::ACTIVE_STATUSES)])->when($f['connectivity_status'] ?? null, fn ($q, $v) => $q->where('connectivity_status', $v))->when($f['control_status'] ?? null, fn ($q, $v) => $q->where('control_status', $v))->when($f['locked'] ?? false, fn ($q) => $q->whereIn('control_status', ['warning', 'partial_lock', 'full_lock']));

        return ['summary' => ['total' => (clone $q)->count(), 'online' => (clone $q)->where('connectivity_status', 'online')->count(), 'offline' => (clone $q)->where('connectivity_status', 'offline')->count()], 'rows' => $this->rows($q, $all)];
    }

    private function events(array $f, bool $all): array
    {
        $q = DeviceEvent::query()->with(['device.customer:id,customer_code,full_name'])->when($f['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))->when($f['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))->latest('event_time');

        return ['summary' => ['total' => (clone $q)->count(), 'critical' => (clone $q)->where('severity', 'critical')->count(), 'unacknowledged' => (clone $q)->whereNull('acknowledged_at')->count()], 'rows' => $this->rows($q, $all)];
    }

    private function commands(array $f, bool $all, bool $actions = false): array
    {
        $q = DeviceCommand::query()->with(['device.customer:id,customer_code,full_name', 'device.emiAccount:id,emi_account_code', 'requester:id,name'])->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['command_type'] ?? null, fn ($q, $v) => $q->where('command_type', $v))->when($actions, fn ($q) => $q->whereIn('command_type', ['show_warning', 'partial_lock', 'full_lock', 'unlock']))->latest('requested_at');

        return ['summary' => ['total' => (clone $q)->count(), 'applied' => (clone $q)->where('status', 'applied')->count(), 'failed' => (clone $q)->where('status', 'failed')->count()], 'rows' => $this->rows($q, $all)];
    }

    private function performance(array $f): array
    {
        $q = DeviceCommand::query();
        $total = $q->count();
        $applied = (clone $q)->where('status', 'applied')->count();

        return ['summary' => ['total' => $total, 'applied' => $applied, 'failed' => (clone $q)->where('status', 'failed')->count(), 'expired' => (clone $q)->where('status', 'expired')->count(), 'cancelled' => (clone $q)->where('status', 'cancelled')->count(), 'pending' => (clone $q)->whereIn('status', DeviceCommand::ACTIVE_STATUSES)->count(), 'success_rate' => $total ? round($applied / $total * 100, 2) : 0, 'average_dispatch_to_received_seconds' => (float) (clone $q)->whereNotNull('sent_at')->whereNotNull('received_at')->selectRaw('avg(timestampdiff(second,sent_at,received_at)) value')->value('value')], 'rows' => (clone $q)->selectRaw('command_type, count(*) total, sum(status="applied") applied, sum(status="failed") failed')->groupBy('command_type')->get()];
    }

    private function staff(array $f): array
    {
        return ['summary' => ['actors' => AuditLog::whereNotNull('actor_user_id')->distinct('actor_user_id')->count('actor_user_id')], 'rows' => AuditLog::query()->selectRaw('actor_user_id, actor_name, actor_email, count(*) action_count')->whereNotNull('actor_user_id')->groupBy('actor_user_id', 'actor_name', 'actor_email')->orderByDesc('action_count')->get()];
    }

    private function audit(array $f, bool $all): array
    {
        $q = AuditLog::query()->select(['id', 'actor_user_id', 'actor_name', 'actor_email', 'action', 'entity_type', 'entity_id', 'remarks', 'ip_address', 'created_at'])->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', "%$v%"))->latest();

        return ['summary' => ['total' => (clone $q)->count()], 'rows' => $this->rows($q, $all)];
    }

    private function rows(Builder $q, bool $all): mixed
    {
        return $all ? $q->limit(10000)->get() : $q->paginate(min((int) request('per_page', 25), 100));
    }
}

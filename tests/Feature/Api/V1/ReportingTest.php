<?php

namespace Tests\Feature\Api\V1;

use App\Exports\ArrayReportExport;
use App\Jobs\GenerateReportJob;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\GeneratedReport;
use App\Models\Payment;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Reports\ReportExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_report_permissions_filters_and_money_totals(): void
    {
        $account = EmiAccount::factory()->create(['status' => 'active', 'overdue_amount' => '1250.50', 'outstanding_amount' => '5000.50']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/reports/overdue')->assertOk()->assertJsonPath('data.summary.accounts', 1)->assertJsonPath('data.summary.overdue_amount', '1250.50');
        $this->postJson('/api/v1/reports/generate', ['report_type' => 'overdue', 'format' => 'xlsx'])->assertForbidden();
    }

    public function test_private_xlsx_and_pdf_exports_are_real_files(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        foreach (['xlsx', 'pdf'] as $format) {
            $response = $this->postJson('/api/v1/reports/generate', ['report_type' => 'customers', 'format' => $format, 'filters' => []])->assertAccepted();
            $report = GeneratedReport::findOrFail($response->json('data.id'));
            (new GenerateReportJob($report->id))->handle(app(ReportExportService::class));
            $report->refresh();
            $this->assertSame('completed', $report->status);
            Storage::disk('local')->assertExists($report->file_path);
            $this->assertGreaterThan(100, Storage::disk('local')->size($report->file_path));
        }
    }

    public function test_formula_injection_is_neutralized(): void
    {
        $export = new ArrayReportExport([['name' => '=HYPERLINK("bad")']]);
        $this->assertSame("'=HYPERLINK(\"bad\")", $export->collection()->first()[0]);
    }

    public function test_receipt_pdf_and_schedule_rbac(): void
    {
        $payment = Payment::factory()->create(['status' => 'verified', 'receipt_number' => 'REC-100', 'verified_at' => now()]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $this->get('/api/v1/payments/'.$payment->id.'/receipt/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->postJson('/api/v1/scheduled-reports', ['name' => 'Daily', 'report_type' => 'payments', 'format' => 'xlsx', 'schedule_type' => 'daily', 'delivery_channels' => ['dashboard'], 'recipients' => [], 'is_active' => true])->assertCreated();
    }

    public function test_critical_device_alerts_are_deduplicated(): void
    {
        $device = Device::factory()->create();
        $device->events()->create(['event_type' => 'possible_tamper', 'severity' => 'critical', 'event_time' => now(), 'payload' => []]);
        $this->assertSame(1, app(AlertService::class)->syncDeviceEvents());
        $this->assertSame(0, app(AlertService::class)->syncDeviceEvents());
        $this->assertDatabaseCount('system_alerts', 1);
    }
}

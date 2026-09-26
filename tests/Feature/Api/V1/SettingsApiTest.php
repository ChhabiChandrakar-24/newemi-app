<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_settings_access_is_rejected(): void
    {
        $this->getJson('/api/v1/settings/company')->assertUnauthorized();
    }

    public function test_secret_is_encrypted_masked_preserved_replaced_and_removed(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/settings/firebase', ['values' => ['enabled' => true, 'project_id' => 'project', 'service_account_json' => 'private-secret']])->assertOk()->assertJsonPath('data.service_account_json.configured', true)->assertJsonMissing(['private-secret']);
        $row = SystemSetting::where('key', 'service_account_json')->firstOrFail();
        $this->assertNotSame('private-secret', $row->getRawOriginal('value'));
        $this->assertSame('private-secret', Crypt::decryptString($row->getRawOriginal('value')));
        $this->putJson('/api/v1/settings/firebase', ['values' => ['service_account_json' => '']])->assertOk();
        $this->assertSame('private-secret', Crypt::decryptString($row->fresh()->getRawOriginal('value')));
        $this->putJson('/api/v1/settings/firebase', ['values' => ['service_account_json' => 'replacement']])->assertOk();
        $this->assertSame('replacement', Crypt::decryptString($row->fresh()->getRawOriginal('value')));
        $this->putJson('/api/v1/settings/firebase', ['values' => [], 'remove_secrets' => ['service_account_json']])->assertOk()->assertJsonPath('data.service_account_json.configured', false);
        $this->assertStringNotContainsString('private-secret', json_encode(AuditLog::latest()->value('new_values'), JSON_THROW_ON_ERROR));
    }

    public function test_auditor_cannot_update_or_test_integrations(): void
    {
        $user = User::factory()->create();
        $user->assignRole('auditor');
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/settings/company', ['values' => ['company_name' => 'Denied']])->assertForbidden();
        $this->postJson('/api/v1/settings/integrations/firebase/test')->assertForbidden();
    }
}

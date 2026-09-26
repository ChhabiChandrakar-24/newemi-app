<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AndroidAgentMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_is_authenticated_company_scoped_and_package_is_not_editable(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $alphaAdmin = User::factory()->create(['company_id' => $alpha->id]);
        $betaAdmin = User::factory()->create(['company_id' => $beta->id]);
        $alphaAdmin->assignRole('admin');
        $betaAdmin->assignRole('admin');

        $this->getJson('/api/v1/android-agent')->assertUnauthorized();
        Sanctum::actingAs($alphaAdmin);
        $this->putJson('/api/v1/android-agent', ['current_version' => '1.2.0', 'minimum_version' => '1.1.0', 'package_name' => 'attacker.invalid'])
            ->assertOk();
        $this->getJson('/api/v1/android-agent')->assertOk()
            ->assertJsonPath('data.package_name', 'com.example.emiagent')
            ->assertJsonPath('data.current_version', '1.2.0');

        Sanctum::actingAs($betaAdmin);
        $this->getJson('/api/v1/android-agent')->assertOk()
            ->assertJsonPath('data.current_version', '1.0.0');
    }
}

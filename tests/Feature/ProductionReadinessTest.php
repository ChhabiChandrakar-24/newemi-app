<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_health_is_minimal_and_hardened(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok', 'service' => 'emi-locking-backend'])->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_setup_is_super_admin_only_and_locks_completion(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/setup/status')->assertForbidden();
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        Sanctum::actingAs($super);
        $this->getJson('/api/v1/setup/status')->assertOk()->assertJsonPath('data.completed', false);
        $this->postJson('/api/v1/setup/complete')->assertOk();
        $this->getJson('/api/v1/setup/status')->assertJsonPath('data.completed', true);
    }
}

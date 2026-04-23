<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminResellerCompanyCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_admin_without_company_can_create_company_for_its_reseller(): void
    {
        $reseller = Reseller::create([
            'name' => 'Revenda Nova',
            'slug' => 'revenda-nova',
        ]);

        $resellerAdmin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'reseller-admin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($resellerAdmin)->postJson('/api/admin/empresas', [
            'name' => 'Empresa Cliente 1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('company.name', 'Empresa Cliente 1');
        $response->assertJsonPath('company.reseller_id', $reseller->id);

        $this->assertDatabaseHas('companies', [
            'name' => 'Empresa Cliente 1',
            'reseller_id' => $reseller->id,
        ]);
    }

    public function test_reseller_admin_without_reseller_cannot_create_company(): void
    {
        $resellerAdmin = User::create([
            'name' => 'Admin Sem Revenda',
            'email' => 'reseller-admin-nil@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($resellerAdmin)->postJson('/api/admin/empresas', [
            'name' => 'Empresa Bloqueada',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Usuario sem reseller vinculado.');

        $this->assertDatabaseMissing('companies', [
            'name' => 'Empresa Bloqueada',
        ]);
    }
}

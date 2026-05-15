<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaNumberSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_admin_cannot_access_meta_numbers_of_other_reseller_company(): void
    {
        $resellerA = Reseller::create(['name' => 'Revenda A', 'slug' => 'revenda-a']);
        $resellerB = Reseller::create(['name' => 'Revenda B', 'slug' => 'revenda-b']);

        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $resellerB->id]);

        $resellerAdminA = User::create([
            'name' => 'Admin Revenda A',
            'email' => 'admin-revenda-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => $resellerA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($resellerAdminA)
            ->getJson("/api/admin/companies/{$companyB->id}/meta-numbers");

        $response->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN_SCOPE');
    }

    public function test_company_user_cannot_set_contact_meta_number_from_another_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $agentA = User::create([
            'name' => 'Agente A',
            'email' => 'agente-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $contactA = Contact::create([
            'company_id' => $companyA->id,
            'name' => 'Contato A',
            'phone' => '5511999999999',
            'source' => 'manual',
            'added_by_user_id' => $agentA->id,
        ]);

        $metaNumberB = CompanyMetaNumber::create([
            'company_id' => $companyB->id,
            'phone_number' => '5511888888888',
            'display_name' => 'Numero B',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $agentA->id,
            'updated_by' => $agentA->id,
        ]);

        $response = $this->actingAs($agentA)
            ->patchJson("/api/minha-conta/contatos/{$contactA->id}/meta-number", [
                'meta_number_id' => $metaNumberB->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'META_NUMBER_COMPANY_MISMATCH');
    }

    public function test_create_conversation_rejects_meta_number_from_another_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $agentA = User::create([
            'name' => 'Agente A',
            'email' => 'agente-a-conversa@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $metaNumberB = CompanyMetaNumber::create([
            'company_id' => $companyB->id,
            'phone_number' => '5511777777777',
            'display_name' => 'Numero B',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $agentA->id,
            'updated_by' => $agentA->id,
        ]);

        $response = $this->actingAs($agentA)
            ->postJson('/api/minha-conta/conversations', [
                'customer_phone' => '5511666666666',
                'customer_name' => 'Cliente A',
                'meta_number_id' => $metaNumberB->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'META_NUMBER_COMPANY_MISMATCH');
    }

    public function test_admin_update_meta_number_does_not_trust_foreign_id_with_local_company_route(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda', 'slug' => 'revenda']);
        $companyA = Company::create(['name' => 'Empresa A', 'reseller_id' => $reseller->id]);
        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $reseller->id]);

        $admin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'admin-meta-id@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'company_id' => null,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $metaNumberB = CompanyMetaNumber::create([
            'company_id' => $companyB->id,
            'phone_number' => '5511555555555',
            'display_name' => 'Numero B',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->patchJson("/api/admin/companies/{$companyA->id}/meta-numbers/{$metaNumberB->id}", [
                'display_name' => 'Tentativa indevida',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('error', 'META_NUMBER_NOT_FOUND');
    }
}

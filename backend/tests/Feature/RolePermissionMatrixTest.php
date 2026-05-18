<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_companies_index_requires_admin_role(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda Perm', 'slug' => 'revenda-perm']);
        Company::create(['name' => 'Empresa Perm', 'reseller_id' => $reseller->id]);

        $systemAdmin = $this->makeUser(User::ROLE_SYSTEM_ADMIN, null, null, 'system-admin-perm@test.local');
        $resellerAdmin = $this->makeUser(User::ROLE_RESELLER_ADMIN, null, $reseller->id, 'reseller-admin-perm@test.local');
        $companyAdmin = $this->makeUser(User::ROLE_COMPANY_ADMIN, 1, null, 'company-admin-perm@test.local');
        $agent = $this->makeUser(User::ROLE_AGENT, 1, null, 'agent-perm@test.local');

        $this->getJson('/api/admin/empresas')->assertStatus(401);
        $this->actingAs($systemAdmin)->getJson('/api/admin/empresas')->assertOk();
        $this->actingAs($resellerAdmin)->getJson('/api/admin/empresas')->assertOk();
        $this->actingAs($companyAdmin)->getJson('/api/admin/empresas')->assertStatus(403);
        $this->actingAs($agent)->getJson('/api/admin/empresas')->assertStatus(403);
    }

    public function test_company_conversations_require_company_user_role(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda C', 'slug' => 'revenda-c']);
        $company = Company::create(['name' => 'Empresa C', 'reseller_id' => $reseller->id]);

        $companyAdmin = $this->makeUser(User::ROLE_COMPANY_ADMIN, $company->id, null, 'company-admin-conv@test.local');
        $agent = $this->makeUser(User::ROLE_AGENT, $company->id, null, 'agent-conv@test.local');
        $systemAdmin = $this->makeUser(User::ROLE_SYSTEM_ADMIN, null, null, 'system-admin-conv@test.local');
        $resellerAdmin = $this->makeUser(User::ROLE_RESELLER_ADMIN, null, $reseller->id, 'reseller-admin-conv@test.local');

        $this->getJson('/api/minha-conta/conversas')->assertStatus(401);
        $this->actingAs($companyAdmin)->getJson('/api/minha-conta/conversas')->assertOk();
        $this->actingAs($agent)->getJson('/api/minha-conta/conversas')->assertOk();
        $this->actingAs($systemAdmin)->getJson('/api/minha-conta/conversas')->assertStatus(403);
        $this->actingAs($resellerAdmin)->getJson('/api/minha-conta/conversas')->assertStatus(403);
    }

    public function test_admin_meta_numbers_follow_reseller_scope_and_system_admin_access(): void
    {
        $resellerA = Reseller::create(['name' => 'Revenda A', 'slug' => 'revenda-a-perm']);
        $resellerB = Reseller::create(['name' => 'Revenda B', 'slug' => 'revenda-b-perm']);
        $companyA = Company::create(['name' => 'Empresa A', 'reseller_id' => $resellerA->id]);
        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $resellerB->id]);

        $resellerAdminA = $this->makeUser(User::ROLE_RESELLER_ADMIN, null, $resellerA->id, 'reseller-a-meta-perm@test.local');
        $systemAdmin = $this->makeUser(User::ROLE_SYSTEM_ADMIN, null, null, 'system-meta-perm@test.local');
        $companyAdminA = $this->makeUser(User::ROLE_COMPANY_ADMIN, $companyA->id, null, 'company-a-meta-perm@test.local');

        $this->actingAs($resellerAdminA)->getJson("/api/admin/companies/{$companyA->id}/meta-numbers")->assertOk();
        $this->actingAs($resellerAdminA)->getJson("/api/admin/companies/{$companyB->id}/meta-numbers")->assertStatus(403);
        $this->actingAs($systemAdmin)->getJson("/api/admin/companies/{$companyB->id}/meta-numbers")->assertOk();
        $this->actingAs($companyAdminA)->getJson("/api/admin/companies/{$companyA->id}/meta-numbers")->assertStatus(403);
    }

    public function test_admin_conversation_actions_are_system_admin_only(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda Priv', 'slug' => 'revenda-priv']);
        $company = Company::create(['name' => 'Empresa Priv', 'reseller_id' => $reseller->id]);
        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511990000001',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $systemAdmin = $this->makeUser(User::ROLE_SYSTEM_ADMIN, null, null, 'system-admin-privacy@test.local');
        $resellerAdmin = $this->makeUser(User::ROLE_RESELLER_ADMIN, null, $reseller->id, 'reseller-admin-privacy@test.local');
        $companyAdmin = $this->makeUser(User::ROLE_COMPANY_ADMIN, $company->id, null, 'company-admin-privacy@test.local');

        $this->actingAs($systemAdmin)->postJson("/api/admin/conversas/{$conversation->id}/assumir")->assertStatus(403);
        $this->actingAs($resellerAdmin)->postJson("/api/admin/conversas/{$conversation->id}/assumir")->assertStatus(403);
        $this->actingAs($companyAdmin)->postJson("/api/admin/conversas/{$conversation->id}/assumir")->assertStatus(403);
    }

    private function makeUser(string $role, ?int $companyId, ?int $resellerId, string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $companyId,
            'reseller_id' => $resellerId,
            'is_active' => true,
        ]);
    }
}

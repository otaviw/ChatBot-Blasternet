<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriticalEntitiesSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_destroy_soft_deletes_user_and_keeps_default_queries_safe(): void
    {
        $company = Company::create(['name' => 'Empresa Soft Delete User']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa',
            'email' => 'company-admin-soft-user@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $agent = User::create([
            'name' => 'Agente Alvo',
            'email' => 'agent-soft-delete@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($companyAdmin)
            ->deleteJson("/api/minha-conta/users/{$agent->id}");

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSoftDeleted('users', ['id' => $agent->id]);
        $this->assertNull(User::query()->find($agent->id));
        $this->assertNotNull(User::withTrashed()->find($agent->id));
    }

    public function test_admin_company_destroy_soft_deletes_company_and_preserves_route_behavior(): void
    {
        $reseller = Reseller::create([
            'name' => 'Revenda Soft Delete',
            'slug' => 'revenda-soft-delete',
        ]);
        $resellerAdmin = User::create([
            'name' => 'Admin Revenda',
            'email' => 'reseller-admin-soft-company@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $company = Company::create([
            'name' => 'Empresa Alvo Soft Delete',
            'reseller_id' => $reseller->id,
        ]);

        $response = $this->actingAs($resellerAdmin)
            ->deleteJson("/api/admin/empresas/{$company->id}");

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $this->assertNull(Company::query()->find($company->id));
        $this->assertNotNull(Company::withTrashed()->find($company->id));
    }

    public function test_company_conversation_destroy_soft_deletes_conversation_and_hides_from_listing(): void
    {
        $company = Company::create(['name' => 'Empresa Soft Delete Conversation']);
        $companyAdmin = User::create([
            'name' => 'Admin Empresa Conversa',
            'email' => 'company-admin-soft-conv@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999991111',
            'status' => 'open',
        ]);

        $response = $this->actingAs($companyAdmin)
            ->deleteJson("/api/minha-conta/conversas/{$conversation->id}");

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);
        $this->assertNull(Conversation::query()->find($conversation->id));
        $this->assertNotNull(Conversation::withTrashed()->find($conversation->id));

        $listResponse = $this->actingAs($companyAdmin)->getJson('/api/minha-conta/conversas');
        $listResponse->assertOk();

        $listedIds = collect($listResponse->json('conversations') ?? [])->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($listedIds->contains((int) $conversation->id));
    }

    public function test_soft_deleted_critical_entities_can_be_restored(): void
    {
        $company = Company::create(['name' => 'Empresa Restore']);
        $user = User::create([
            'name' => 'Usuario Restore',
            'email' => 'restore-user@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999992222',
            'status' => 'open',
        ]);

        $user->delete();
        $conversation->delete();
        $company->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);

        $this->assertTrue((bool) Company::withTrashed()->findOrFail($company->id)->restore());
        $this->assertTrue((bool) User::withTrashed()->findOrFail($user->id)->restore());
        $this->assertTrue((bool) Conversation::withTrashed()->findOrFail($conversation->id)->restore());

        $this->assertNotNull(Company::query()->find($company->id));
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertNotNull(Conversation::query()->find($conversation->id));
    }
}

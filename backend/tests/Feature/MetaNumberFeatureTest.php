<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use App\Models\Reseller;
use App\Models\User;
use App\Services\ContactSendNumberResolver;
use App\Services\RealtimePublisher;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MetaNumberFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_admin_only_changes_allowed_companies(): void
    {
        $resellerA = Reseller::create(['name' => 'Revenda A', 'slug' => 'revenda-a-test']);
        $resellerB = Reseller::create(['name' => 'Revenda B', 'slug' => 'revenda-b-test']);
        $companyA = Company::create(['name' => 'Empresa A', 'reseller_id' => $resellerA->id]);
        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $resellerB->id]);

        $adminA = User::create([
            'name' => 'Admin A',
            'email' => 'admin-a-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $resellerA->id,
            'is_active' => true,
        ]);

        $allowed = $this->actingAs($adminA)->postJson("/api/admin/companies/{$companyA->id}/meta-numbers", [
            'phone_number' => '5511992000001',
            'display_name' => 'Comercial A',
            'is_active' => true,
            'is_primary' => true,
        ]);
        $allowed->assertStatus(201);

        $forbidden = $this->actingAs($adminA)->postJson("/api/admin/companies/{$companyB->id}/meta-numbers", [
            'phone_number' => '5511992000002',
            'display_name' => 'Comercial B',
            'is_active' => true,
            'is_primary' => true,
        ]);
        $forbidden->assertStatus(403)->assertJsonPath('error', 'FORBIDDEN_SCOPE');
    }

    public function test_conversation_creation_saves_selected_number_and_manual_send_uses_saved_number(): void
    {
        $company = Company::create(['name' => 'Empresa Conversa']);
        $agent = User::create([
            'name' => 'Agente Conversa',
            'email' => 'agente-conversa-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $numberA = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511993000001',
            'display_name' => 'Numero A',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $numberB = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511993000002',
            'display_name' => 'Numero B',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $create = $this->actingAs($agent)->postJson('/api/minha-conta/conversations', [
            'customer_phone' => '5511988880001',
            'customer_name' => 'Cliente 1',
            'meta_number_id' => $numberB->id,
        ]);
        $create->assertOk();

        $contact = Contact::query()
            ->where('company_id', $company->id)
            ->where('phone', '5511988880001')
            ->firstOrFail();

        $this->assertSame((int) $numberB->id, (int) $contact->meta_number_id);

        $conversationId = (int) $create->json('conversation.id');
        $send = $this->actingAs($agent)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Mensagem de teste',
            'send_outbound' => false,
        ]);
        $send->assertOk();
        $send->assertJsonPath('message.meta.resolved_meta_number_id', (int) $numberB->id);

        $contactPatch = $this->actingAs($agent)->patchJson("/api/minha-conta/contatos/{$contact->id}/meta-number", [
            'meta_number_id' => $numberA->id,
        ]);
        $contactPatch->assertOk();

        $send2 = $this->actingAs($agent)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Mensagem após troca',
            'send_outbound' => false,
        ]);
        $send2->assertOk();
        $send2->assertJsonPath('message.meta.resolved_meta_number_id', (int) $numberA->id);
    }

    public function test_conversation_creation_rejects_nonexistent_meta_number_id_with_not_found(): void
    {
        $company = Company::create(['name' => 'Empresa Meta 404']);
        $agent = User::create([
            'name' => 'Agente Meta 404',
            'email' => 'agente-meta-404@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($agent)->postJson('/api/minha-conta/conversations', [
            'customer_phone' => '5511912340001',
            'customer_name' => 'Cliente Meta 404',
            'meta_number_id' => 999999,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'META_NUMBER_NOT_FOUND');
    }

    public function test_conversation_creation_rejects_inactive_meta_number_id(): void
    {
        $company = Company::create(['name' => 'Empresa Meta Inativo']);
        $agent = User::create([
            'name' => 'Agente Meta Inativo',
            'email' => 'agente-meta-inativo@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $inactive = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511993330001',
            'display_name' => 'Numero Inativo',
            'is_active' => false,
            'is_primary' => false,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->postJson('/api/minha-conta/conversations', [
            'customer_phone' => '5511912340002',
            'customer_name' => 'Cliente Meta Inativo',
            'meta_number_id' => $inactive->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'META_NUMBER_INACTIVE')
            ->assertJsonPath('errors.error', 'META_NUMBER_INACTIVE');
    }

    public function test_removal_reassigns_contacts_in_bulk(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda Remove', 'slug' => 'revenda-remove-test']);
        $company = Company::create(['name' => 'Empresa Remove', 'reseller_id' => $reseller->id]);

        $admin = User::create([
            'name' => 'Admin Remove',
            'email' => 'admin-remove-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $primary = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511994000001',
            'display_name' => 'Primary',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $toRemove = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511994000002',
            'display_name' => 'To Remove',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato R1',
            'phone' => '5511977770001',
            'meta_number_id' => $toRemove->id,
        ]);
        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato R2',
            'phone' => '5511977770002',
            'meta_number_id' => $toRemove->id,
        ]);

        $remove = $this->actingAs($admin)->deleteJson("/api/admin/companies/{$company->id}/meta-numbers/{$toRemove->id}", [
            'strategy' => 'deactivate',
        ]);
        $remove->assertOk();

        $this->assertDatabaseHas('contacts', ['company_id' => $company->id, 'phone' => '5511977770001', 'meta_number_id' => $primary->id]);
        $this->assertDatabaseHas('contacts', ['company_id' => $company->id, 'phone' => '5511977770002', 'meta_number_id' => $primary->id]);
    }

    public function test_campaign_uses_contact_default_number_and_audits_critical_events(): void
    {
        $company = Company::create(['name' => 'Empresa Campanha']);
        $agent = User::create([
            'name' => 'Agente Campanha',
            'email' => 'agente-campanha-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $numberA = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511995000001',
            'display_name' => 'A',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);
        $numberB = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511995000002',
            'display_name' => 'B',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $createConversation = $this->actingAs($agent)->postJson('/api/minha-conta/conversations', [
            'customer_phone' => '5511966660001',
            'customer_name' => 'Cliente Campanha',
            'meta_number_id' => $numberB->id,
        ]);
        $createConversation->assertOk();

        $contact = Contact::query()->where('company_id', $company->id)->where('phone', '5511966660001')->firstOrFail();
        $contact->last_interaction_at = now();
        $contact->save();
        $this->assertSame((int) $numberB->id, (int) $contact->meta_number_id);

        $campaign = Campaign::create([
            'company_id' => $company->id,
            'name' => 'Campanha Meta',
            'type' => 'free',
            'message' => 'Teste campanha',
            'status' => 'sending',
        ]);

        CampaignContact::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        $whatsApp = Mockery::mock(WhatsAppSendService::class);
        $whatsApp->shouldReceive('sendText')->andReturn([
            'ok' => true,
            'whatsapp_message_id' => 'wamid.test.meta',
            'status' => 'sent',
            'error' => null,
            'response' => ['messages' => [['id' => 'wamid.test.meta']]],
        ]);

        $realtime = Mockery::mock(RealtimePublisher::class);
        $realtime->shouldReceive('publish')->andReturnNull();

        $job = new ProcessCampaignJob((int) $campaign->id);
        $job->handle($whatsApp, $realtime, app(ContactSendNumberResolver::class));

        $this->assertDatabaseHas('campaign_contacts', [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.meta_number.changed', 'company_id' => $company->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.send_number.selected', 'company_id' => $company->id]);
    }

    public function test_e2e_real_flow_two_numbers_select_b_campaign_then_remove_b_and_migrate_to_primary(): void
    {
        $reseller = Reseller::create(['name' => 'Revenda E2E', 'slug' => 'revenda-e2e-test']);
        $company = Company::create(['name' => 'Empresa E2E', 'reseller_id' => $reseller->id]);

        $admin = User::create([
            'name' => 'Admin E2E',
            'email' => 'admin-e2e-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);

        $agent = User::create([
            'name' => 'Agente E2E',
            'email' => 'agente-e2e-meta@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $numberA = $this->actingAs($admin)->postJson("/api/admin/companies/{$company->id}/meta-numbers", [
            'phone_number' => '5511996000001',
            'display_name' => 'Numero A',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $numberA->assertStatus(201);
        $numberAId = (int) $numberA->json('item.id');

        $numberB = $this->actingAs($admin)->postJson("/api/admin/companies/{$company->id}/meta-numbers", [
            'phone_number' => '5511996000002',
            'display_name' => 'Numero B',
            'is_primary' => false,
            'is_active' => true,
        ]);
        $numberB->assertStatus(201);
        $numberBId = (int) $numberB->json('item.id');

        $createConversation = $this->actingAs($agent)->postJson('/api/minha-conta/conversations', [
            'customer_phone' => '5511955550001',
            'customer_name' => 'Cliente E2E',
            'meta_number_id' => $numberBId,
        ]);
        $createConversation->assertOk();
        $conversationId = (int) $createConversation->json('conversation.id');

        $contact = Contact::query()->where('company_id', $company->id)->where('phone', '5511955550001')->firstOrFail();
        $contact->last_interaction_at = now();
        $contact->save();
        $this->assertSame($numberBId, (int) $contact->meta_number_id);

        $campaign = Campaign::create([
            'company_id' => $company->id,
            'name' => 'Campanha E2E',
            'type' => 'free',
            'message' => 'Mensagem E2E',
            'status' => 'sending',
        ]);
        CampaignContact::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        $whatsApp = Mockery::mock(WhatsAppSendService::class);
        $whatsApp->shouldReceive('sendText')->andReturn([
            'ok' => true,
            'whatsapp_message_id' => 'wamid.e2e.meta',
            'status' => 'sent',
            'error' => null,
            'response' => ['messages' => [['id' => 'wamid.e2e.meta']]],
        ]);
        $realtime = Mockery::mock(RealtimePublisher::class);
        $realtime->shouldReceive('publish')->andReturnNull();

        (new ProcessCampaignJob((int) $campaign->id))
            ->handle($whatsApp, $realtime, app(ContactSendNumberResolver::class));

        $removeB = $this->actingAs($admin)->deleteJson("/api/admin/companies/{$company->id}/meta-numbers/{$numberBId}", [
            'strategy' => 'deactivate',
        ]);
        $removeB->assertOk();

        $contact->refresh();
        $this->assertSame($numberAId, (int) $contact->meta_number_id);

        $sendAfterRemoval = $this->actingAs($agent)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Após remoção do B',
            'send_outbound' => false,
        ]);
        $sendAfterRemoval->assertOk()->assertJsonPath('message.meta.resolved_meta_number_id', $numberAId);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPrivacyModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_conversation_endpoints_do_not_expose_pii_or_message_content(): void
    {
        $company = Company::create(['name' => 'Empresa Privada']);
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'super-admin@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999999999',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
            'tags' => ['vip'],
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'text' => 'Meu CPF e 123.456.789-00',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'text' => 'Recebido.',
        ]);

        $index = $this->actingAs($admin)->getJson("/api/admin/conversas?company_id={$company->id}");

        $index->assertOk();
        $index->assertJsonPath('privacy_mode', 'blind_default');
        $index->assertJsonPath('conversations.0.customer_phone_masked', '*********9999');
        $index->assertJsonPath('conversations.0.messages_count', 2);
        $index->assertJsonPath('conversations.0.tags_count', 1);
        $index->assertJsonMissingPath('conversations.0.customer_phone');
        $index->assertJsonMissingPath('conversations.0.messages');

        $show = $this->actingAs($admin)->getJson("/api/admin/conversas/{$conversation->id}");

        $show->assertOk();
        $show->assertJsonPath('privacy_mode', 'blind_default');
        $show->assertJsonPath('conversation.customer_phone_masked', '*********9999');
        $show->assertJsonPath('conversation.messages_count', 2);
        $show->assertJsonMissingPath('conversation.customer_phone');
        $show->assertJsonMissingPath('conversation.messages');
    }

    public function test_admin_conversation_actions_are_blocked_by_privacy_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Restrita']);
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin-privacy@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511988888888',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $actions = [
            ['method' => 'postJson', 'url' => "/api/admin/conversas/{$conversation->id}/assumir", 'payload' => []],
            ['method' => 'postJson', 'url' => "/api/admin/conversas/{$conversation->id}/soltar", 'payload' => []],
            [
                'method' => 'postJson',
                'url' => "/api/admin/conversas/{$conversation->id}/responder-manual",
                'payload' => ['text' => 'Teste'],
            ],
            ['method' => 'postJson', 'url' => "/api/admin/conversas/{$conversation->id}/encerrar", 'payload' => []],
            ['method' => 'putJson', 'url' => "/api/admin/conversas/{$conversation->id}/tags", 'payload' => ['tags' => ['a']]],
        ];

        foreach ($actions as $action) {
            $response = $this->actingAs($admin)->{$action['method']}($action['url'], $action['payload']);
            $response->assertStatus(403);
            $response->assertJsonPath('privacy_mode', 'blind_default');
        }
    }

    public function test_reseller_admin_cannot_filter_admin_conversations_with_foreign_company_id(): void
    {
        $resellerA = Reseller::create(['name' => 'Revenda A', 'slug' => 'revenda-a']);
        $resellerB = Reseller::create(['name' => 'Revenda B', 'slug' => 'revenda-b']);

        $companyA = Company::create(['name' => 'Empresa A', 'reseller_id' => $resellerA->id]);
        $companyB = Company::create(['name' => 'Empresa B', 'reseller_id' => $resellerB->id]);

        Conversation::create([
            'company_id' => $companyA->id,
            'customer_phone' => '5511911111111',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        Conversation::create([
            'company_id' => $companyB->id,
            'customer_phone' => '5511922222222',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $resellerAdminA = User::create([
            'name' => 'Admin Revenda A',
            'email' => 'admin-revenda-a-conv@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $resellerA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($resellerAdminA)
            ->getJson("/api/admin/conversas?company_id={$companyB->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Acesso negado para esta empresa.');
    }
}

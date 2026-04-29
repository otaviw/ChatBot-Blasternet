<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualInboxFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_without_saved_settings_uses_default_welcome_then_fallback(): void
    {
        $company = Company::create(['name' => 'Empresa Sem Config']);
        $user = User::create([
            'name' => 'Operador Sem Config',
            'email' => 'semconfig@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $first = $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511777777777',
            'text' => 'oi',
            'send_outbound' => false,
        ]);
        $first->assertOk();
        $first->assertJsonPath('auto_replied', true);
        $firstReply = trim((string) $first->json('reply'));
        $this->assertNotSame('', $firstReply);

        $second = $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511777777777',
            'text' => 'mensagem sem keyword',
            'send_outbound' => false,
        ]);
        $second->assertOk();
        $second->assertJsonPath('auto_replied', true);
        $secondReply = trim((string) $second->json('reply'));
        $this->assertNotSame('', $secondReply);
    }

    public function test_assumed_conversation_disables_auto_reply_and_allows_manual_reply(): void
    {
        $company = Company::create(['name' => 'Empresa Manual']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Ola',
            'fallback_message' => 'Fallback',
            'out_of_hours_message' => 'Fora',
            'business_hours' => [
                'monday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'tuesday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'wednesday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'thursday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'friday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'saturday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'sunday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
            'keyword_replies' => [],
        ]);

        $user = User::create([
            'name' => 'Operador Manual',
            'email' => 'manual@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $first = $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511888888888',
            'text' => 'primeira mensagem',
            'send_outbound' => false,
        ]);
        $first->assertOk();
        $conversationId = (int) $first->json('conversation.id');

        $assume = $this->actingAs($user)->postJson("/api/minha-conta/conversas/{$conversationId}/assumir");
        $assume->assertOk();
        $assume->assertJsonPath('conversation.handling_mode', 'human');

        $second = $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511888888888',
            'text' => 'segunda mensagem',
            'send_outbound' => false,
        ]);
        $second->assertOk();
        $second->assertJsonPath('auto_replied', false);
        $second->assertJsonPath('out_message.id', null);

        $manualReply = $this->actingAs($user)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Resposta manual do operador',
            'send_outbound' => false,
        ]);
        $manualReply->assertOk();
        $manualReply->assertJsonPath('ok', true);
        $manualReply->assertJsonPath('message.direction', 'out');
        $manualReply->assertJsonPath('message.meta.source', 'manual');
    }
}


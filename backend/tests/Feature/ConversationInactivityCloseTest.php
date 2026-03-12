<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationInactivityCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_close_inactive_command_uses_company_hours_and_resets_assignment_fields(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        CompanyBotSetting::create([
            'company_id' => $companyA->id,
            'inactivity_close_hours' => 2,
        ]);
        CompanyBotSetting::create([
            'company_id' => $companyB->id,
            'inactivity_close_hours' => 5,
        ]);

        $agent = User::create([
            'name' => 'Agente A',
            'email' => 'agent-a-inactive@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);
        $area = Area::create([
            'company_id' => $companyA->id,
            'name' => 'Suporte',
        ]);

        $mustClose = Conversation::create([
            'company_id' => $companyA->id,
            'customer_phone' => '5511999000001',
            'status' => 'in_progress',
            'assigned_type' => 'user',
            'assigned_id' => $agent->id,
            'current_area_id' => $area->id,
            'handling_mode' => 'human',
            'assigned_user_id' => $agent->id,
            'assigned_area' => 'Suporte',
            'assumed_at' => now()->subHours(6),
        ]);
        $keepA = Conversation::create([
            'company_id' => $companyA->id,
            'customer_phone' => '5511999000002',
            'status' => 'open',
            'assigned_type' => 'bot',
            'handling_mode' => 'bot',
        ]);
        $keepB = Conversation::create([
            'company_id' => $companyB->id,
            'customer_phone' => '5511999000003',
            'status' => 'open',
            'assigned_type' => 'bot',
            'handling_mode' => 'bot',
        ]);

        $this->createMessageAt($mustClose, now()->subHours(3), 'mensagem antiga A');
        $this->createMessageAt($keepA, now()->subHour(), 'mensagem recente A');
        $this->createMessageAt($keepB, now()->subHours(3), 'mensagem antiga B');

        $this->artisan('conversations:close-inactive')
            ->assertExitCode(0);

        $mustClose->refresh();
        $this->assertSame('closed', $mustClose->status);
        $this->assertSame('bot', $mustClose->handling_mode);
        $this->assertSame('unassigned', $mustClose->assigned_type);
        $this->assertNull($mustClose->assigned_id);
        $this->assertNull($mustClose->current_area_id);
        $this->assertNull($mustClose->assigned_user_id);
        $this->assertNull($mustClose->assigned_area);
        $this->assertNull($mustClose->assumed_at);
        $this->assertNotNull($mustClose->closed_at);

        $keepA->refresh();
        $this->assertSame('open', $keepA->status);
        $this->assertNull($keepA->closed_at);

        $keepB->refresh();
        $this->assertSame('open', $keepB->status);
        $this->assertNull($keepB->closed_at);
    }

    public function test_stale_manual_conversation_is_reset_before_next_inbound_message(): void
    {
        $company = Company::create(['name' => 'Empresa Inatividade']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Ola!',
            'fallback_message' => 'Entendi.',
            'inactivity_close_hours' => 1,
        ]);

        $user = User::create([
            'name' => 'Operador',
            'email' => 'operator-inactive@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999555555',
            'status' => 'in_progress',
            'assigned_type' => 'user',
            'assigned_id' => $user->id,
            'handling_mode' => 'human',
            'assigned_user_id' => $user->id,
            'assumed_at' => now()->subHours(2),
        ]);

        $this->createMessageAt($conversation, now()->subHours(2), 'mensagem antiga');

        $response = $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511999555555',
            'text' => 'nova mensagem',
            'send_outbound' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('auto_replied', true);
        $response->assertJsonPath('conversation.id', (int) $conversation->id);

        $conversation->refresh();
        $this->assertSame('bot', $conversation->handling_mode);
        $this->assertSame('bot', $conversation->assigned_type);
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->assigned_id);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->closed_at);
    }

    private function createMessageAt(Conversation $conversation, Carbon $createdAt, string $text): void
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => $text,
            'meta' => ['source' => 'test'],
        ]);

        $message->created_at = $createdAt;
        $message->updated_at = $createdAt;
        $message->timestamps = false;
        $message->save();

        $conversation->updated_at = $createdAt;
        $conversation->timestamps = false;
        $conversation->save();
    }
}

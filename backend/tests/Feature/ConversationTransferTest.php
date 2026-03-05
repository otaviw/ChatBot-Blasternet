<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_transfer_to_specific_user_and_register_history(): void
    {
        $company = Company::create(['name' => 'Empresa Transfer']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'service_areas' => ['Suporte', 'Financeiro'],
        ]);

        $operatorA = User::create([
            'name' => 'Operador A',
            'email' => 'transfer-a@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
            'areas' => ['Suporte'],
        ]);

        $operatorB = User::create([
            'name' => 'Operador B',
            'email' => 'transfer-b@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
            'areas' => ['Suporte'],
        ]);

        $firstMessage = $this->actingAs($operatorA)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511999999991',
            'text' => 'Preciso de ajuda',
            'send_outbound' => false,
        ]);
        $firstMessage->assertOk();
        $conversationId = (int) $firstMessage->json('conversation.id');

        $transfer = $this->actingAs($operatorA)->postJson("/api/minha-conta/conversas/{$conversationId}/transferir", [
            'to_area' => 'Suporte',
            'to_user_id' => $operatorB->id,
            'send_outbound' => false,
        ]);

        $transfer->assertOk();
        $transfer->assertJsonPath('ok', true);
        $transfer->assertJsonPath('conversation.handling_mode', 'manual');
        $transfer->assertJsonPath('conversation.assigned_user_id', $operatorB->id);
        $transfer->assertJsonPath('conversation.assigned_area', 'Suporte');
        $transfer->assertJsonPath('conversation.transfer_history.0.to_user_id', $operatorB->id);
        $transfer->assertJsonPath('conversation.transfer_history.0.to_area', 'Suporte');

        $this->assertDatabaseHas('conversation_transfers', [
            'conversation_id' => $conversationId,
            'transferred_by_user_id' => $operatorA->id,
            'to_user_id' => $operatorB->id,
            'to_area' => 'Suporte',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'direction' => 'out',
            'text' => 'Sua conversa foi transferida para Operador B (Suporte). Vamos dar continuidade ao atendimento.',
        ]);
    }

    public function test_transfer_by_area_requires_operator_from_target_area_to_reply(): void
    {
        $company = Company::create(['name' => 'Empresa Area']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'service_areas' => ['Suporte', 'Financeiro'],
        ]);

        $supportUser = User::create([
            'name' => 'Atendente Suporte',
            'email' => 'suporte@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
            'areas' => ['Suporte'],
        ]);

        $financeUser = User::create([
            'name' => 'Atendente Financeiro',
            'email' => 'financeiro@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
            'areas' => ['Financeiro'],
        ]);

        $firstMessage = $this->actingAs($supportUser)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511999999992',
            'text' => 'Quero resolver boleto',
            'send_outbound' => false,
        ]);
        $firstMessage->assertOk();
        $conversationId = (int) $firstMessage->json('conversation.id');

        $transfer = $this->actingAs($supportUser)->postJson("/api/minha-conta/conversas/{$conversationId}/transferir", [
            'to_area' => 'Financeiro',
            'send_outbound' => false,
        ]);
        $transfer->assertOk();
        $transfer->assertJsonPath('conversation.assigned_user_id', null);
        $transfer->assertJsonPath('conversation.assigned_area', 'Financeiro');

        $invalidReply = $this->actingAs($supportUser)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Tentativa fora da area',
            'send_outbound' => false,
        ]);
        $invalidReply->assertStatus(409);
        $invalidReply->assertJsonPath('message', 'Conversa destinada para outra área de atendimento.');

        $validReply = $this->actingAs($financeUser)->postJson("/api/minha-conta/conversas/{$conversationId}/responder-manual", [
            'text' => 'Atendimento financeiro iniciado',
            'send_outbound' => false,
        ]);
        $validReply->assertOk();
        $validReply->assertJsonPath('conversation.assigned_user_id', $financeUser->id);
        $validReply->assertJsonPath('conversation.assigned_area', 'Financeiro');
    }
}


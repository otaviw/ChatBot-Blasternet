<?php

namespace Tests\Feature;

use App\Models\Area;
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
        $supportArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Suporte',
        ]);
        $financeArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Financeiro',
        ]);

        $operatorA = User::create([
            'name' => 'Operador A',
            'email' => 'transfer-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $operatorB = User::create([
            'name' => 'Operador B',
            'email' => 'transfer-b@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $operatorA->areas()->attach($supportArea->id);
        $operatorB->areas()->attach($supportArea->id);

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
        $transfer->assertJsonPath('conversation.handling_mode', 'human');
        $transfer->assertJsonPath('conversation.assigned_user_id', $operatorB->id);
        $transfer->assertJsonPath('conversation.assigned_area', 'Suporte');
        $transfer->assertJsonPath('transfer_history.0.to_assigned_type', 'user');
        $transfer->assertJsonPath('transfer_history.0.to_assigned_id', $operatorB->id);
        $transfer->assertJsonPath('transfer_history.0.to_user.id', $operatorB->id);

        $this->assertDatabaseHas('conversation_transfers', [
            'conversation_id' => $conversationId,
            'transferred_by_user_id' => $operatorA->id,
            'to_assigned_type' => 'user',
            'to_assigned_id' => $operatorB->id,
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'direction' => 'out',
            'text' => 'Conversa transferida para Operador B',
        ]);
    }

    public function test_transfer_by_area_requires_operator_from_target_area_to_reply(): void
    {
        $company = Company::create(['name' => 'Empresa Area']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'service_areas' => ['Suporte', 'Financeiro'],
        ]);
        $supportArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Suporte',
        ]);
        $financeArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Financeiro',
        ]);

        $supportUser = User::create([
            'name' => 'Atendente Suporte',
            'email' => 'suporte@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $financeUser = User::create([
            'name' => 'Atendente Financeiro',
            'email' => 'financeiro@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $supportUser->areas()->attach($supportArea->id);
        $financeUser->areas()->attach($financeArea->id);

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


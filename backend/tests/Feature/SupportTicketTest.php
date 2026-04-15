<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_support_ticket_with_sequential_number(): void
    {
        $company = Company::create(['name' => 'Empresa Suporte']);
        $user = User::create([
            'name' => 'Atendente Suporte',
            'email' => 'suporte-user@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $first = $this->actingAs($user)->postJson('/api/suporte/solicitacoes', [
            'subject' => 'Erro ao responder cliente',
            'message' => 'Ao tentar enviar resposta manual, recebo timeout.',
        ]);
        $first->assertStatus(201);
        $first->assertJsonPath('ok', true);
        $first->assertJsonPath('ticket.ticket_number', 1);
        $first->assertJsonPath('ticket.status', SupportTicket::STATUS_OPEN);
        $first->assertJsonPath('ticket.requester_name', $user->name);
        $first->assertJsonPath('ticket.requester_contact', $user->email);
        $first->assertJsonPath('ticket.requester_company_name', $company->name);

        $second = $this->actingAs($user)->postJson('/api/suporte/solicitacoes', [
            'subject' => 'Erro no filtro',
            'message' => 'Não consigo filtrar por tags.',
        ]);
        $second->assertStatus(201);
        $second->assertJsonPath('ticket.ticket_number', 2);
    }

    public function test_authenticated_user_can_list_only_own_support_tickets(): void
    {
        $company = Company::create(['name' => 'Empresa Suporte']);
        $owner = User::create([
            'name' => 'Agente Dono',
            'email' => 'owner@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $other = User::create([
            'name' => 'Agente Outro',
            'email' => 'other@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        SupportTicket::create([
            'ticket_number' => 1,
            'company_id' => $company->id,
            'requester_user_id' => $owner->id,
            'requester_name' => $owner->name,
            'requester_contact' => $owner->email,
            'requester_company_name' => $company->name,
            'subject' => 'Chamado do dono',
            'message' => 'Descrição do dono',
            'status' => SupportTicket::STATUS_OPEN,
        ]);
        SupportTicket::create([
            'ticket_number' => 2,
            'company_id' => $company->id,
            'requester_user_id' => $other->id,
            'requester_name' => $other->name,
            'requester_contact' => $other->email,
            'requester_company_name' => $company->name,
            'subject' => 'Chamado de outro usuário',
            'message' => 'Descrição de outro usuário',
            'status' => SupportTicket::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($owner)->getJson('/api/suporte/minhas-solicitacoes');
        $response->assertOk();
        $response->assertJsonPath('counts.total', 1);
        $response->assertJsonCount(1, 'open_tickets');
        $response->assertJsonCount(0, 'closed_tickets');
        $response->assertJsonPath('open_tickets.0.requester_user_id', $owner->id);

        $showOwner = $this->actingAs($owner)->getJson('/api/suporte/minhas-solicitacoes/1');
        $showOwner->assertOk();
        $showOwner->assertJsonPath('ticket.id', 1);

        $showOther = $this->actingAs($owner)->getJson('/api/suporte/minhas-solicitacoes/2');
        $showOther->assertStatus(403);
    }

    public function test_only_superadmin_can_list_support_tickets(): void
    {
        $company = Company::create(['name' => 'Empresa A']);
        $agent = User::create([
            'name' => 'Agente',
            'email' => 'agent-list@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $admin = User::create([
            'name' => 'Superadmin',
            'email' => 'superadmin-list@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        SupportTicket::create([
            'ticket_number' => 1,
            'company_id' => $company->id,
            'requester_user_id' => $agent->id,
            'requester_name' => $agent->name,
            'requester_contact' => $agent->email,
            'requester_company_name' => $company->name,
            'subject' => 'Chamado aberto',
            'message' => 'Descrição teste',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $forbidden = $this->actingAs($agent)->getJson('/api/admin/suporte/solicitacoes');
        $forbidden->assertStatus(403);

        $allowed = $this->actingAs($admin)->getJson('/api/admin/suporte/solicitacoes');
        $allowed->assertOk();
        $allowed->assertJsonPath('counts.total', 1);
        $allowed->assertJsonPath('open_tickets.0.ticket_number', 1);

        $showForbidden = $this->actingAs($agent)->getJson('/api/admin/suporte/solicitacoes/1');
        $showForbidden->assertStatus(403);

        $showAllowed = $this->actingAs($admin)->getJson('/api/admin/suporte/solicitacoes/1');
        $showAllowed->assertOk();
        $showAllowed->assertJsonPath('ticket.id', 1);
    }

    public function test_superadmin_can_filter_and_update_ticket_status(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);
        $admin = User::create([
            'name' => 'Superadmin',
            'email' => 'superadmin-filter@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $ticketA = SupportTicket::create([
            'ticket_number' => 1,
            'company_id' => $companyA->id,
            'requester_name' => 'Usuário A',
            'requester_contact' => 'a@test.local',
            'requester_company_name' => $companyA->name,
            'subject' => 'Ticket A',
            'message' => 'Mensagem A',
            'status' => SupportTicket::STATUS_OPEN,
        ]);
        SupportTicket::create([
            'ticket_number' => 2,
            'company_id' => $companyB->id,
            'requester_name' => 'Usuário B',
            'requester_contact' => 'b@test.local',
            'requester_company_name' => $companyB->name,
            'subject' => 'Ticket B',
            'message' => 'Mensagem B',
            'status' => SupportTicket::STATUS_CLOSED,
            'closed_at' => now(),
            'managed_by_user_id' => $admin->id,
        ]);

        $filtered = $this->actingAs($admin)->getJson("/api/admin/suporte/solicitacoes?company_id={$companyA->id}&status=open");
        $filtered->assertOk();
        $filtered->assertJsonPath('counts.total', 1);
        $filtered->assertJsonPath('open_tickets.0.ticket_number', 1);

        $close = $this->actingAs($admin)->putJson("/api/admin/suporte/solicitacoes/{$ticketA->id}/status", [
            'status' => SupportTicket::STATUS_CLOSED,
        ]);
        $close->assertOk();
        $close->assertJsonPath('ticket.status', SupportTicket::STATUS_CLOSED);
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketA->id,
            'status' => SupportTicket::STATUS_CLOSED,
        ]);

        $reopen = $this->actingAs($admin)->putJson("/api/admin/suporte/solicitacoes/{$ticketA->id}/status", [
            'status' => SupportTicket::STATUS_OPEN,
        ]);
        $reopen->assertOk();
        $reopen->assertJsonPath('ticket.status', SupportTicket::STATUS_OPEN);
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketA->id,
            'status' => SupportTicket::STATUS_OPEN,
            'closed_at' => null,
        ]);
    }
}

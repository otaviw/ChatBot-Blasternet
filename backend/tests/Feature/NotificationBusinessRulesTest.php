<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\ConversationPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.enabled' => false,
        ]);
    }

    public function test_inbound_customer_message_notifies_only_assigned_user_when_human_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Inbox']);
        $assignedUser = $this->makeCompanyUser($company, 'assigned-user@test.local');
        $otherUser = $this->makeCompanyUser($company, 'other-user@test.local');

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990001',
            'customer_name' => 'Cliente Um',
            'status' => 'in_progress',
            'handling_mode' => 'human',
            'assigned_type' => 'user',
            'assigned_id' => $assignedUser->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Preciso de ajuda',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $assignedUser->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_type' => 'conversation',
            'reference_id' => $conversation->id,
            'is_read' => false,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $otherUser->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_id' => $conversation->id,
        ]);
    }

    public function test_inbound_customer_message_does_not_notify_when_conversation_is_bot_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Bot']);
        $assignedUser = $this->makeCompanyUser($company, 'bot-assigned@test.local');

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990002',
            'status' => 'open',
            'handling_mode' => 'bot',
            'assigned_type' => 'user',
            'assigned_id' => $assignedUser->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Mensagem em modo bot',
        ]);

        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_inbound_customer_message_notifies_only_users_from_assigned_area(): void
    {
        $company = Company::create(['name' => 'Empresa Area']);
        $area = Area::create([
            'company_id' => $company->id,
            'name' => 'Suporte',
        ]);

        $areaUserA = $this->makeCompanyUser($company, 'area-a@test.local');
        $areaUserB = $this->makeCompanyUser($company, 'area-b@test.local');
        $otherUser = $this->makeCompanyUser($company, 'area-out@test.local');

        $areaUserA->areas()->attach($area->id);
        $areaUserB->areas()->attach($area->id);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990003',
            'status' => 'in_progress',
            'handling_mode' => 'human',
            'assigned_type' => 'area',
            'assigned_id' => $area->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Mensagem para area',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $areaUserA->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_id' => $conversation->id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $areaUserB->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_id' => $conversation->id,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $otherUser->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_id' => $conversation->id,
        ]);
    }

    public function test_inbound_customer_message_skips_notification_when_assignee_has_conversation_open(): void
    {
        $company = Company::create(['name' => 'Empresa Presenca']);
        $assignedUser = $this->makeCompanyUser($company, 'presence-user@test.local');

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990004',
            'status' => 'in_progress',
            'handling_mode' => 'human',
            'assigned_type' => 'user',
            'assigned_id' => $assignedUser->id,
        ]);

        app(ConversationPresenceService::class)->touch((int) $assignedUser->id, (int) $conversation->id);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Mensagem com conversa aberta',
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $assignedUser->id,
            'module' => 'inbox',
            'type' => 'customer_message',
            'reference_id' => $conversation->id,
        ]);
    }

    public function test_transfer_to_user_generates_unread_notification_for_receiver(): void
    {
        $company = Company::create(['name' => 'Empresa Transfer']);
        $receiver = $this->makeCompanyUser($company, 'receiver-transfer@test.local');

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990005',
            'status' => 'in_progress',
            'handling_mode' => 'human',
            'assigned_type' => 'user',
            'assigned_id' => 999,
        ]);

        ConversationTransfer::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'from_assigned_type' => 'user',
            'from_assigned_id' => 999,
            'to_assigned_type' => 'user',
            'to_assigned_id' => $receiver->id,
            'transferred_by_user_id' => $receiver->id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $receiver->id,
            'module' => 'inbox',
            'type' => 'conversation_transferred',
            'reference_type' => 'conversation',
            'reference_id' => $conversation->id,
            'is_read' => false,
        ]);
    }

    public function test_support_ticket_creation_notifies_superadmins(): void
    {
        $company = Company::create(['name' => 'Empresa Support']);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super-admin-support@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $secondSuperAdmin = User::create([
            'name' => 'Second Super Admin',
            'email' => 'second-super-admin-support@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $requester = $this->makeCompanyUser($company, 'support-requester@test.local');

        $ticket = SupportTicket::create([
            'ticket_number' => 12345,
            'company_id' => $company->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_contact' => $requester->email,
            'requester_company_name' => $company->name,
            'subject' => 'Ajuda no sistema',
            'message' => 'Detalhes da solicitacao.',
            'status' => SupportTicket::STATUS_OPEN,
            'managed_by_user_id' => null,
            'closed_at' => null,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superAdmin->id,
            'module' => 'support',
            'type' => 'support_ticket_created',
            'reference_type' => 'support_ticket',
            'reference_id' => $ticket->id,
            'is_read' => false,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $secondSuperAdmin->id,
            'module' => 'support',
            'type' => 'support_ticket_created',
            'reference_type' => 'support_ticket',
            'reference_id' => $ticket->id,
            'is_read' => false,
        ]);
    }

    private function makeCompanyUser(Company $company, string $email): User
    {
        return User::create([
            'name' => 'Company User '.substr($email, 0, 6),
            'email' => $email,
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }
}

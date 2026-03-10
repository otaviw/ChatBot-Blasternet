<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_list_only_allowed_chat_recipients(): void
    {
        $companyA = Company::create(['name' => 'Empresa Chat A']);
        $companyB = Company::create(['name' => 'Empresa Chat B']);

        $admin = User::create([
            'name' => 'Admin Sistema',
            'email' => 'admin-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $sender = User::create([
            'name' => 'Operador A1',
            'email' => 'a1-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $sameCompanyUser = User::create([
            'name' => 'Operador A2',
            'email' => 'a2-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $otherCompanyUser = User::create([
            'name' => 'Operador B1',
            'email' => 'b1-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($sender)->getJson('/api/chat/users');

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonCount(2, 'users');
        $response->assertJsonFragment(['id' => $admin->id]);
        $response->assertJsonFragment(['id' => $sameCompanyUser->id]);
        $response->assertJsonMissing(['id' => $sender->id]);
        $response->assertJsonMissing(['id' => $otherCompanyUser->id]);
    }

    public function test_user_can_create_direct_conversation_send_and_mark_read(): void
    {
        $company = Company::create(['name' => 'Empresa Chat']);

        $sender = User::create([
            'name' => 'Remetente',
            'email' => 'sender-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Destinatario',
            'email' => 'recipient-chat@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createConversation = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Ola time interno',
        ]);

        $createConversation->assertCreated();
        $createConversation->assertJsonPath('ok', true);
        $createConversation->assertJsonPath('created', true);
        $createConversation->assertJsonPath('message.content', 'Ola time interno');

        $conversationId = (int) $createConversation->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $showAsSender = $this->actingAs($sender)->getJson("/api/chat/conversations/{$conversationId}");
        $showAsSender->assertOk();
        $showAsSender->assertJsonPath('conversation.id', $conversationId);
        $showAsSender->assertJsonPath('conversation.messages.0.content', 'Ola time interno');

        $listAsRecipient = $this->actingAs($recipient)->getJson('/api/chat/conversations');
        $listAsRecipient->assertOk();
        $listAsRecipient->assertJsonPath('conversations.0.id', $conversationId);
        $listAsRecipient->assertJsonPath('conversations.0.unread_count', 1);

        $markRead = $this->actingAs($recipient)->postJson("/api/chat/conversations/{$conversationId}/read");
        $markRead->assertOk();
        $markRead->assertJsonPath('unread_count', 0);

        $listAsRecipientAfterRead = $this->actingAs($recipient)->getJson('/api/chat/conversations');
        $listAsRecipientAfterRead->assertOk();
        $listAsRecipientAfterRead->assertJsonPath('conversations.0.unread_count', 0);
    }

    public function test_non_participant_cannot_open_or_send_messages_in_conversation(): void
    {
        $companyA = Company::create(['name' => 'Empresa Chat A']);
        $companyB = Company::create(['name' => 'Empresa Chat B']);

        $sender = User::create([
            'name' => 'Sender',
            'email' => 'sender-chat-private@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Recipient',
            'email' => 'recipient-chat-private@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-chat-private@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $created = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Mensagem privada',
        ]);
        $conversationId = (int) $created->json('conversation.id');

        $outsiderShow = $this->actingAs($outsider)->getJson("/api/chat/conversations/{$conversationId}");
        $outsiderShow->assertForbidden();

        $outsiderSend = $this->actingAs($outsider)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'content' => 'Nao deveria enviar',
        ]);
        $outsiderSend->assertForbidden();
    }

    public function test_only_message_owner_can_edit_or_delete_message_and_deleted_message_is_kept_as_placeholder(): void
    {
        $company = Company::create(['name' => 'Empresa Chat']);

        $owner = User::create([
            'name' => 'Dono da Mensagem',
            'email' => 'owner-chat-message@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $participant = User::create([
            'name' => 'Participante',
            'email' => 'participant-chat-message@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $created = $this->actingAs($owner)->postJson('/api/chat/conversations', [
            'recipient_id' => $participant->id,
            'content' => 'Mensagem original',
        ]);

        $created->assertCreated();
        $conversationId = (int) $created->json('conversation.id');
        $messageId = (int) $created->json('message.id');
        $this->assertGreaterThan(0, $conversationId);
        $this->assertGreaterThan(0, $messageId);

        $editAsOther = $this->actingAs($participant)->patchJson(
            "/api/chat/conversations/{$conversationId}/messages/{$messageId}",
            ['content' => 'Nao deveria editar']
        );
        $editAsOther->assertForbidden();

        $editAsOwner = $this->actingAs($owner)->patchJson(
            "/api/chat/conversations/{$conversationId}/messages/{$messageId}",
            ['content' => 'Mensagem editada']
        );
        $editAsOwner->assertOk();
        $editAsOwner->assertJsonPath('message.content', 'Mensagem editada');
        $this->assertNotNull($editAsOwner->json('message.edited_at'));

        $deleteAsOther = $this->actingAs($participant)->deleteJson(
            "/api/chat/conversations/{$conversationId}/messages/{$messageId}"
        );
        $deleteAsOther->assertForbidden();

        $deleteAsOwner = $this->actingAs($owner)->deleteJson(
            "/api/chat/conversations/{$conversationId}/messages/{$messageId}"
        );
        $deleteAsOwner->assertOk();
        $deleteAsOwner->assertJsonPath('message.content', 'Mensagem apagada');
        $deleteAsOwner->assertJsonPath('message.is_deleted', true);
        $this->assertNotNull($deleteAsOwner->json('message.deleted_at'));

        $showAsParticipant = $this->actingAs($participant)->getJson("/api/chat/conversations/{$conversationId}");
        $showAsParticipant->assertOk();
        $showAsParticipant->assertJsonPath('conversation.messages.0.id', $messageId);
        $showAsParticipant->assertJsonPath('conversation.messages.0.content', 'Mensagem apagada');
        $showAsParticipant->assertJsonPath('conversation.messages.0.is_deleted', true);
    }

    public function test_internal_chat_creates_notifications_for_other_participants_on_new_and_existing_conversations(): void
    {
        $company = Company::create(['name' => 'Empresa Chat Notificacao']);

        $sender = User::create([
            'name' => 'Remetente Notificacao',
            'email' => 'sender-chat-notify@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Destinatario Notificacao',
            'email' => 'recipient-chat-notify@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createConversation = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Primeira mensagem de notificacao',
        ]);

        $createConversation->assertCreated();
        $conversationId = (int) $createConversation->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $recipient->id,
            'module' => 'internal_chat',
            'reference_type' => 'chat_conversation',
            'reference_id' => $conversationId,
            'is_read' => false,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => (int) $sender->id,
            'module' => 'internal_chat',
            'reference_type' => 'chat_conversation',
            'reference_id' => $conversationId,
        ]);

        $sendSecondMessage = $this->actingAs($sender)->postJson(
            "/api/chat/conversations/{$conversationId}/messages",
            ['content' => 'Segunda mensagem de notificacao']
        );
        $sendSecondMessage->assertOk();

        $this->assertDatabaseCount('user_notifications', 2);

        $recipientCounts = $this->actingAs($recipient)->getJson('/api/notifications/unread-counts');
        $recipientCounts->assertOk();
        $recipientCounts->assertJsonPath('unread_by_module.internal_chat', 2);
        $recipientCounts->assertJsonPath('total_unread', 2);

        $recipientNotifications = Notification::query()
            ->where('user_id', (int) $recipient->id)
            ->where('module', 'internal_chat')
            ->latest('id')
            ->get(['id', 'title', 'text', 'reference_id', 'reference_type', 'is_read']);

        $this->assertCount(2, $recipientNotifications);
        $this->assertTrue($recipientNotifications->every(fn (Notification $item) => (int) $item->reference_id === $conversationId));
        $this->assertTrue($recipientNotifications->every(fn (Notification $item) => (string) $item->reference_type === 'chat_conversation'));
        $this->assertTrue($recipientNotifications->every(fn (Notification $item) => ! $item->is_read));
    }
}

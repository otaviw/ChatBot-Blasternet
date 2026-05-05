<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InternalChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_conversation_delete_hides_only_for_user_who_deleted(): void
    {
        $company = Company::create(['name' => 'Empresa Chat Delete Direct']);

        $sender = User::create([
            'name' => 'Sender Delete Direct',
            'email' => 'sender-delete-direct@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Recipient Delete Direct',
            'email' => 'recipient-delete-direct@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $created = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Mensagem para deletar apenas localmente',
        ]);
        $created->assertCreated();

        $conversationId = (int) $created->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $deleteForSender = $this->actingAs($sender)->deleteJson("/api/chat/conversations/{$conversationId}");
        $deleteForSender->assertOk();
        $deleteForSender->assertJsonPath('hidden', true);

        $listSender = $this->actingAs($sender)->getJson('/api/chat/conversations');
        $listSender->assertOk();
        $this->assertFalse(
            collect($listSender->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );

        $listRecipient = $this->actingAs($recipient)->getJson('/api/chat/conversations');
        $listRecipient->assertOk();
        $this->assertTrue(
            collect($listRecipient->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $sender->id,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $recipient->id,
            'hidden_at' => null,
        ]);
    }

    public function test_group_creator_starts_as_admin_and_can_manage_group_and_participants(): void
    {
        $company = Company::create(['name' => 'Empresa Chat Group Manage']);

        $creator = User::create([
            'name' => 'Creator Group',
            'email' => 'creator-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $memberA = User::create([
            'name' => 'Member A',
            'email' => 'member-a-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $memberB = User::create([
            'name' => 'Member B',
            'email' => 'member-b-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $memberC = User::create([
            'name' => 'Member C',
            'email' => 'member-c-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createGroup = $this->actingAs($creator)->postJson('/api/chat/conversations', [
            'type' => 'group',
            'participant_ids' => [$memberA->id, $memberB->id],
            'name' => 'Grupo Operação',
        ]);
        $createGroup->assertCreated();

        $conversationId = (int) $createGroup->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $creatorPivot = ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', (int) $creator->id)
            ->whereNull('left_at')
            ->first();
        $this->assertNotNull($creatorPivot);
        $this->assertTrue((bool) $creatorPivot->is_admin);

        $renameAsMember = $this->actingAs($memberA)->patchJson(
            "/api/chat/conversations/{$conversationId}/group-name",
            ['name' => 'Tentativa sem permissão']
        );
        $renameAsMember->assertForbidden();

        $renameAsCreator = $this->actingAs($creator)->patchJson(
            "/api/chat/conversations/{$conversationId}/group-name",
            ['name' => 'Grupo Renomeado']
        );
        $renameAsCreator->assertOk();
        $renameAsCreator->assertJsonPath('conversation.name', 'Grupo Renomeado');

        $addParticipant = $this->actingAs($creator)->postJson(
            "/api/chat/conversations/{$conversationId}/participants",
            ['participant_id' => $memberC->id]
        );
        $addParticipant->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $memberC->id,
            'left_at' => null,
        ]);

        $promoteMemberA = $this->actingAs($creator)->patchJson(
            "/api/chat/conversations/{$conversationId}/participants/{$memberA->id}/admin",
            ['is_admin' => true]
        );
        $promoteMemberA->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $memberA->id,
            'is_admin' => true,
            'left_at' => null,
        ]);

        $demoteCreator = $this->actingAs($creator)->patchJson(
            "/api/chat/conversations/{$conversationId}/participants/{$creator->id}/admin",
            ['is_admin' => false]
        );
        $demoteCreator->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $creator->id,
            'is_admin' => false,
            'left_at' => null,
        ]);

        $removeMemberB = $this->actingAs($memberA)->deleteJson(
            "/api/chat/conversations/{$conversationId}/participants/{$memberB->id}"
        );
        $removeMemberB->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $memberB->id,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $memberB->id,
            'left_at' => null,
        ]);

        $memberBList = $this->actingAs($memberB)->getJson('/api/chat/conversations');
        $memberBList->assertOk();
        $this->assertFalse(
            collect($memberBList->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );

        $deleteGroup = $this->actingAs($memberA)->deleteJson(
            "/api/chat/conversations/{$conversationId}/group"
        );
        $deleteGroup->assertOk();
        $deleteGroup->assertJsonPath('deleted', true);

        $creatorList = $this->actingAs($creator)->getJson('/api/chat/conversations');
        $creatorList->assertOk();
        $this->assertFalse(
            collect($creatorList->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );
    }

    public function test_last_group_admin_must_transfer_before_leaving_group(): void
    {
        $company = Company::create(['name' => 'Empresa Chat Group Leave']);

        $creator = User::create([
            'name' => 'Creator Leave Group',
            'email' => 'creator-leave-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $memberA = User::create([
            'name' => 'Member A Leave Group',
            'email' => 'member-a-leave-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $memberB = User::create([
            'name' => 'Member B Leave Group',
            'email' => 'member-b-leave-group@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createGroup = $this->actingAs($creator)->postJson('/api/chat/conversations', [
            'type' => 'group',
            'participant_ids' => [$memberA->id, $memberB->id],
            'name' => 'Grupo Saida',
        ]);
        $createGroup->assertCreated();
        $conversationId = (int) $createGroup->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $leaveWithoutTransfer = $this->actingAs($creator)->postJson(
            "/api/chat/conversations/{$conversationId}/leave"
        );
        $leaveWithoutTransfer->assertStatus(422);

        $leaveWithTransfer = $this->actingAs($creator)->postJson(
            "/api/chat/conversations/{$conversationId}/leave",
            ['transfer_admin_to' => $memberA->id]
        );
        $leaveWithTransfer->assertOk();
        $leaveWithTransfer->assertJsonPath('left', true);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $creator->id,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $creator->id,
            'left_at' => null,
        ]);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => (int) $memberA->id,
            'is_admin' => true,
            'left_at' => null,
        ]);

        $creatorList = $this->actingAs($creator)->getJson('/api/chat/conversations');
        $creatorList->assertOk();
        $this->assertFalse(
            collect($creatorList->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );

        $memberAList = $this->actingAs($memberA)->getJson('/api/chat/conversations');
        $memberAList->assertOk();
        $this->assertTrue(
            collect($memberAList->json('conversations', []))
                ->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $conversationId)
        );
    }

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

    public function test_show_conversation_supports_messages_pagination(): void
    {
        $company = Company::create(['name' => 'Empresa Chat Paginacao']);

        $sender = User::create([
            'name' => 'Remetente Paginacao',
            'email' => 'sender-chat-pagination@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Destinatario Paginacao',
            'email' => 'recipient-chat-pagination@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $createdConversation = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Mensagem 1',
        ]);
        $createdConversation->assertCreated();

        $conversationId = (int) $createdConversation->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        for ($index = 2; $index <= 6; $index++) {
            $sendMessage = $this->actingAs($sender)->postJson(
                "/api/chat/conversations/{$conversationId}/messages",
                ['content' => "Mensagem {$index}"]
            );
            $sendMessage->assertOk();
        }

        $latestPage = $this->actingAs($sender)->getJson(
            "/api/chat/conversations/{$conversationId}?messages_per_page=2"
        );
        $latestPage->assertOk();
        $latestPage->assertJsonPath('messages_pagination.current_page', 3);
        $latestPage->assertJsonPath('messages_pagination.last_page', 3);
        $latestPage->assertJsonPath('messages_pagination.per_page', 2);
        $latestPage->assertJsonPath('messages_pagination.total', 6);
        $latestPage->assertJsonPath('conversation.messages.0.content', 'Mensagem 5');
        $latestPage->assertJsonPath('conversation.messages.1.content', 'Mensagem 6');

        $firstPage = $this->actingAs($sender)->getJson(
            "/api/chat/conversations/{$conversationId}?messages_page=1&messages_per_page=2"
        );
        $firstPage->assertOk();
        $firstPage->assertJsonPath('messages_pagination.current_page', 1);
        $firstPage->assertJsonPath('messages_pagination.last_page', 3);
        $firstPage->assertJsonPath('conversation.messages.0.content', 'Mensagem 1');
        $firstPage->assertJsonPath('conversation.messages.1.content', 'Mensagem 2');
    }

    public function test_user_can_send_attachment_and_participant_can_open_media_endpoint(): void
    {
        Storage::fake('public');

        $company = Company::create(['name' => 'Empresa Chat Anexo']);
        $otherCompany = Company::create(['name' => 'Empresa Chat Anexo B']);

        $sender = User::create([
            'name' => 'Remetente Anexo',
            'email' => 'sender-chat-attachment@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $recipient = User::create([
            'name' => 'Destinatario Anexo',
            'email' => 'recipient-chat-attachment@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $outsider = User::create([
            'name' => 'Fora da Conversa',
            'email' => 'outsider-chat-attachment@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);

        $createdConversation = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Conversa com anexo',
        ]);
        $createdConversation->assertCreated();

        $conversationId = (int) $createdConversation->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $sendWithAttachment = $this->actingAs($sender)->post(
            "/api/chat/conversations/{$conversationId}/messages",
            [
                'content' => 'Arquivo em anexo',
                'attachment' => UploadedFile::fake()->create('evidence.txt', 12, 'text/plain'),
            ],
            ['Accept' => 'application/json']
        );
        $sendWithAttachment->assertOk();

        $attachmentId = (int) $sendWithAttachment->json('message.attachments.0.id');
        $attachmentUrl = (string) $sendWithAttachment->json('message.attachments.0.url');
        $this->assertGreaterThan(0, $attachmentId);
        $this->assertSame("/api/chat/attachments/{$attachmentId}/media", $attachmentUrl);

        $showConversation = $this->actingAs($recipient)->getJson("/api/chat/conversations/{$conversationId}");
        $showConversation->assertOk();
        $showConversation->assertJsonPath('conversation.messages.1.attachments.0.id', $attachmentId);
        $showConversation->assertJsonPath('conversation.messages.1.attachments.0.url', $attachmentUrl);

        $mediaResponse = $this->actingAs($recipient)->get($attachmentUrl);
        $mediaResponse->assertOk();
        $this->assertTrue(
            str_starts_with((string) $mediaResponse->headers->get('Content-Type', ''), 'text/plain'),
            'Expected attachment media content type to start with text/plain'
        );

        $outsiderResponse = $this->actingAs($outsider)->getJson($attachmentUrl);
        $outsiderResponse->assertForbidden();
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
        $outsiderShow->assertNotFound();

        $outsiderSend = $this->actingAs($outsider)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'content' => 'Não deveria enviar',
        ]);
        $outsiderSend->assertNotFound();
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
            ['content' => 'Não deveria editar']
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

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalChatConversationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_validates_missing_recipient_with_legacy_message(): void
    {
        $company = Company::create(['name' => 'Compat Company']);
        $sender = $this->createUser('compat-sender@test.local', User::ROLE_AGENT, $company->id);

        $response = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'content' => 'Hello',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.recipient_id.0', 'recipient_id e obrigatório.');
    }

    public function test_store_existing_direct_conversation_returns_200_and_created_false(): void
    {
        $company = Company::create(['name' => 'Compat Company']);
        $sender = $this->createUser('compat-sender-existing@test.local', User::ROLE_AGENT, $company->id);
        $recipient = $this->createUser('compat-recipient-existing@test.local', User::ROLE_COMPANY_ADMIN, $company->id);

        $created = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'First message',
        ]);
        $created->assertCreated();

        $reused = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Second message',
        ]);

        $reused->assertOk();
        $reused->assertJsonPath('ok', true);
        $reused->assertJsonPath('created', false);
        $reused->assertJsonPath('message.content', 'Second message');
        $this->assertSame(
            (int) $created->json('conversation.id'),
            (int) $reused->json('conversation.id')
        );
    }

    public function test_send_message_requires_text_or_attachment_with_legacy_message(): void
    {
        $company = Company::create(['name' => 'Compat Company']);
        $sender = $this->createUser('compat-sender-send@test.local', User::ROLE_AGENT, $company->id);
        $recipient = $this->createUser('compat-recipient-send@test.local', User::ROLE_COMPANY_ADMIN, $company->id);

        $conversationId = $this->createConversation($sender, $recipient);
        $response = $this->actingAs($sender)->postJson("/api/chat/conversations/{$conversationId}/messages", []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.content.0', 'Envie texto ou anexo para continuar.');
    }

    public function test_update_message_rejects_message_from_other_conversation_with_legacy_message(): void
    {
        $company = Company::create(['name' => 'Compat Company']);
        $sender = $this->createUser('compat-sender-update@test.local', User::ROLE_AGENT, $company->id);
        $recipient = $this->createUser('compat-recipient-update@test.local', User::ROLE_COMPANY_ADMIN, $company->id);
        $thirdParticipant = $this->createUser('compat-third-update@test.local', User::ROLE_AGENT, $company->id);

        $firstConversation = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Message in first conversation',
        ]);
        $firstConversation->assertCreated();
        $firstMessageId = (int) $firstConversation->json('message.id');

        $secondConversationId = $this->createConversation($sender, $thirdParticipant);
        $response = $this->actingAs($sender)->patchJson(
            "/api/chat/conversations/{$secondConversationId}/messages/{$firstMessageId}",
            ['content' => 'Should fail']
        );

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Mensagem não pertence a conversa informada.');
    }

    public function test_delete_message_rejects_non_owner_with_legacy_message(): void
    {
        $company = Company::create(['name' => 'Compat Company']);
        $owner = $this->createUser('compat-owner-delete@test.local', User::ROLE_AGENT, $company->id);
        $participant = $this->createUser('compat-participant-delete@test.local', User::ROLE_COMPANY_ADMIN, $company->id);

        $created = $this->actingAs($owner)->postJson('/api/chat/conversations', [
            'recipient_id' => $participant->id,
            'content' => 'Owner message',
        ]);
        $created->assertCreated();

        $conversationId = (int) $created->json('conversation.id');
        $messageId = (int) $created->json('message.id');

        $response = $this->actingAs($participant)->deleteJson(
            "/api/chat/conversations/{$conversationId}/messages/{$messageId}"
        );

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Apenas o dono da mensagem pode apagar.');
    }

    public function test_mark_read_rejects_non_participant_with_legacy_message(): void
    {
        $companyA = Company::create(['name' => 'Compat Company A']);
        $companyB = Company::create(['name' => 'Compat Company B']);

        $sender = $this->createUser('compat-sender-read@test.local', User::ROLE_AGENT, $companyA->id);
        $recipient = $this->createUser('compat-recipient-read@test.local', User::ROLE_COMPANY_ADMIN, $companyA->id);
        $outsider = $this->createUser('compat-outsider-read@test.local', User::ROLE_AGENT, $companyB->id);

        $conversationId = $this->createConversation($sender, $recipient);
        $response = $this->actingAs($outsider)->postJson("/api/chat/conversations/{$conversationId}/read");

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Sem permissão para marcar leitura desta conversa.');
    }

    private function createConversation(User $sender, User $recipient): int
    {
        $response = $this->actingAs($sender)->postJson('/api/chat/conversations', [
            'recipient_id' => $recipient->id,
            'content' => 'Bootstrap conversation',
        ]);

        $response->assertCreated();

        return (int) $response->json('conversation.id');
    }

    private function createUser(string $email, string $role, ?int $companyId): User
    {
        return User::create([
            'name' => 'Compat User',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $companyId,
            'is_active' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_requester_can_send_and_list_chat_messages_with_images(): void
    {
        Storage::fake('local');

        [$company, $requester] = $this->makeCompanyRequester();
        $ticket = $this->makeTicket($company, $requester);

        $sendResponse = $this->actingAs($requester)->post(
            "/api/suporte/minhas-solicitacoes/{$ticket->id}/chat",
            [
                'message' => 'Segue print do problema.',
                'images' => [
                    $this->fakePng('erro.png'),
                ],
            ]
        );

        $sendResponse->assertStatus(201);
        $sendResponse->assertJsonPath('ok', true);
        $sendResponse->assertJsonPath('message.support_ticket_id', $ticket->id);
        $sendResponse->assertJsonPath('message.sender_user_id', $requester->id);
        $sendResponse->assertJsonPath('message.content', 'Segue print do problema.');
        $sendResponse->assertJsonCount(1, 'message.attachments');

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_user_id' => $requester->id,
            'type' => 'image',
            'content' => 'Segue print do problema.',
        ]);

        $listResponse = $this->actingAs($requester)->getJson("/api/suporte/minhas-solicitacoes/{$ticket->id}/chat");
        $listResponse->assertOk();
        $listResponse->assertJsonPath('ok', true);
        $listResponse->assertJsonCount(1, 'messages');
        $listResponse->assertJsonPath('messages.0.sender_user_id', $requester->id);
        $listResponse->assertJsonCount(1, 'messages.0.attachments');
    }

    public function test_chat_access_obeys_ticket_permissions_for_requester_and_admin_routes(): void
    {
        [$company, $requester] = $this->makeCompanyRequester();
        $otherUser = $this->makeCompanyUser($company, 'other-chat-user@test.local', User::ROLE_AGENT);
        $systemAdmin = $this->makeSystemAdmin('admin-chat-access@test.local');
        $ticket = $this->makeTicket($company, $requester);

        $mineForbidden = $this->actingAs($otherUser)->getJson("/api/suporte/minhas-solicitacoes/{$ticket->id}/chat");
        $mineForbidden->assertStatus(403);

        $mineSendForbidden = $this->actingAs($otherUser)->postJson("/api/suporte/minhas-solicitacoes/{$ticket->id}/chat", [
            'message' => 'Tentativa indevida',
        ]);
        $mineSendForbidden->assertStatus(403);

        $adminAllowed = $this->actingAs($systemAdmin)->getJson("/api/admin/suporte/solicitacoes/{$ticket->id}/chat");
        $adminAllowed->assertOk();
        $adminAllowed->assertJsonPath('ok', true);

        $adminForbidden = $this->actingAs($requester)->getJson("/api/admin/suporte/solicitacoes/{$ticket->id}/chat");
        $adminForbidden->assertStatus(403);
    }

    public function test_requester_message_notifies_superadmins_and_admin_message_notifies_requester(): void
    {
        [$company, $requester] = $this->makeCompanyRequester();
        $adminSender = $this->makeSystemAdmin('admin-chat-sender@test.local');
        $legacyAdmin = $this->makeSystemAdmin('legacy-admin-chat@test.local', User::ROLE_LEGACY_ADMIN);
        $ticket = $this->makeTicket($company, $requester);

        $requesterSend = $this->actingAs($requester)->postJson("/api/suporte/minhas-solicitacoes/{$ticket->id}/chat", [
            'message' => 'Mensagem do solicitante para suporte.',
        ]);
        $requesterSend->assertStatus(201);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $adminSender->id,
            'module' => 'support',
            'type' => 'support_ticket_message',
            'reference_type' => 'support_ticket',
            'reference_id' => $ticket->id,
            'is_read' => false,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $legacyAdmin->id,
            'module' => 'support',
            'type' => 'support_ticket_message',
            'reference_type' => 'support_ticket',
            'reference_id' => $ticket->id,
            'is_read' => false,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'type' => 'support_ticket_message',
            'reference_id' => $ticket->id,
        ]);

        $adminSend = $this->actingAs($adminSender)->postJson("/api/admin/suporte/solicitacoes/{$ticket->id}/chat", [
            'message' => 'Retorno do suporte para voce.',
        ]);
        $adminSend->assertStatus(201);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'module' => 'support',
            'type' => 'support_ticket_message',
            'reference_type' => 'support_ticket',
            'reference_id' => $ticket->id,
            'is_read' => false,
        ]);
    }

    public function test_chat_attachment_media_endpoint_respects_access_permissions(): void
    {
        Storage::fake('local');

        [$company, $requester] = $this->makeCompanyRequester();
        $otherUser = $this->makeCompanyUser($company, 'other-chat-media@test.local', User::ROLE_COMPANY_ADMIN);
        $systemAdmin = $this->makeSystemAdmin('admin-chat-media@test.local');
        $ticket = $this->makeTicket($company, $requester);

        $sendResponse = $this->actingAs($requester)->post(
            "/api/suporte/minhas-solicitacoes/{$ticket->id}/chat",
            [
                'message' => 'Imagem para validar permissao.',
                'images' => [
                    $this->fakePng('evidencia.png'),
                ],
            ]
        );
        $sendResponse->assertStatus(201);

        $attachment = SupportTicketMessageAttachment::query()->first();
        $this->assertNotNull($attachment);

        $ownerMedia = $this->actingAs($requester)->get("/api/support/ticket-chat/attachments/{$attachment->id}/media");
        $ownerMedia->assertOk();
        $this->assertStringStartsWith('image/', (string) $ownerMedia->headers->get('Content-Type'));

        $otherMedia = $this->actingAs($otherUser)->getJson("/api/support/ticket-chat/attachments/{$attachment->id}/media");
        $otherMedia->assertStatus(403);

        $adminMedia = $this->actingAs($systemAdmin)->get("/api/support/ticket-chat/attachments/{$attachment->id}/media");
        $adminMedia->assertOk();
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function makeCompanyRequester(): array
    {
        $company = Company::create(['name' => 'Empresa Chat']);

        $requester = $this->makeCompanyUser(
            $company,
            'requester-support-chat@test.local',
            User::ROLE_AGENT
        );

        return [$company, $requester];
    }

    private function makeTicket(Company $company, User $requester): SupportTicket
    {
        return SupportTicket::create([
            'ticket_number' => 1500,
            'company_id' => $company->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_contact' => $requester->email,
            'requester_company_name' => $company->name,
            'subject' => 'Falha no fluxo do bot',
            'message' => 'Quando tento publicar, o fluxo nao salva.',
            'status' => SupportTicket::STATUS_OPEN,
            'managed_by_user_id' => null,
            'closed_at' => null,
        ]);
    }

    private function makeCompanyUser(Company $company, string $email, string $role): User
    {
        return User::create([
            'name' => 'Company User '.substr($email, 0, 6),
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    private function makeSystemAdmin(string $email, string $role = User::ROLE_SYSTEM_ADMIN): User
    {
        return User::create([
            'name' => 'Admin '.substr($email, 0, 6),
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => null,
            'is_active' => true,
        ]);
    }

    private function fakePng(string $name): UploadedFile
    {
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAgMBgJ4QfnoAAAAASUVORK5CYII=',
            true
        );

        return UploadedFile::fake()->createWithContent(
            $name,
            $binary !== false ? $binary : ''
        );
    }
}

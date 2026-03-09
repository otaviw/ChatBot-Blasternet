<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketAttachmentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_listing_hides_direct_attachment_url_and_media_endpoint_stays_protected(): void
    {
        Storage::fake('local');

        $company = Company::create(['name' => 'Empresa Privada']);

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-attachment@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other-attachment@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $ticket = SupportTicket::create([
            'company_id' => $company->id,
            'requester_user_id' => $owner->id,
            'requester_name' => $owner->name,
            'requester_contact' => $owner->email,
            'requester_company_name' => $company->name,
            'subject' => 'Erro em anexo',
            'message' => 'Arquivo sensivel',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $storageKey = 'support/attachments/private-evidence.txt';
        Storage::disk('local')->put($storageKey, 'private-content');

        $attachment = SupportTicketAttachment::create([
            'support_ticket_id' => $ticket->id,
            'storage_provider' => 'local',
            'storage_key' => $storageKey,
            'url' => 'https://should-not-be-exposed.invalid/file',
            'mime_type' => 'text/plain',
            'size_bytes' => 15,
        ]);

        $mine = $this->actingAs($owner)->getJson('/api/suporte/minhas-solicitacoes');
        $mine->assertOk();
        $mine->assertJsonPath('open_tickets.0.attachments.0.id', (int) $attachment->id);
        $mine->assertJsonPath('open_tickets.0.attachments.0.url', null);

        $mediaAsOwner = $this->actingAs($owner)->get("/api/support/attachments/{$attachment->id}/media");
        $mediaAsOwner->assertOk();
        $this->assertStringStartsWith(
            'text/plain',
            (string) $mediaAsOwner->headers->get('Content-Type')
        );

        $mediaAsOther = $this->actingAs($otherUser)->getJson("/api/support/attachments/{$attachment->id}/media");
        $mediaAsOther->assertStatus(403);
    }
}

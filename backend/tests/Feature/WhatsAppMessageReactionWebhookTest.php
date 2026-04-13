<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMessageReactionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.app_secret', 'test-secret');
        config()->set('realtime.enabled', false);
    }

    public function test_webhook_reaction_creates_message_reaction(): void
    {
        [$company, $message] = $this->createTargetMessage('wamid.REACTION.CREATE.1');

        $payload = $this->buildReactionPayload(
            (string) $company->meta_phone_number_id,
            '5511999991111',
            'wamid.REACTION.CREATE.1',
            ':)'
        );

        $this->webhookPost($payload)->assertOk();

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'reactor_phone' => '5511999991111',
            'emoji' => ':)',
            'whatsapp_message_id' => 'wamid.REACTION.CREATE.1',
        ]);
    }

    public function test_webhook_reaction_updates_existing_reaction_for_same_contact(): void
    {
        [$company, $message] = $this->createTargetMessage('wamid.REACTION.UPDATE.1');

        MessageReaction::create([
            'message_id' => $message->id,
            'whatsapp_message_id' => 'wamid.REACTION.UPDATE.1',
            'reactor_phone' => '551188887777',
            'emoji' => ':)',
            'reacted_at' => now()->subMinute(),
            'meta' => ['source' => 'test'],
        ]);

        $payload = $this->buildReactionPayload(
            (string) $company->meta_phone_number_id,
            '551188887777',
            'wamid.REACTION.UPDATE.1',
            ':D'
        );

        $this->webhookPost($payload)->assertOk();

        $this->assertSame(1, MessageReaction::query()->count());
        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'reactor_phone' => '551188887777',
            'emoji' => ':D',
            'whatsapp_message_id' => 'wamid.REACTION.UPDATE.1',
        ]);
    }

    public function test_webhook_reaction_removes_existing_reaction_when_emoji_is_empty(): void
    {
        [$company, $message] = $this->createTargetMessage('wamid.REACTION.REMOVE.1');

        MessageReaction::create([
            'message_id' => $message->id,
            'whatsapp_message_id' => 'wamid.REACTION.REMOVE.1',
            'reactor_phone' => '551177776666',
            'emoji' => ':(',
            'reacted_at' => now()->subMinute(),
            'meta' => ['source' => 'test'],
        ]);

        $payload = $this->buildReactionPayload(
            (string) $company->meta_phone_number_id,
            '551177776666',
            'wamid.REACTION.REMOVE.1',
            ''
        );

        $this->webhookPost($payload)->assertOk();

        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'reactor_phone' => '551177776666',
        ]);
    }

    /**
     * @return array{0: Company, 1: Message}
     */
    private function createTargetMessage(string $whatsAppMessageId): array
    {
        $company = Company::create([
            'name' => 'Empresa Reacao',
            'meta_phone_number_id' => '998877665544',
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511900000000',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => 'text',
            'text' => 'Mensagem alvo para reacao',
            'whatsapp_message_id' => $whatsAppMessageId,
            'delivery_status' => 'sent',
        ]);

        return [$company, $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReactionPayload(
        string $phoneNumberId,
        string $from,
        string $targetMessageId,
        string $emoji
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.REACTION.EVENT',
                                        'from' => $from,
                                        'type' => 'reaction',
                                        'timestamp' => '1710000000',
                                        'reaction' => [
                                            'message_id' => $targetMessageId,
                                            'emoji' => $emoji,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

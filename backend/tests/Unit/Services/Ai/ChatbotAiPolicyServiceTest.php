<?php

namespace Tests\Unit\Services\Ai;

use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Services\Ai\ChatbotAiPolicyService;
use PHPUnit\Framework\TestCase;

class ChatbotAiPolicyServiceTest extends TestCase
{
    public function test_low_confidence_returns_fallback_legacy(): void
    {
        $service = new ChatbotAiPolicyService();

        $decision = $service->decide(
            $this->conversation(1001),
            $this->settings(),
            [
                'intent' => 'duvida_geral',
                'confidence' => 0.2,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY, $decision['action']);
        $this->assertSame('low_confidence', $decision['reason']);
    }

    public function test_first_human_request_can_be_assistive_when_enabled(): void
    {
        $service = new ChatbotAiPolicyService();

        $decision = $service->decide(
            $this->conversation(1002),
            $this->settings(autoReplyEnabled: true, handoffLimit: 2),
            [
                'intent' => 'falar_com_atendente',
                'confidence' => 0.92,
                'extracted_data' => [],
                'suggested_reply' => 'posso ajudar rapidamente',
                'should_transfer_to_human' => true,
                'reason' => 'explicit_human_request',
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_SUGGEST_REPLY, $decision['action']);
        $this->assertSame(1, $decision['attendant_request_count']);
    }

    public function test_second_human_request_reaches_handoff_limit(): void
    {
        $service = new ChatbotAiPolicyService();
        $conversation = $this->conversation(1003);
        $settings = $this->settings(autoReplyEnabled: true, handoffLimit: 2);
        $classification = [
            'intent' => 'falar_com_atendente',
            'confidence' => 0.95,
            'extracted_data' => [],
            'suggested_reply' => null,
            'should_transfer_to_human' => true,
            'reason' => 'explicit_human_request',
        ];

        $first = $service->decide($conversation, $settings, $classification);
        $second = $service->decide($conversation, $settings, $classification);

        $this->assertSame(ChatbotAiPolicyService::ACTION_SUGGEST_REPLY, $first['action']);
        $this->assertSame(ChatbotAiPolicyService::ACTION_HANDOFF, $second['action']);
        $this->assertSame('repeated_human_request_limit_reached', $second['reason']);
        $this->assertSame(2, $second['attendant_request_count']);
    }

    public function test_appointment_intent_routes_to_legacy_appointment_flow(): void
    {
        $service = new ChatbotAiPolicyService();

        $decision = $service->decide(
            $this->conversation(1004),
            $this->settings(),
            [
                'intent' => 'agendamento',
                'confidence' => 0.91,
                'extracted_data' => ['date_hint' => 'amanha'],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_ROUTE_TO_APPOINTMENT_FLOW, $decision['action']);
    }

    public function test_policy_error_returns_fallback_legacy(): void
    {
        $service = new ChatbotAiPolicyService();

        $explodingReason = new class
        {
            public function __toString(): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $decision = $service->decide(
            $this->conversation(1005),
            $this->settings(),
            [
                'intent' => 'duvida_geral',
                'confidence' => 0.95,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => $explodingReason,
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY, $decision['action']);
        $this->assertSame('policy_exception', $decision['reason']);
    }

    public function test_specialized_intent_in_sandbox_can_suggest_reply(): void
    {
        $service = new ChatbotAiPolicyService();

        $decision = $service->decide(
            $this->conversation(1006),
            $this->settings(),
            [
                'intent' => 'financeiro',
                'confidence' => 0.91,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ],
            [
                'mode' => 'sandbox',
                'message_text' => 'duvida sobre pagamento',
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_SUGGEST_REPLY, $decision['action']);
        $this->assertSame('specialized_intent_sandbox_assist', $decision['reason']);
    }

    public function test_specialized_intent_outside_sandbox_keeps_extract_only(): void
    {
        $service = new ChatbotAiPolicyService();

        $decision = $service->decide(
            $this->conversation(1007),
            $this->settings(),
            [
                'intent' => 'financeiro',
                'confidence' => 0.91,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ],
            [
                'mode' => 'active',
                'message_text' => 'duvida sobre pagamento',
            ]
        );

        $this->assertSame(ChatbotAiPolicyService::ACTION_EXTRACT_ONLY, $decision['action']);
        $this->assertSame('specialized_intent_extract_only', $decision['reason']);
    }

    private function settings(
        bool $autoReplyEnabled = false,
        float $threshold = 0.75,
        int $handoffLimit = 2
    ): CompanyBotSetting {
        return new CompanyBotSetting([
            'company_id' => 10,
            'ai_chatbot_auto_reply_enabled' => $autoReplyEnabled,
            'ai_chatbot_confidence_threshold' => $threshold,
            'ai_chatbot_handoff_repeat_limit' => $handoffLimit,
        ]);
    }

    private function conversation(int $id): Conversation
    {
        $conversation = new Conversation();
        $conversation->id = $id;
        $conversation->company_id = 10;
        $conversation->bot_flow = 'main';
        $conversation->bot_step = 'menu';

        return $conversation;
    }
}

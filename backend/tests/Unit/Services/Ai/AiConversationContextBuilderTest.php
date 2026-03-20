<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiConversationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConversationContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_uses_only_recent_messages_from_same_conversation(): void
    {
        config()->set('ai.history_messages_limit', 2);

        $company = Company::create(['name' => 'Empresa Builder AI']);
        $user = User::create([
            'name' => 'User Builder AI',
            'email' => 'user-builder-ai@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        $otherConversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Primeira mensagem',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => null,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'Resposta anterior',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem mais recente',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $otherConversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem de outra conversa',
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, 'Prompt da empresa');

        $this->assertCount(3, $context);
        $this->assertSame(AiMessage::ROLE_SYSTEM, $context[0]['role']);
        $this->assertSame('Prompt da empresa', $context[0]['content']);
        $this->assertSame(AiMessage::ROLE_ASSISTANT, $context[1]['role']);
        $this->assertSame('Resposta anterior', $context[1]['content']);
        $this->assertSame(AiMessage::ROLE_USER, $context[2]['role']);
        $this->assertSame('Mensagem mais recente', $context[2]['content']);
    }
}

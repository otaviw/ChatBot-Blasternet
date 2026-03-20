<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_bot_setting_defaults_keep_ai_disabled(): void
    {
        $company = Company::create(['name' => 'Empresa AI Default']);

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
        ]);

        $settings->refresh();

        $this->assertFalse((bool) $settings->ai_enabled);
        $this->assertFalse((bool) $settings->ai_internal_chat_enabled);
        $this->assertFalse((bool) $settings->ai_chatbot_auto_reply_enabled);
        $this->assertNull($settings->ai_provider);
        $this->assertNull($settings->ai_model);
        $this->assertNull($settings->ai_system_prompt);
        $this->assertNull($settings->ai_temperature);
        $this->assertNull($settings->ai_max_response_tokens);
    }

    public function test_ai_conversations_and_messages_are_isolated_per_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa AI A']);
        $companyB = Company::create(['name' => 'Empresa AI B']);

        $userA = User::create([
            'name' => 'User AI A',
            'email' => 'user-ai-a@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $userB = User::create([
            'name' => 'User AI B',
            'email' => 'user-ai-b@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);

        $conversationA = AiConversation::create([
            'company_id' => $companyA->id,
            'opened_by_user_id' => $userA->id,
            'origin' => 'internal_chat',
            'title' => 'Thread AI A',
        ]);

        $conversationB = AiConversation::create([
            'company_id' => $companyB->id,
            'opened_by_user_id' => $userB->id,
            'origin' => 'chatbot',
            'title' => 'Thread AI B',
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversationA->id,
            'user_id' => $userA->id,
            'role' => 'user',
            'content' => 'Mensagem empresa A',
            'provider' => 'test',
            'model' => 'test-model',
            'response_time_ms' => 120,
            'meta' => ['source' => 'test'],
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversationB->id,
            'user_id' => $userB->id,
            'role' => 'user',
            'content' => 'Mensagem empresa B',
            'provider' => 'test',
            'model' => 'test-model',
            'response_time_ms' => 150,
            'meta' => ['source' => 'test'],
        ]);

        $companyAConversations = $companyA->aiConversations()->with('messages')->get();
        $companyBConversations = $companyB->aiConversations()->with('messages')->get();

        $this->assertCount(1, $companyAConversations);
        $this->assertCount(1, $companyBConversations);
        $this->assertSame((int) $conversationA->id, (int) $companyAConversations->first()->id);
        $this->assertSame((int) $conversationB->id, (int) $companyBConversations->first()->id);
        $this->assertSame(1, $companyAConversations->first()->messages->count());
        $this->assertSame(1, $companyBConversations->first()->messages->count());
    }
}

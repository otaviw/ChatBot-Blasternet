<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\Ai\ChatbotAiDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotAiDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_should_use_ai_returns_false_for_disabled_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Decision Disabled']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_DISABLED,
        ]);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertFalse($service->shouldUseAi($company));
    }

    public function test_should_use_ai_returns_true_for_always_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Decision Always']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_ALWAYS,
        ]);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertTrue($service->shouldUseAi($company));
    }

    public function test_should_use_ai_returns_false_for_fallback_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Decision Fallback']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_FALLBACK,
        ]);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertFalse($service->shouldUseAi($company));
    }

    public function test_should_use_ai_returns_false_for_outside_business_hours_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Decision Outside']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_OUTSIDE_BUSINESS_HOURS,
        ]);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertFalse($service->shouldUseAi($company));
    }

    public function test_get_mode_returns_disabled_when_no_settings_exist(): void
    {
        $company = Company::create(['name' => 'Empresa Decision No Settings']);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertSame(ChatbotAiDecisionService::MODE_DISABLED, $service->getMode($company));
        $this->assertFalse($service->shouldUseAi($company));
    }

    public function test_get_mode_returns_disabled_when_mode_is_invalid(): void
    {
        $company = Company::create(['name' => 'Empresa Decision Invalid']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => 'invalid_mode',
        ]);

        $service = $this->app->make(ChatbotAiDecisionService::class);

        $this->assertSame(ChatbotAiDecisionService::MODE_DISABLED, $service->getMode($company));
        $this->assertFalse($service->shouldUseAi($company));
    }
}


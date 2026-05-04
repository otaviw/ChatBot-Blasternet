<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\Ai\ChatbotAiGuardService;
use App\Services\Ai\ResellerAiCompanyPermissionService;
use PHPUnit\Framework\TestCase;

class ChatbotAiGuardServiceTest extends TestCase
{
    public function test_global_flag_disabled_blocks_ai(): void
    {
        $service = $this->makeGuardService(globalFlag: false, permissionAllowed: true);
        $company = $this->makeCompany(aiEnabled: true);

        $result = $service->gateResult($company);

        $this->assertFalse($result['allowed']);
        $this->assertContains('global_feature_disabled', $result['reasons']);
    }

    public function test_reseller_not_authorized_blocks_ai(): void
    {
        $service = $this->makeGuardService(globalFlag: true, permissionAllowed: false);
        $company = $this->makeCompany(aiEnabled: true);

        $result = $service->gateResult($company);

        $this->assertFalse($result['allowed']);
        $this->assertContains('reseller_not_authorized', $result['reasons']);
    }

    public function test_company_disabled_blocks_ai(): void
    {
        $service = $this->makeGuardService(globalFlag: true, permissionAllowed: true);
        $company = $this->makeCompany(aiEnabled: false);

        $result = $service->gateResult($company);

        $this->assertFalse($result['allowed']);
        $this->assertContains('company_ai_disabled', $result['reasons']);
    }

    public function test_all_gates_enabled_allows_ai(): void
    {
        $service = $this->makeGuardService(globalFlag: true, permissionAllowed: true);
        $company = $this->makeCompany(aiEnabled: true);

        $this->assertTrue($service->canUseAiForBot($company));
    }

    public function test_safe_defaults_block_ai(): void
    {
        $service = $this->makeGuardService(globalFlag: false, permissionAllowed: false);
        $company = new Company(['name' => 'Empresa Default Segura']);
        $company->id = 99;
        $company->reseller_id = 88;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 99,
            'ai_chatbot_enabled' => false,
            'ai_chatbot_shadow_mode' => false,
            'ai_chatbot_sandbox_enabled' => false,
        ]));

        $result = $service->gateResult($company);

        $this->assertFalse($result['allowed']);
        $this->assertContains('global_feature_disabled', $result['reasons']);
        $this->assertContains('reseller_not_authorized', $result['reasons']);
        $this->assertContains('company_ai_disabled', $result['reasons']);
    }

    private function makeCompany(bool $aiEnabled): Company
    {
        $company = new Company(['name' => 'Empresa Teste Guard']);
        $company->id = 10;
        $company->reseller_id = 20;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'ai_chatbot_enabled' => $aiEnabled,
            'ai_chatbot_shadow_mode' => false,
            'ai_chatbot_sandbox_enabled' => false,
            'ai_chatbot_confidence_threshold' => 0.75,
            'ai_chatbot_handoff_repeat_limit' => 2,
        ]));

        return $company;
    }

    private function makeGuardService(bool $globalFlag, bool $permissionAllowed): ChatbotAiGuardService
    {
        $permissionService = new class($permissionAllowed) extends ResellerAiCompanyPermissionService
        {
            public function __construct(private readonly bool $allowed) {}

            public function isCompanyAllowed(?int $resellerId, int $companyId): bool
            {
                unset($resellerId, $companyId);

                return $this->allowed;
            }
        };

        return new ChatbotAiGuardService($permissionService, $globalFlag);
    }
}

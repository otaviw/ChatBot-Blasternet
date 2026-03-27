<?php

namespace Tests\Unit\Services\Ai\Tools;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Ai\Tools\CustomerByPhoneTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerByPhoneToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_returns_customer_data_for_same_company_phone(): void
    {
        $company = Company::create(['name' => 'Empresa Tool A']);
        $otherCompany = Company::create(['name' => 'Empresa Tool B']);

        Conversation::create([
            'company_id' => $otherCompany->id,
            'customer_phone' => '5511988887777',
            'customer_name' => 'Cliente Outra Empresa',
            'status' => 'open',
            'bot_context' => ['plan' => 'Outro Plano'],
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511988887777',
            'customer_name' => 'Cliente Correto',
            'status' => 'open',
            'bot_context' => ['plan' => 'Plano Premium'],
        ]);

        $tool = $this->app->make(CustomerByPhoneTool::class);
        $result = $tool->execute([
            'company_id' => (int) $company->id,
            'phone' => '+55 (11) 98888-7777',
        ]);

        $this->assertTrue((bool) ($result['found'] ?? false));
        $this->assertSame('Cliente Correto', $result['name'] ?? null);
        $this->assertSame('Plano Premium', $result['plan'] ?? null);
    }

    public function test_tool_returns_not_found_when_phone_does_not_exist_for_company(): void
    {
        $company = Company::create(['name' => 'Empresa Tool C']);
        $otherCompany = Company::create(['name' => 'Empresa Tool D']);

        Conversation::create([
            'company_id' => $otherCompany->id,
            'customer_phone' => '5511977776666',
            'customer_name' => 'Cliente Outra Empresa',
            'status' => 'open',
        ]);

        $tool = $this->app->make(CustomerByPhoneTool::class);
        $result = $tool->execute([
            'company_id' => (int) $company->id,
            'phone' => '5511977776666',
        ]);

        $this->assertFalse((bool) ($result['found'] ?? true));
        $this->assertNull($result['name'] ?? null);
        $this->assertNull($result['plan'] ?? null);
    }
}


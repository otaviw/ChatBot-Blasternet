<?php

namespace Tests\Unit\Services\Ai\Tools;

use App\Services\Ai\Tools\AiToolManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiToolManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_returns_registered_tools(): void
    {
        $manager = $this->app->make(AiToolManager::class);
        $tools = $manager->getAvailableTools();

        $this->assertNotEmpty($tools);
        $this->assertTrue(
            collect($tools)->contains(fn ($tool) => $tool->getName() === 'get_customer_by_phone')
        );

        $this->assertNotNull($manager->findTool('get_customer_by_phone'));
        $this->assertNotNull($manager->findTool('GET_CUSTOMER_BY_PHONE'));
        $this->assertNull($manager->findTool('tool_que_nao_existe'));
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Services\IxcApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class IxcApiServiceHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite nao habilitada neste ambiente.');
        }

        parent::setUp();
    }

    public function test_circuit_breaker_opens_after_consecutive_failures(): void
    {
        $company = Company::create([
            'name' => 'Empresa Breaker',
            'ixc_base_url' => 'https://ixc.local/webservice/v1',
            'ixc_api_token' => 'token-breaker',
            'ixc_self_signed' => true,
            'ixc_timeout_seconds' => 5,
            'ixc_enabled' => true,
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'down'], 500),
        ]);

        $service = app(IxcApiService::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '1', 'oper' => '=']);
            } catch (RuntimeException) {
            }
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integracao IXC temporariamente indisponivel');

        $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '1', 'oper' => '=']);
    }

    public function test_success_resets_breaker_failure_counter(): void
    {
        Cache::flush();

        $company = Company::create([
            'name' => 'Empresa Breaker Reset',
            'ixc_base_url' => 'https://ixc.local/webservice/v1',
            'ixc_api_token' => 'token-breaker-reset',
            'ixc_self_signed' => true,
            'ixc_timeout_seconds' => 5,
            'ixc_enabled' => true,
        ]);

        Http::fakeSequence()
            ->push(['error' => 'fail'], 500)
            ->push([
                'registros' => [
                    ['id' => 10, 'razao' => 'Cliente ok'],
                ],
                'total' => 1,
            ], 200);

        $service = app(IxcApiService::class);

        try {
            $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '10', 'oper' => '=']);
        } catch (RuntimeException) {
        }

        $result = $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '10', 'oper' => '=']);
        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['total'] ?? 0));
    }
}

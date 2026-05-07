<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Services\IxcApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $this->expectExceptionMessage('Integração IXC temporariamente indisponível');

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

    public function test_list_mode_tries_listar_and_falls_back_to_token_when_empty(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-listar-fallback');

        Http::fakeSequence()
            ->push(['registros' => [], 'total' => 0], 200)
            ->push(['registros' => [['id' => 55]], 'total' => 1], 200);

        $service = app(IxcApiService::class);
        $result = $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '0', 'oper' => '>=']);

        $this->assertSame(1, (int) ($result['total'] ?? 0));

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('listar', (string) ($recorded[0][0]->header('ixcsoft')[0] ?? ''));
        $this->assertSame('token-listar-fallback', (string) ($recorded[1][0]->header('ixcsoft')[0] ?? ''));
        $this->assertSame('Basic ' . base64_encode('token-listar-fallback'), (string) ($recorded[0][0]->header('Authorization')[0] ?? ''));
    }

    public function test_list_mode_does_not_fallback_when_first_response_has_records(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-listar-first-ok');

        Http::fake([
            '*' => Http::response(['registros' => [['id' => 77]], 'total' => 1], 200),
        ]);

        $service = app(IxcApiService::class);
        $result = $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '0', 'oper' => '>=']);

        $this->assertSame(1, (int) ($result['total'] ?? 0));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => (string) ($request->header('ixcsoft')[0] ?? '') === 'listar');
    }

    public function test_non_list_post_keeps_token_header_mode(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-write-mode');

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $service = app(IxcApiService::class);
        $result = $service->request($company, 'fn_areceber', ['id' => 1], 'post');

        $this->assertTrue((bool) ($result['ok'] ?? false));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => (string) ($request->header('ixcsoft')[0] ?? '') === 'token-write-mode');
    }

    public function test_debug_logs_mask_sensitive_headers(): void
    {
        Cache::flush();
        config(['ixc.debug_log' => true]);
        Log::spy();

        $company = $this->makeCompany('token-debug-sensitive');
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $service = app(IxcApiService::class);
        $service->request($company, 'fn_areceber', ['id' => 1], 'post');

        Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context): bool {
            if ($message !== 'ixc.request.debug') {
                return false;
            }

            $serialized = json_encode($context);
            if (! is_string($serialized)) {
                return false;
            }

            return ($context['headers']['Authorization'] ?? null) === 'Basic ***'
                && ! str_contains($serialized, 'token-debug-sensitive');
        })->atLeast()->once();
    }

    public function test_provider_type_error_throws_runtime_exception_on_token_mode(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-provider-error');

        Http::fake([
            '*' => Http::response([
                'type' => 'error',
                'message' => 'Recurso cliente nao esta disponivel!',
            ], 200),
        ]);

        $service = app(IxcApiService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recurso cliente nao esta disponivel!');
        $service->request($company, 'fn_areceber', ['qtype' => 'fn_areceber.id', 'query' => '1', 'oper' => '=']);
    }

    public function test_provider_type_error_in_listar_mode_can_fallback_and_succeed_with_token_mode(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-listar-provider-error');

        Http::fakeSequence()
            ->push([
                'type' => 'error',
                'message' => 'Recurso cliente nao esta disponivel para modo listar',
            ], 200)
            ->push([
                'registros' => [
                    ['id' => 91, 'razao' => 'Cliente Fallback'],
                ],
                'total' => 1,
            ], 200);

        $service = app(IxcApiService::class);
        $result = $service->request($company, 'cliente', ['qtype' => 'cliente.id', 'query' => '0', 'oper' => '>=']);

        $this->assertSame(1, (int) ($result['total'] ?? 0));
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('listar', (string) ($recorded[0][0]->header('ixcsoft')[0] ?? ''));
        $this->assertSame('token-listar-provider-error', (string) ($recorded[1][0]->header('ixcsoft')[0] ?? ''));
    }

    public function test_provider_functional_errors_do_not_open_circuit_breaker(): void
    {
        Cache::flush();
        $company = $this->makeCompany('token-no-breaker-functional-error');

        Http::fake([
            '*' => Http::response([
                'type' => 'error',
                'message' => 'Recurso cliente nao esta disponivel!',
            ], 200),
        ]);

        $service = app(IxcApiService::class);
        for ($i = 0; $i < 6; $i++) {
            try {
                $service->request($company, 'fn_areceber', ['qtype' => 'fn_areceber.id', 'query' => '1', 'oper' => '=']);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Recurso cliente nao esta disponivel', $exception->getMessage());
            }
        }

        $this->assertTrue(true);
    }

    private function makeCompany(string $token): Company
    {
        return Company::create([
            'name' => 'Empresa IXC Teste',
            'ixc_base_url' => 'https://ixc.local/webservice/v1',
            'ixc_api_token' => $token,
            'ixc_self_signed' => true,
            'ixc_timeout_seconds' => 5,
            'ixc_enabled' => true,
        ]);
    }
}

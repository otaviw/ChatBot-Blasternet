<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\IxcCredentialsValidatorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IxcCredentialsValidatorServiceTest extends TestCase
{
    public function test_rejects_private_host_when_not_allowed(): void
    {
        config(['ixc.allow_private_hosts' => false]);
        Http::fake();

        $service = app(IxcCredentialsValidatorService::class);
        $result = $service->validate('http://127.0.0.1:8080/webservice/v1', 'token', true, 10);

        $this->assertFalse($result['ok']);
        $this->assertSame('URL da IXC invalida ou nao permitida.', $result['error']);
        Http::assertNothingSent();
    }

    public function test_returns_connection_error_on_timeout_or_network_failure(): void
    {
        Http::fake([
            '*' => static function (): void {
                throw new ConnectionException('timed out');
            },
        ]);

        $service = app(IxcCredentialsValidatorService::class);
        $result = $service->validate('https://ixc.local/webservice/v1', 'token', true, 5);

        $this->assertFalse($result['ok']);
        $this->assertSame('Nao foi possivel conectar ao servidor IXC.', $result['error']);
    }

    public function test_returns_invalid_token_message_for_401(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        $service = app(IxcCredentialsValidatorService::class);
        $unauthorized = $service->validate('https://ixc.local/webservice/v1', 'token', false, 10);

        $this->assertFalse($unauthorized['ok']);
        $this->assertSame('Token IXC invalido ou sem permissao.', $unauthorized['error']);
        $this->assertSame(401, $unauthorized['details']['status']);
    }

    public function test_returns_invalid_token_message_for_403(): void
    {
        Http::fake(['*' => Http::response([], 403)]);

        $service = app(IxcCredentialsValidatorService::class);
        $forbidden = $service->validate('https://ixc.local/webservice/v1', 'token', false, 10);

        $this->assertFalse($forbidden['ok']);
        $this->assertSame('Token IXC invalido ou sem permissao.', $forbidden['error']);
        $this->assertSame(403, $forbidden['details']['status']);
    }

    public function test_returns_http_status_error_for_other_non_success_codes(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $service = app(IxcCredentialsValidatorService::class);
        $result = $service->validate('https://ixc.local/webservice/v1', 'token', false, 10);

        $this->assertFalse($result['ok']);
        $this->assertSame('Servidor IXC respondeu com HTTP 500.', $result['error']);
        $this->assertSame(500, $result['details']['status']);
    }
}

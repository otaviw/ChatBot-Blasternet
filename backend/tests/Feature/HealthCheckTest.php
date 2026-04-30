<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
    }

    public function test_health_endpoint_returns_expected_shape(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure(['ok', 'ts', 'app'])
            ->assertJsonPath('ok', true);
    }

    public function test_health_endpoint_ts_is_valid_iso8601(): void
    {
        $response = $this->getJson('/api/health');

        $ts = $response->json('ts');
        $this->assertNotNull($ts);
        $this->assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $ts)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $ts),
            "Campo 'ts' não é um timestamp ISO 8601 válido: {$ts}"
        );
    }

    public function test_health_endpoint_returns_app_name(): void
    {
        config(['app.name' => 'TestApp']);

        $response = $this->getJson('/api/health');

        $response->assertJsonPath('app', 'TestApp');
    }

    public function test_health_endpoint_requires_no_authentication(): void
    {
        // Chamada sem qualquer cookie de sessão ou token — deve retornar 200
        $response = $this->getJson('/api/health');

        $response->assertOk();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestTrackingMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_request_id_when_header_is_missing(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $requestId = trim((string) $response->headers->get('X-Request-ID'));

        $this->assertNotSame('', $requestId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        );
    }

    public function test_keeps_valid_incoming_request_id(): void
    {
        $incoming = 'req-frontend-1234';

        $response = $this->withHeader('X-Request-ID', $incoming)->get('/up');

        $response->assertOk();
        $response->assertHeader('X-Request-ID', $incoming);
    }

    public function test_replaces_invalid_incoming_request_id(): void
    {
        $incoming = str_repeat('x', 200) . '<script>';

        $response = $this->withHeader('X-Request-ID', $incoming)->get('/up');

        $response->assertOk();
        $requestId = trim((string) $response->headers->get('X-Request-ID'));

        $this->assertNotSame($incoming, $requestId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        );
    }
}

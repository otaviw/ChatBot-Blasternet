<?php

namespace Tests\Unit\Logging;

use App\Http\Middleware\RequestTrackingMiddleware;
use App\Logging\AddRequestContext;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class AddRequestContextTest extends TestCase
{
    public function test_uses_request_attribute_request_id_when_available(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set(RequestTrackingMiddleware::ATTRIBUTE_KEY, 'req-attr-42');
        $this->app->instance('request', $request);

        $processor = new AddRequestContext();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'testing',
            level: Level::Info,
            message: 'context test',
        );

        $processed = $processor($record);

        $this->assertSame('req-attr-42', $processed->extra['request_id'] ?? null);
    }

    public function test_falls_back_to_header_request_id_when_attribute_missing(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Request-ID', 'req-header-77');
        $this->app->instance('request', $request);

        $processor = new AddRequestContext();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'testing',
            level: Level::Info,
            message: 'context test',
        );

        $processed = $processor($record);

        $this->assertSame('req-header-77', $processed->extra['request_id'] ?? null);
    }
}

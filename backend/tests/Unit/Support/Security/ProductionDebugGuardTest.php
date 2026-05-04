<?php

declare(strict_types=1);

use App\Exceptions\ConfigurationException;
use App\Support\Security\ProductionDebugGuard;

it('blocks debug mode in production', function (): void {
    expect(fn () => ProductionDebugGuard::assertSafe('production', true))
        ->toThrow(
            ConfigurationException::class,
            'APP_DEBUG=true em APP_ENV=production'
        );
});

it('allows debug mode outside production', function (): void {
    expect(fn () => ProductionDebugGuard::assertSafe('local', true))->not->toThrow(Exception::class);
    expect(fn () => ProductionDebugGuard::assertSafe('testing', true))->not->toThrow(Exception::class);
});

it('allows production when debug is disabled', function (): void {
    expect(fn () => ProductionDebugGuard::assertSafe('production', false))->not->toThrow(Exception::class);
});

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Exceptions\ApiExceptionHandler;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCompanyUser;
use App\Http\Middleware\EnsureSystemAdmin;
use App\Http\Middleware\RequestMetricsMiddleware;
use App\Http\Middleware\RequestTrackingMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\LogCriticalAction;
use App\Http\Middleware\ValidateWhatsAppWebhookSignature;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestTrackingMiddleware::class);
        $middleware->append(SecurityHeadersMiddleware::class);

        // throttle:api-global cobre qualquer rota em routes/api.php que não tenha
        // um limiter próprio mais restritivo. Os limiters específicos (login, bot-write,
        // inbox-read, etc.) continuam tendo prioridade por ter seus próprios buckets.
        $middleware->api(append: [
            'throttle:api-global',
            RequestMetricsMiddleware::class,
        ]);

        $middleware->alias([
            'admin'              => EnsureAdmin::class,
            'company.user'       => EnsureCompanyUser::class,
            'system.admin'       => EnsureSystemAdmin::class,
            'webhook.signature'  => ValidateWhatsAppWebhookSignature::class,
            'critical.audit'     => LogCriticalAction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionHandler::configure($exceptions);

        // Integração Sentry: reporta exceções 5xx e não-HTTP automaticamente.
        // Só ativa quando SENTRY_LARAVEL_DSN estiver definido no .env.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();

<?php

use App\Http\Middleware\RequestMetricsMiddleware;
use Illuminate\Support\Facades\Route;

// Healthcheck — sem autenticação, sem throttle, sem métricas.
// Usado por load balancers, orquestradores (K8s/ECS) e pipelines de deploy.
// Não consulta banco nem cache — resposta em memória pura.
Route::get('/health', static function () {
    return response()->json([
        'ok'  => true,
        'ts'  => now()->toISOString(),
        'app' => config('app.name'),
    ]);
})->withoutMiddleware(['throttle:api-global', RequestMetricsMiddleware::class]);

require __DIR__ . '/api/webhooks.php';
require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/realtime.php';
require __DIR__ . '/api/chat.php';
require __DIR__ . '/api/notifications.php';
require __DIR__ . '/api/support.php';
require __DIR__ . '/api/admin.php';
require __DIR__ . '/api/company.php';

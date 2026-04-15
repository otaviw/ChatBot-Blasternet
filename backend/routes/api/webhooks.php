<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Ambas as rotas levam o limiter webhook-inbound (500 req/min por IP).
// O POST tem adicionalmente o middleware de assinatura HMAC que bloqueia
// antes mesmo do limiter ser consumido quando a assinatura é inválida.

Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify'])
    ->middleware('throttle:webhook-inbound');

Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle'])
    ->middleware(['throttle:webhook-inbound', 'webhook.signature']);

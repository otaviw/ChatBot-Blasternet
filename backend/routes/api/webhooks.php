<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;


Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify'])
    ->middleware('throttle:webhook-inbound');

Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle'])
    ->middleware(['throttle:webhook-inbound', 'webhook.signature']);

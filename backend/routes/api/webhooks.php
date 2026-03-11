<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle']);

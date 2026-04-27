<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'ok' => true,
        'service' => 'chatbot-backend',
    ]);
});

Route::get('/health', function () {
    $checks = [];

    try {
        DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable) {
        $checks['database'] = 'fail';
    }

    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable) {
        $checks['redis'] = 'fail';
    }

    $allOk = ! in_array('fail', $checks, true);

    return response()->json([
        'status'    => $allOk ? 'healthy' : 'degraded',
        'checks'    => $checks,
        'timestamp' => now()->toISOString(),
    ], $allOk ? 200 : 500);
});

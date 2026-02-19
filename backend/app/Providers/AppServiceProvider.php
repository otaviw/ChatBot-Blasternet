<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('bot-write', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_BOT_WRITE', 60))
                ->by($this->limiterKey($request));
        });

        RateLimiter::for('simulation', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_SIMULATION', 30))
                ->by($this->limiterKey($request));
        });

        RateLimiter::for('inbox-read', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_INBOX_READ', 180))
                ->by($this->limiterKey($request));
        });
    }

    private function limiterKey(Request $request): string
    {
        $role = (string) $request->session()->get('role', 'guest');
        $companyId = (string) $request->session()->get('company_id', '0');

        return "{$request->ip()}|{$role}|{$companyId}";
    }
}

<?php

namespace App\Providers;

use App\Models\CompanyBotSetting;
use App\Models\Area;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Observers\CompanyBotSettingObserver;
use App\Observers\ConversationTransferObserver;
use App\Observers\MessageObserver;
use App\Policies\AreaPolicy;
use App\Policies\ConversationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Area::class, AreaPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Message::observe(MessageObserver::class);
        CompanyBotSetting::observe(CompanyBotSettingObserver::class);
        ConversationTransfer::observe(ConversationTransferObserver::class);

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

        RateLimiter::for('realtime-token', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_REALTIME_TOKEN', 30))
                ->by($this->realtimeLimiterKey($request));
        });

        RateLimiter::for('realtime-join', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_REALTIME_JOIN', 120))
                ->by($this->realtimeLimiterKey($request));
        });
    }

    private function limiterKey(Request $request): string
    {
        $role = (string) $request->session()->get('role', 'guest');
        $companyId = (string) $request->session()->get('company_id', '0');

        return "{$request->ip()}|{$role}|{$companyId}";
    }

    private function realtimeLimiterKey(Request $request): string
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : 'guest';

        return "{$request->ip()}|{$userId}";
    }
}

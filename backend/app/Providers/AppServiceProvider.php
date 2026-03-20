<?php

namespace App\Providers;

use App\Models\CompanyBotSetting;
use App\Models\Area;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Observers\ChatMessageObserver;
use App\Observers\CompanyBotSettingObserver;
use App\Observers\ConversationTransferObserver;
use App\Observers\MessageObserver;
use App\Observers\NotificationObserver;
use App\Observers\SupportTicketObserver;
use App\Observers\SupportTicketMessageObserver;
use App\Policies\AreaPolicy;
use App\Policies\ChatPolicy;
use App\Policies\ConversationPolicy;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Providers\AiProvider;
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
        $this->app->bind(AiProvider::class, function ($app): AiProvider {
            /** @var AiProviderResolver $resolver */
            $resolver = $app->make(AiProviderResolver::class);

            return $resolver->resolve();
        });
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
        Notification::observe(NotificationObserver::class);
        SupportTicket::observe(SupportTicketObserver::class);
        SupportTicketMessage::observe(SupportTicketMessageObserver::class);

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

        Gate::policy(ChatConversation::class, ChatPolicy::class);
        ChatMessage::observe(ChatMessageObserver::class);
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

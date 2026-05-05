<?php

declare(strict_types=1);


namespace App\Providers;

use App\Exceptions\ConfigurationException;
use App\Models\AiCompanyKnowledge;
use App\Models\AuditLog;
use App\Models\Appointment;
use App\Models\CompanyBotSetting;
use App\Models\Area;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\Notification;
use App\Models\QuickReply;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tag;
use App\Models\User;
use App\Observers\AiCompanyKnowledgeObserver;
use App\Observers\AppointmentObserver;
use App\Observers\ChatMessageObserver;
use App\Observers\CompanyBotSettingObserver;
use App\Observers\ConversationObserver;
use App\Observers\ConversationTransferObserver;
use App\Observers\MessageObserver;
use App\Observers\NotificationObserver;
use App\Observers\SupportTicketObserver;
use App\Observers\SupportTicketMessageObserver;
use App\Observers\UserObserver;
use App\Policies\AreaPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ChatPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\QuickReplyPolicy;
use App\Policies\TagPolicy;
use App\Console\Commands\GenerateSecretsCommand;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Providers\AiProvider;
use App\Support\Security\ProductionDebugGuard;
use Carbon\Carbon;
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
        ProductionDebugGuard::assertSafe(
            (string) config('app.env', 'production'),
            (bool) config('app.debug', false)
        );

        if (! $this->app->runningInConsole() && ! $this->app->environment('testing')) {
            $this->validateCriticalSecrets();
        }

        Carbon::setLocale('pt_BR');

        Gate::policy(Area::class, AreaPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(QuickReply::class, QuickReplyPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
        AiCompanyKnowledge::observe(AiCompanyKnowledgeObserver::class);
        Appointment::observe(AppointmentObserver::class);
        Message::observe(MessageObserver::class);
        Conversation::observe(ConversationObserver::class);
        CompanyBotSetting::observe(CompanyBotSettingObserver::class);
        ConversationTransfer::observe(ConversationTransferObserver::class);
        Notification::observe(NotificationObserver::class);
        SupportTicket::observe(SupportTicketObserver::class);
        SupportTicketMessage::observe(SupportTicketMessageObserver::class);
        User::observe(UserObserver::class);

        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email', '')));
            $key   = 'login|' . $request->ip() . '|' . $email;

            return Limit::perMinute((int) config('rate_limits.login', 5))
                ->by($key)
                ->response(fn () => response()->json([
                    'message' => 'Muitas tentativas de login. Aguarde 1 minuto e tente novamente.',
                    'errors'  => ['email' => ['Limite de tentativas atingido.']],
                ], 429));
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email', '')));
            $key   = 'pwd-reset|' . $request->ip() . '|' . $email;

            return Limit::perMinute((int) config('rate_limits.password_reset', 5))
                ->by($key)
                ->response(fn () => response()->json([
                    'message' => 'Muitas tentativas de recuperação de senha. Aguarde 1 minuto.',
                ], 429));
        });

        RateLimiter::for('webhook-inbound', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.webhook_inbound', 500))
                ->by('webhook|' . $request->ip());
        });

        RateLimiter::for('api-global', function (Request $request) {
            $user = $request->user();
            $key  = $user
                ? 'api|user:' . $user->id
                : 'api|ip:' . $request->ip();

            return Limit::perMinute((int) config('rate_limits.api_global', 600))
                ->by($key)
                ->response(fn () => response()->json([
                    'message' => 'Muitas requisições. Tente novamente em instantes.',
                ], 429));
        });

        RateLimiter::for('bot-write', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.bot_write', 60))
                ->by($this->limiterKey($request));
        });

        RateLimiter::for('ai-sandbox', function (Request $request) {
            $user = $request->user();
            $key  = $user ? "user:{$user->id}" : $this->limiterKey($request);

            return Limit::perMinute(20)->by($key);
        });

        RateLimiter::for('simulation', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.simulation', 30))
                ->by($this->limiterKey($request));
        });

        RateLimiter::for('inbox-read', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.inbox_read', 180))
                ->by($this->limiterKey($request));
        });

        RateLimiter::for('ixc-read', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.ixc_read', 90))
                ->by('ixc-read|' . $this->limiterKey($request))
                ->response(fn () => response()->json([
                    'message' => 'Muitas consultas IXC em pouco tempo. Aguarde alguns segundos e tente novamente.',
                ], 429));
        });

        RateLimiter::for('ixc-write', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.ixc_write', 30))
                ->by('ixc-write|' . $this->limiterKey($request))
                ->response(fn () => response()->json([
                    'message' => 'Muitas acoes IXC em pouco tempo. Aguarde alguns segundos e tente novamente.',
                ], 429));
        });

        RateLimiter::for('conversation-search', function (Request $request) {
            $user = $request->user();
            $identity = $user ? (string) $user->id : $this->limiterKey($request);

            return Limit::perMinute((int) config('rate_limits.conversation_search', 30))
                ->by("conversation-search|{$identity}");
        });

        RateLimiter::for('realtime-token', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.realtime_token', 30))
                ->by($this->realtimeLimiterKey($request));
        });

        RateLimiter::for('realtime-join', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.realtime_join', 120))
                ->by($this->realtimeLimiterKey($request));
        });

        Gate::policy(ChatConversation::class, ChatPolicy::class);
        ChatMessage::observe(ChatMessageObserver::class);
    }

    private function limiterKey(Request $request): string
    {
        $user = $request->user();
        
        if ($user) {
            return "{$request->ip()}|user:{$user->id}";
        }

        return "{$request->ip()}|guest";
    }

    private function realtimeLimiterKey(Request $request): string
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : 'guest';

        return "{$request->ip()}|{$userId}";
    }


    private function validateCriticalSecrets(): void
    {
        if ($this->app->environment('production')) {
            $this->assertSecret(
                (string) config('whatsapp.app_secret', ''),
                'WHATSAPP_APP_SECRET',
                'Obtenha em: Meta for Developers → Configurações do app → Básico → Chave secreta do app.'
            );
        }

        $this->assertSecret(
            (string) config('realtime.jwt.secret', ''),
            'REALTIME_JWT_SECRET',
            'Execute: php artisan app:generate-secrets --write'
        );

        $internalEmitUrl = trim((string) config('realtime.fallback.internal_emit_url', ''));
        if ($internalEmitUrl !== '') {
            $this->assertSecret(
                (string) config('realtime.fallback.internal_key', ''),
                'REALTIME_INTERNAL_KEY',
                'REALTIME_INTERNAL_EMIT_URL está configurado mas REALTIME_INTERNAL_KEY não. ' .
                'Execute: php artisan app:generate-secrets --write'
            );
        }
    }

    private function assertSecret(string $value, string $varName, string $hint): void
    {
        if ($value === '' || GenerateSecretsCommand::isPlaceholder($value)) {
            throw new ConfigurationException(
                "{$varName} não está configurado ou contém um valor de exemplo. {$hint}"
            );
        }
    }
}

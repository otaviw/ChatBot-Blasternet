<?php

declare(strict_types=1);


namespace App\Jobs;

use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishRealtimeEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public function backoff(): array
    {
        return [2, 5];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public array $envelope
    ) {
        $this->afterCommit();
    }

    public function handle(RealtimePublisher $publisher): void
    {
        $publisher->publishNow($this->envelope);
    }

    /**
     * Chamado após esgotar todas as tentativas.
     * Eventos realtime perdidos não bloqueiam o sistema, mas devem ser visíveis
     * nos logs para detectar instabilidade no transporte (Pusher, Ably, etc.).
     */
    public function failed(?\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error('PublishRealtimeEventJob: falhou após todas as tentativas.', [
            'event'           => $this->envelope['event'] ?? null,
            'channels'        => $this->envelope['channels'] ?? null,
            'attempts'        => $this->tries,
            'exception_class' => $exception !== null ? get_class($exception) : null,
            'exception'       => $exception?->getMessage(),
        ]);
    }
}

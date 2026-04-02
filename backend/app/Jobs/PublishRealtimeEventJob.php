<?php

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

    // tries = 1 significa que qualquer falha (realtime down, timeout) descarta o evento
    // se notificações críticas passarem por aqui, vai precisar de retry com backoff
    public int $tries = 1;

    public int $timeout = 5;

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
}

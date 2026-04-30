<?php

declare(strict_types=1);


namespace App\Services;

use App\Jobs\PublishRealtimeEventJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class RealtimePublisher
{
    /**
     * @param  array<int, string>  $rooms
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    public function publish(string $event, array $rooms, array $payload = [], array $meta = []): void
    {
        if (! config('realtime.enabled', true)) {
            return;
        }

        $envelope = $this->buildEnvelope($event, $rooms, $payload, $meta);
        if (! $envelope) {
            return;
        }

        $mode = (string) config('realtime.publish.mode', 'after_response');
        if ($mode === 'sync') {
            $this->publishNow($envelope);

            return;
        }

        if ($mode === 'after_response') {
            if (app()->runningInConsole()) {
                $this->publishNow($envelope);

                return;
            }

            app()->terminating(function () use ($envelope): void {
                $this->publishNow($envelope);
            });

            return;
        }

        PublishRealtimeEventJob::dispatch($envelope)
            ->onQueue((string) config('realtime.publish.queue', 'realtime'));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function publishNow(array $envelope): void
    {
        $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            Log::error('realtime.publish.encode_failed', [
                'event' => $envelope['event'] ?? null,
            ]);

            return;
        }

        $channel = (string) config('realtime.redis.channel', 'realtime.events');

        try {
            Redis::publish($channel, $encoded);
            Log::info('realtime.publish.redis', [
                'event' => $envelope['event'] ?? null,
                'rooms' => $envelope['rooms'] ?? [],
                'meta' => $envelope['meta'] ?? [],
            ]);

            return;
        } catch (Throwable $exception) {
            Log::warning('realtime.publish.redis_failed', [
                'event' => $envelope['event'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->fallbackInternalEmit($envelope);
    }

    /**
     * @param  array<int, string>  $rooms
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function buildEnvelope(string $event, array $rooms, array $payload, array $meta): ?array
    {
        $name = trim($event);
        if ($name === '') {
            return null;
        }

        $normalizedRooms = collect($rooms)
            ->map(fn ($room) => trim((string) $room))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalizedRooms === []) {
            return null;
        }

        $request = request();
        $defaultActor = auth()->id();

        $resolvedMeta = array_merge([
            'requestId' => $request?->headers->get('X-Request-Id') ?: (string) Str::uuid(),
            'timestamp' => now()->toISOString(),
            'actorId' => $defaultActor ? (int) $defaultActor : null,
        ], $meta);

        return [
            'event' => $name,
            'rooms' => $normalizedRooms,
            'payload' => $payload,
            'meta' => $resolvedMeta,
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function fallbackInternalEmit(array $envelope): void
    {
        $url = trim((string) config('realtime.fallback.internal_emit_url', ''));
        $key = trim((string) config('realtime.fallback.internal_key', ''));

        if ($url === '' || $key === '') {
            Log::error('realtime.publish.fallback_config_missing', [
                'event' => $envelope['event'] ?? null,
            ]);

            return;
        }

        $timeoutMs = max(100, (int) config('realtime.fallback.timeout_ms', 800));

        try {
            $response = Http::asJson()
                ->withHeaders(['X-INTERNAL-KEY' => $key])
                ->timeout($timeoutMs / 1000)
                ->post($url, $envelope);

            if ($response->failed()) {
                Log::error('realtime.publish.fallback_failed', [
                    'event' => $envelope['event'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return;
            }

            Log::info('realtime.publish.fallback_success', [
                'event' => $envelope['event'] ?? null,
                'rooms' => $envelope['rooms'] ?? [],
            ]);
        } catch (Throwable $exception) {
            Log::error('realtime.publish.fallback_exception', [
                'event' => $envelope['event'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

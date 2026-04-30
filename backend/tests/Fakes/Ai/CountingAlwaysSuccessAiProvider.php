<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class CountingAlwaysSuccessAiProvider implements AiProvider
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array{
     *     ok:bool,
     *     text:?string,
     *     error:mixed,
     *     meta:array<string, mixed>|null
     * }
     */
    public function reply(array $messages, array $options = []): array
    {
        unset($messages, $options);
        self::$calls++;

        return [
            'ok' => true,
            'text' => 'ok',
            'error' => null,
            'meta' => [
                'provider' => 'counting_success',
            ],
        ];
    }
}


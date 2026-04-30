<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class CountingAlwaysFailAiProvider implements AiProvider
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
            'ok' => false,
            'text' => null,
            'error' => 'counting_fail',
            'meta' => [
                'provider' => 'counting_fail',
                'message' => 'Falha simulada',
            ],
        ];
    }
}


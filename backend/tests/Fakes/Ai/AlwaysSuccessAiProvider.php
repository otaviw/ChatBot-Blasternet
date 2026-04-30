<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class AlwaysSuccessAiProvider implements AiProvider
{
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

        return [
            'ok' => true,
            'text' => 'Fallback anthropic success',
            'error' => null,
            'meta' => [
                'provider' => 'always_success',
                'model' => 'fake-fallback-model',
            ],
        ];
    }
}

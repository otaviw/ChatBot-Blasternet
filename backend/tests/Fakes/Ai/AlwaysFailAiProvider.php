<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class AlwaysFailAiProvider implements AiProvider
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
            'ok' => false,
            'text' => null,
            'error' => 'always_fail_provider',
            'meta' => [
                'provider' => 'always_fail',
                'message' => 'Provider principal falhou.',
            ],
        ];
    }
}

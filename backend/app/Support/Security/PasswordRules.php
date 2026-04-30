<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function required(int $max = 255): array
    {
        return [
            'required',
            'string',
            "max:{$max}",
            self::strong(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function optional(int $max = 255): array
    {
        return [
            'nullable',
            'string',
            "max:{$max}",
            self::strong(),
        ];
    }

    public static function strong(): Password
    {
        return Password::min(8)
            ->letters()
            ->numbers();
    }
}

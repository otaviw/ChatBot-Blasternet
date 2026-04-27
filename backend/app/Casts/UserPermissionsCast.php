<?php

namespace App\Casts;

use App\Support\UserPermissions;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Cast para a coluna `users.permissions`.
 *
 * Garante que apenas strings de permissão conhecidas (UserPermissions::ALL)
 * sejam armazenadas e lidas. Permissões desconhecidas são descartadas silenciosamente.
 *
 * Estrutura esperada: lista de strings — ex: ['page_inbox', 'page_contacts']
 * null → usuário usa os defaults do sistema (ver UserPermissions::resolve)
 *
 * @implements CastsAttributes<list<string>|null, list<string>|null>
 */
class UserPermissionsCast implements CastsAttributes
{
    /**
     * Lê do banco e filtra strings de permissão inválidas.
     * Retorna null (usar defaults) se o valor armazenado for inválido.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            Log::warning('UserPermissionsCast: valor inválido lido do banco.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($decoded),
            ]);

            return null;
        }

        return array_values(array_intersect($decoded, UserPermissions::ALL));
    }

    /**
     * Valida e persiste apenas permissões conhecidas.
     * Strings inválidas são descartadas; valores não-array retornam null.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            Log::warning('UserPermissionsCast: tentativa de salvar valor não-array.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($value),
            ]);

            return null;
        }

        $filtered = array_values(array_intersect($value, UserPermissions::ALL));

        return json_encode($filtered);
    }
}

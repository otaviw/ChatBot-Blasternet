<?php

declare(strict_types=1);


namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        $builder->where($model->getTable() . '.company_id', (int) $user->company_id ?: 0);
    }
}

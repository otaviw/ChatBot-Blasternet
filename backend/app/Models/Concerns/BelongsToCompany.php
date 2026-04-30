<?php

declare(strict_types=1);


namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());
    }

    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }
}

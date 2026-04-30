<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReply extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id', 'title', 'text'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
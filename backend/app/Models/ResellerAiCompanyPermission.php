<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerAiCompanyPermission extends Model
{
    protected $table = 'reseller_ai_company_permissions';

    protected $fillable = [
        'reseller_id',
        'company_id',
        'ai_chatbot_allowed',
        'allowed_by_user_id',
        'allowed_at',
        'notes',
    ];

    protected $casts = [
        'reseller_id' => 'integer',
        'company_id' => 'integer',
        'ai_chatbot_allowed' => 'boolean',
        'allowed_by_user_id' => 'integer',
        'allowed_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function allowedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allowed_by_user_id');
    }
}

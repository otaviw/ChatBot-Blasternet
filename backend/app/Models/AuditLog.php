<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'actor_role',
        'actor_company_id',
        'action',
        'method',
        'route',
        'ip_address',
        'user_agent',
        'changes',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}


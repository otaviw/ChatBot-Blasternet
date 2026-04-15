<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'company_id',
        'reseller_id',
        'action',
        'entity_type',
        'entity_id',
        'old_data',
        'new_data',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('AuditLog is immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('AuditLog cannot be deleted.');
        });
    }
}


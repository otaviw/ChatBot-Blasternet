<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;

class CriticalAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'company_id',
        'action',
        'ip_address',
        'user_agent',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('CriticalAuditLog is immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('CriticalAuditLog cannot be deleted.');
        });
    }
}

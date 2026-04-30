<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'storage_provider',
        'storage_key',
        'url',
        'mime_type',
        'size_bytes',
    ];

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }
}

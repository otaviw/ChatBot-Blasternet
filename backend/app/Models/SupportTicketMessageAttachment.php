<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessageAttachment extends Model
{
    protected $fillable = [
        'support_ticket_message_id',
        'storage_provider',
        'storage_key',
        'url',
        'mime_type',
        'size_bytes',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }
}

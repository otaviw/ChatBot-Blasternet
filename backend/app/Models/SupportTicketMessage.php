<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketMessage extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';

    protected $fillable = [
        'support_ticket_id',
        'sender_user_id',
        'type',
        'content',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketMessageAttachment::class, 'support_ticket_message_id');
    }
}

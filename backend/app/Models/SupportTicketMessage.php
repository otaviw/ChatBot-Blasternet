<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments()
    {
        return $this->hasMany(SupportTicketMessageAttachment::class, 'support_ticket_message_id');
    }
}

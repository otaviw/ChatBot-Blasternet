<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function message()
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }
}

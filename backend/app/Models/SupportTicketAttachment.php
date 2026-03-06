<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function supportTicket()
    {
        return $this->belongsTo(SupportTicket::class);
    }
}

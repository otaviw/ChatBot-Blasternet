<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use BelongsToCompany;
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'ticket_number',
        'company_id',
        'requester_user_id',
        'requester_name',
        'requester_contact',
        'requester_company_name',
        'subject',
        'message',
        'status',
        'managed_by_user_id',
        'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function managedBy()
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function attachments()
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}

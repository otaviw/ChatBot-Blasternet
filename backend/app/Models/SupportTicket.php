<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        $attachments = $this->relationLoaded('attachments')
            ? $this->attachments->map(fn ($a) => [
                'id'         => (int) $a->id,
                'url'        => null,
                'mime_type'  => $a->mime_type,
                'size_bytes' => $a->size_bytes ? (int) $a->size_bytes : null,
            ])->values()->all()
            : [];

        return [
            'id'                     => (int) $this->id,
            'ticket_number'          => (int) ($this->ticket_number ?: $this->id),
            'company_id'             => $this->company_id ? (int) $this->company_id : null,
            'company_name'           => $this->company?->name ?? $this->requester_company_name,
            'requester_user_id'      => $this->requester_user_id ? (int) $this->requester_user_id : null,
            'requester_name'         => $this->requester_name,
            'requester_contact'      => $this->requester_contact,
            'requester_company_name' => $this->requester_company_name,
            'subject'                => $this->subject,
            'message'                => $this->message,
            'status'                 => $this->status,
            'managed_by_user_id'     => $this->managed_by_user_id ? (int) $this->managed_by_user_id : null,
            'managed_by_name'        => $this->managedBy?->name,
            'closed_at'              => $this->closed_at,
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,
            'attachments'            => $attachments,
        ];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationTransfer extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'conversation_id',
        'from_assigned_type',
        'from_assigned_id',
        'to_assigned_type',
        'to_assigned_id',
        'transferred_by_user_id',
    ];

    protected $casts = [
        'from_assigned_id' => 'integer',
        'to_assigned_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function transferredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by_user_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_assigned_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_assigned_id');
    }

    public function fromArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'from_assigned_id');
    }

    public function toArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'to_assigned_id');
    }
}

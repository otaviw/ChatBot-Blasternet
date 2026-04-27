<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

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

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transferredByUser()
    {
        return $this->belongsTo(User::class, 'transferred_by_user_id');
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_assigned_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_assigned_id');
    }

    public function fromArea()
    {
        return $this->belongsTo(Area::class, 'from_assigned_id');
    }

    public function toArea()
    {
        return $this->belongsTo(Area::class, 'to_assigned_id');
    }
}

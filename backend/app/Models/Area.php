<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'name',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'area_user');
    }

    public function currentConversations()
    {
        return $this->hasMany(Conversation::class, 'current_area_id');
    }
}


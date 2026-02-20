<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    protected $fillable = ['company_id', 'title', 'text'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
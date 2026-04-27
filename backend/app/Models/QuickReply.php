<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id', 'title', 'text'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
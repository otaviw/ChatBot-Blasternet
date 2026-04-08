<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiCompanyKnowledge extends Model
{
    use HasFactory;
    protected $table = 'ai_company_knowledge';

    protected $fillable = [
        'company_id',
        'title',
        'content',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function chunks()
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'ai_knowledge_item_id');
    }
}


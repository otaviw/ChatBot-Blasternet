<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiCompanyKnowledge extends Model
{
    use HasFactory;

    public const INDEXING_PENDING = 'pending';
    public const INDEXING_INDEXED = 'indexed';
    public const INDEXING_FAILED  = 'failed';

    protected $table = 'ai_company_knowledge';

    protected $fillable = [
        'company_id',
        'title',
        'content',
        'is_active',
        'indexing_status',
        'indexed_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'indexed_at'     => 'datetime',
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


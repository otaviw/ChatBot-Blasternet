<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeChunk extends Model
{
    use BelongsToCompany;
    protected $table = 'ai_knowledge_chunks';

    protected $fillable = [
        'ai_knowledge_item_id',
        'company_id',
        'title',
        'chunk_content',
        'chunk_index',
        'embedding',
        'embedding_model',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'company_id' => 'integer',
        'ai_knowledge_item_id' => 'integer',
    ];

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(AiCompanyKnowledge::class, 'ai_knowledge_item_id');
    }
}

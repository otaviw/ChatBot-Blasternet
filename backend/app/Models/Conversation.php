<?php

namespace App\Models;

use App\Support\ConversationHandlingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Conversation extends Model
{
    /** @var array<int, string>|null */
    private ?array $pendingTagNames = null;

    protected $fillable = [
        'company_id',
        'customer_phone',
        'customer_name',
        'status',
        'assigned_type',
        'assigned_id',
        'current_area_id',
        'handling_mode',
        'assigned_user_id',
        'assigned_area',
        'assumed_at',
        'closed_at',
        'bot_flow',
        'bot_step',
        'bot_context',
        'bot_last_interaction_at',
        'last_user_message_at',
        'last_business_message_at',
        'tags',
    ];

    protected $casts = [
        'assumed_at' => 'datetime',
        'closed_at' => 'datetime',
        'bot_last_interaction_at' => 'datetime',
        'last_user_message_at' => 'datetime',
        'last_business_message_at' => 'datetime',
        'bot_context' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $conversation): void {
            $conversation->syncPendingTags();
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_id');
    }

    public function assignedArea()
    {
        return $this->belongsTo(Area::class, 'assigned_id');
    }

    public function currentArea()
    {
        return $this->belongsTo(Area::class, 'current_area_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag');
    }

    public function transferHistory()
    {
        return $this->hasMany(ConversationTransfer::class)->latest('id');
    }

    public function isManualMode(): bool
    {
        return ConversationHandlingMode::isHuman($this->handling_mode);
    }

    public function getHandlingModeAttribute(?string $value): string
    {
        return ConversationHandlingMode::normalize($value);
    }

    public function setHandlingModeAttribute(?string $value): void
    {
        $this->attributes['handling_mode'] = ConversationHandlingMode::normalize($value);
    }

    public function setTagsAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->pendingTagNames = [];
            return;
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = collect($items)
            ->map(fn ($item): string => mb_strtolower(trim((string) $item)))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();

        $this->pendingTagNames = $normalized;
    }

    private function syncPendingTags(): void
    {
        if ($this->pendingTagNames === null) {
            return;
        }

        $tagNames = $this->pendingTagNames;
        $this->pendingTagNames = null;

        if ((int) $this->company_id <= 0) {
            return;
        }

        if ($tagNames === []) {
            $this->tags()->sync([]);
            return;
        }

        $tagIds = collect($tagNames)
            ->map(function (string $name): int {
                $tag = Tag::query()->firstOrCreate(
                    [
                        'company_id' => (int) $this->company_id,
                        'name' => $name,
                    ],
                    [
                        'color' => '#6b7280',
                    ]
                );

                return (int) $tag->id;
            })
            ->values()
            ->all();

        $this->tags()->sync($tagIds);
    }
}

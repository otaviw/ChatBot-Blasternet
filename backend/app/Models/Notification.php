<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'module',
        'title',
        'text',
        'is_read',
        'reference_type',
        'reference_id',
        'reference_meta',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'reference_meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

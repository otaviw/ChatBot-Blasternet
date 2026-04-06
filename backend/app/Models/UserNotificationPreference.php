<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    protected $table = 'user_notification_preferences';

    protected $fillable = ['user_id', 'preferences'];

    protected $casts = [
        'preferences' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

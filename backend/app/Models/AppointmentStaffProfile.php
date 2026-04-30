<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentStaffProfile extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'user_id',
        'display_name',
        'is_bookable',
        'slot_interval_minutes',
        'booking_min_notice_minutes',
        'booking_max_advance_days',
    ];

    protected $casts = [
        'is_bookable' => 'boolean',
        'slot_interval_minutes' => 'integer',
        'booking_min_notice_minutes' => 'integer',
        'booking_max_advance_days' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(AppointmentWorkingHour::class, 'staff_profile_id')
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time');
    }

    public function timeOffs(): HasMany
    {
        return $this->hasMany(AppointmentTimeOff::class, 'staff_profile_id')
            ->orderBy('starts_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'staff_profile_id')
            ->latest('starts_at');
    }
}


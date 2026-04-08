<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentStaffProfile extends Model
{
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

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workingHours()
    {
        return $this->hasMany(AppointmentWorkingHour::class, 'staff_profile_id')
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time');
    }

    public function timeOffs()
    {
        return $this->hasMany(AppointmentTimeOff::class, 'staff_profile_id')
            ->orderBy('starts_at');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'staff_profile_id')
            ->latest('starts_at');
    }
}


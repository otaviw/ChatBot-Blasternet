<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentWorkingHour extends Model
{
    protected $fillable = [
        'company_id',
        'staff_profile_id',
        'day_of_week',
        'start_time',
        'break_start_time',
        'break_end_time',
        'end_time',
        'slot_interval_minutes',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'slot_interval_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function staffProfile()
    {
        return $this->belongsTo(AppointmentStaffProfile::class, 'staff_profile_id');
    }
}

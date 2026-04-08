<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentService extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'max_bookings_per_slot',
        'is_active',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'max_bookings_per_slot' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }
}


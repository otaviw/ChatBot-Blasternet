<?php

namespace App\Models;

use App\Support\AppointmentSource;
use App\Support\AppointmentStatus;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'company_id',
        'service_id',
        'staff_profile_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'starts_at',
        'ends_at',
        'effective_start_at',
        'effective_end_at',
        'service_duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'status',
        'source',
        'notes',
        'cancelled_at',
        'cancelled_reason',
        'reminder_sent_at',
        'rescheduled_from_appointment_id',
        'created_by_user_id',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'effective_start_at' => 'datetime',
        'effective_end_at' => 'datetime',
        'service_duration_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'cancelled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function service()
    {
        return $this->belongsTo(AppointmentService::class, 'service_id');
    }

    public function staffProfile()
    {
        return $this->belongsTo(AppointmentStaffProfile::class, 'staff_profile_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rescheduledFrom()
    {
        return $this->belongsTo(self::class, 'rescheduled_from_appointment_id');
    }

    public function events()
    {
        return $this->hasMany(AppointmentEvent::class)->latest('id');
    }

    public function setStatusAttribute(?string $value): void
    {
        $normalized = mb_strtolower(trim((string) $value));
        $this->attributes['status'] = in_array($normalized, AppointmentStatus::all(), true)
            ? $normalized
            : AppointmentStatus::PENDING;
    }

    public function setSourceAttribute(?string $value): void
    {
        $normalized = mb_strtolower(trim((string) $value));
        $this->attributes['source'] = in_array($normalized, AppointmentSource::all(), true)
            ? $normalized
            : AppointmentSource::WHATSAPP;
    }
}


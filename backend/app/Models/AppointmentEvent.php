<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'appointment_id',
        'event_type',
        'performed_by_user_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}


<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppointmentTimeOff extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'staff_profile_id',
        'starts_at',
        'ends_at',
        'is_all_day',
        'reason',
        'source',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_all_day' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function staffProfile()
    {
        return $this->belongsTo(AppointmentStaffProfile::class, 'staff_profile_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}


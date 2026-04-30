<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentWorkingHour extends Model
{
    use BelongsToCompany;
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(AppointmentStaffProfile::class, 'staff_profile_id');
    }
}

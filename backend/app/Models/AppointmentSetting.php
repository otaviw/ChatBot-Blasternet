<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentSetting extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id',
        'timezone',
        'slot_interval_minutes',
        'booking_min_notice_minutes',
        'booking_max_advance_days',
        'cancellation_min_notice_minutes',
        'reschedule_min_notice_minutes',
        'allow_customer_choose_staff',
    ];

    protected $casts = [
        'slot_interval_minutes' => 'integer',
        'booking_min_notice_minutes' => 'integer',
        'booking_max_advance_days' => 'integer',
        'cancellation_min_notice_minutes' => 'integer',
        'reschedule_min_notice_minutes' => 'integer',
        'allow_customer_choose_staff' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}


<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timezone'                        => ['required', 'string', 'max:64'],
            'slot_interval_minutes'           => ['required', 'integer', 'min:5', 'max:120'],
            'booking_min_notice_minutes'      => ['required', 'integer', 'min:0', 'max:10080'],
            'booking_max_advance_days'        => ['required', 'integer', 'min:0', 'max:365'],
            'cancellation_min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'reschedule_min_notice_minutes'   => ['required', 'integer', 'min:0', 'max:10080'],
            'allow_customer_choose_staff'     => ['required', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name'               => ['nullable', 'string', 'max:120'],
            'is_bookable'                => ['required', 'boolean'],
            'slot_interval_minutes'      => ['nullable', 'integer', 'min:5', 'max:120'],
            'booking_min_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'booking_max_advance_days'   => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}

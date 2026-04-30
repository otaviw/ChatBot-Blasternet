<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceAppointmentWorkingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours'                          => ['required', 'array', 'max:70'],
            'hours.*.day_of_week'            => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.start_time'             => ['required', 'date_format:H:i'],
            'hours.*.break_start_time'       => ['nullable', 'date_format:H:i'],
            'hours.*.break_end_time'         => ['nullable', 'date_format:H:i'],
            'hours.*.end_time'               => ['required', 'date_format:H:i'],
            'hours.*.slot_interval_minutes'  => ['nullable', 'integer', 'min:5', 'max:120'],
            'hours.*.is_active'              => ['sometimes', 'boolean'],
        ];
    }
}

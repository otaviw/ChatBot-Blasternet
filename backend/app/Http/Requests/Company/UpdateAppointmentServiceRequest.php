<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:120'],
            'description'           => ['nullable', 'string', 'max:1000'],
            'duration_minutes'      => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes'  => ['required', 'integer', 'min:0', 'max:240'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1', 'max:10'],
            'is_active'             => ['required', 'boolean'],
        ];
    }
}

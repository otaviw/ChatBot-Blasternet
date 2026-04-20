<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ListAppointmentTimeOffsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from'         => ['nullable', 'date'],
            'date_to'           => ['nullable', 'date'],
            'staff_profile_id'  => ['nullable', 'integer', 'min:1'],
        ];
    }
}

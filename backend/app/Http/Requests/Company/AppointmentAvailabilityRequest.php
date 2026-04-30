<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_id'       => ['required', 'integer', 'min:1'],
            'date'             => ['required', 'date'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

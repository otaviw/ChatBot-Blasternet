<?php

namespace App\Http\Requests\Company;

use App\Support\AppointmentSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
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
            'staff_profile_id' => ['required', 'integer', 'min:1'],
            'starts_at'        => ['required', 'date'],
            'customer_name'    => ['nullable', 'string', 'max:191'],
            'customer_phone'   => ['required', 'string', 'max:40'],
            'customer_email'   => ['nullable', 'email', 'max:191'],
            'notes'            => ['nullable', 'string', 'max:1000'],
            'source'           => ['sometimes', Rule::in(AppointmentSource::all())],
            'meta'             => ['sometimes', 'array'],
        ];
    }
}

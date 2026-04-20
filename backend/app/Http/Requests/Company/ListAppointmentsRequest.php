<?php

namespace App\Http\Requests\Company;

use App\Support\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from'        => ['nullable', 'date'],
            'date_to'          => ['nullable', 'date'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
            'status'           => ['nullable', Rule::in(AppointmentStatus::all())],
            'customer_phone'   => ['nullable', 'string', 'max:40'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

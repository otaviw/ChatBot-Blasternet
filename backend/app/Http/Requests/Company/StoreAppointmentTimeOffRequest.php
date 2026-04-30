<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use App\Support\AppointmentSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
            'starts_at'        => ['required', 'date'],
            'ends_at'          => ['required', 'date', 'after:starts_at'],
            'is_all_day'       => ['sometimes', 'boolean'],
            'reason'           => ['nullable', 'string', 'max:191'],
            'source'           => ['sometimes', 'string', Rule::in(['manual', 'system'])],
        ];
    }
}

<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use App\Support\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'          => ['required', Rule::in([
                AppointmentStatus::CONFIRMED,
                AppointmentStatus::CANCELLED,
                AppointmentStatus::COMPLETED,
                AppointmentStatus::NO_SHOW,
            ])],
            'reason'          => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['sometimes', 'boolean'],
        ];
    }
}

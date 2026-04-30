<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([SupportTicket::STATUS_OPEN, SupportTicket::STATUS_CLOSED])],
        ];
    }
}

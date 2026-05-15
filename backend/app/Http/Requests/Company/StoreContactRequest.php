<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) ($this->user()?->company_id ?? 0);

        return [
            'name'  => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
            'default_attendant_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->where('is_active', true);
                }),
            ],
            'skip_bot_to_default_attendant' => ['sometimes', 'boolean'],
            'meta_number_id' => [
                'sometimes',
                'nullable',
                'integer',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $skip = filter_var($this->input('skip_bot_to_default_attendant'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $attendantId = $this->input('default_attendant_user_id');

            if ($skip === true && empty($attendantId)) {
                $validator->errors()->add(
                    'default_attendant_user_id',
                    'Selecione um atendente padrão para habilitar o pular bot.'
                );
            }
        });
    }
}

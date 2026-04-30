<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use App\Models\Company;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                              => ['required', 'string', 'max:120', 'unique:companies,name'],
            'reseller_id'                       => ['nullable', 'integer', 'exists:resellers,id'],
            'meta_phone_number_id'              => ['nullable', 'string', 'max:255', $this->uniquePhoneNumberIdRule()],
            'meta_waba_id'                      => ['nullable', 'string', 'max:255'],
            'ai_enabled'                        => ['sometimes', 'boolean'],
            'ai_internal_chat_enabled'          => ['sometimes', 'boolean'],
            'ai_chatbot_enabled'                => ['sometimes', 'boolean'],
            'ai_chatbot_auto_reply_enabled'     => ['sometimes', 'boolean'],
            'ai_chatbot_rules'                  => ['nullable', 'array'],
            'ai_usage_enabled'                  => ['sometimes', 'boolean'],
            'ai_usage_limit_monthly'            => ['nullable', 'integer', 'min:1'],
            'max_users'                         => ['nullable', 'integer', 'min:1'],
            'max_conversation_messages_monthly' => ['nullable', 'integer', 'min:1'],
            'max_template_messages_monthly'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function uniquePhoneNumberIdRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $hash = Company::phoneNumberIdHash((string) $value);
            if ($hash === null) {
                return;
            }

            if (Company::query()->where('meta_phone_number_id_hash', $hash)->exists()) {
                $fail('O phone number id informado já está em uso.');
            }
        };
    }
}

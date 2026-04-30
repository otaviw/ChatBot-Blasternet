<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use App\Models\Reseller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateMyResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isResellerAdmin() === true;
    }

    public function rules(): array
    {
        $resellerId = (int) ($this->user()?->reseller_id ?? 0);

        return [
            'name'          => ['required', 'string', 'max:120'],
            'slug'          => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('resellers', 'slug')->ignore($resellerId),
                'not_in:' . implode(',', Reseller::RESERVED_SLUGS),
            ],
            'logo'          => ['nullable', 'file', 'image', 'max:2048'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $slug = Str::of((string) $this->input('slug', ''))
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->value();

        $primaryColor = Str::of((string) $this->input('primary_color', ''))
            ->trim()
            ->lower()
            ->value();

        $this->merge([
            'slug'          => $slug,
            'primary_color' => $primaryColor !== '' ? $primaryColor : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'slug.unique'       => 'Este slug ja esta em uso.',
            'slug.regex'        => 'O slug deve conter apenas letras minusculas, numeros e hifens.',
            'slug.not_in'       => 'Este slug e reservado e nao pode ser utilizado.',
            'logo.image'        => 'O logo deve ser uma imagem valida.',
            'logo.max'          => 'O logo deve ter no maximo 2MB.',
            'primary_color.regex' => 'A cor primaria deve estar no formato #RRGGBB.',
        ];
    }
}

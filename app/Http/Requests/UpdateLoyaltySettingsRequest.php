<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('sanctum')?->company_id;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'rule_type' => ['required', 'string', Rule::in(['spend', 'visits'])],
            'spend_amount_cents_per_point' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(fn () => $this->input('rule_type') === 'spend'),
            ],
            'points_per_visit' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(fn () => $this->input('rule_type') === 'visits'),
            ],
            'expiration_enabled' => ['required', 'boolean'],
            'expiration_days' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(fn () => $this->boolean('expiration_enabled')),
            ],
        ];
    }
}

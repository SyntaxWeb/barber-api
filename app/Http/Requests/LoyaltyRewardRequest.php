<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoyaltyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('sanctum')?->company_id;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $required = $isCreate ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => [$isCreate ? 'nullable' : 'sometimes', 'string', 'max:1000'],
            'points_cost' => [$required, 'integer', 'min:1'],
            'active' => [$isCreate ? 'nullable' : 'sometimes', 'boolean'],
        ];
    }
}

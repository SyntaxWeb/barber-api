<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $plans = array_keys(config('subscriptions.plans', []));
        $statuses = config('subscriptions.statuses', []);

        return [
            'plan' => ['required', Rule::in($plans)],
            'status' => ['required', Rule::in($statuses)],
            'price' => ['required', 'numeric', 'min:0'],
            'renews_at' => ['nullable', 'date'],
        ];
    }
}

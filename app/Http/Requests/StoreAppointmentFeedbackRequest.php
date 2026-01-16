<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_rating' => 'required|integer|min:1|max:5',
            'professional_rating' => 'required|integer|min:1|max:5',
            'scheduling_rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'allow_public_testimonial' => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('allow_public_testimonial')) {
            $this->merge([
                'allow_public_testimonial' => filter_var(
                    $this->allow_public_testimonial,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }
}

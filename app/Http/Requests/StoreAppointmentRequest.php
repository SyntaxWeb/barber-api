<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $canUsePastDates = in_array($this->user("sanctum")?->role, ["provider", "admin"], true);

        return [
            'cliente' => 'sometimes|required|string|max:255',
            'telefone' => 'sometimes|required|string|max:30',
            "data" => $canUsePastDates ? "required|date" : "required|date|after_or_equal:today",
            'horario' => 'required|date_format:H:i',
            'service_id' => 'nullable|exists:services,id',
            'service_ids' => 'nullable|array|min:1',
            'service_ids.*' => 'integer|exists:services,id',
            'preco' => 'nullable|numeric|min:0',
            'observacoes' => 'nullable|string',
            'company_slug' => 'nullable|string|exists:companies,slug',
        ];
    }
}

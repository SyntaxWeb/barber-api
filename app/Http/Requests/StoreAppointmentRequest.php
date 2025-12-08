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
        return [
            'cliente' => 'required|string|max:255',
            'telefone' => 'required|string|max:30',
            'data' => 'required|date|after_or_equal:today',
            'horario' => 'required|string',
            'service_id' => 'required|exists:services,id',
            'preco' => 'required|numeric|min:0',
            'observacoes' => 'nullable|string',
        ];
    }
}

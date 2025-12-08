<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'horario_inicio' => 'required|string',
            'horario_fim' => 'required|string',
            'intervalo_minutos' => 'required|integer|in:15,30,45,60',
            'dias_bloqueados' => 'nullable|array',
            'dias_bloqueados.*' => 'date',
        ];
    }
}

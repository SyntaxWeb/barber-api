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
            'horario_inicio' => ['required', 'date_format:H:i'],
            'horario_fim' => ['required', 'date_format:H:i'],
            'intervalo_minutos' => 'required|integer|in:15,30,45,60',
            'dias_bloqueados' => 'nullable|array',
            'dias_bloqueados.*' => 'date',
            'weekly_schedule' => 'nullable|array',
            'weekly_schedule.*.enabled' => 'required|boolean',
            'weekly_schedule.*.start' => 'nullable|date_format:H:i',
            'weekly_schedule.*.end' => 'nullable|date_format:H:i',
            'weekly_schedule.*.lunch_enabled' => 'nullable|boolean',
            'weekly_schedule.*.lunch_start' => 'nullable|date_format:H:i',
            'weekly_schedule.*.lunch_end' => 'nullable|date_format:H:i',
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\BlockedDay;
use App\Models\Setting;

class SettingsController extends Controller
{
    public function show()
    {
        $settings = Setting::firstOrFail();
        $dias = BlockedDay::all()->pluck('data')->map->toDateString();

        return response()->json([
            'horarioInicio' => $settings->horario_inicio,
            'horarioFim' => $settings->horario_fim,
            'intervaloMinutos' => $settings->intervalo_minutos,
            'diasBloqueados' => $dias,
        ]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        $data = $request->validated();
        $settings = Setting::firstOrFail();

        $settings->update([
            'horario_inicio' => $data['horario_inicio'],
            'horario_fim' => $data['horario_fim'],
            'intervalo_minutos' => $data['intervalo_minutos'],
        ]);

        if (array_key_exists('dias_bloqueados', $data)) {
            BlockedDay::query()->delete();
            foreach ($data['dias_bloqueados'] ?? [] as $dia) {
                BlockedDay::create(['data' => $dia]);
            }
        }

        return $this->show();
    }
}

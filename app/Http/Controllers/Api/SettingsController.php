<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\BlockedDay;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request)
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }
        $settings = Setting::firstOrCreate(
            ['company_id' => $companyId],
            [
                'horario_inicio' => '09:00',
                'horario_fim' => '19:00',
                'intervalo_minutos' => 30,
            ]
        );
        $dias = BlockedDay::where('company_id', $companyId)->pluck('data')->map->toDateString();

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
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $settings = Setting::updateOrCreate(
            ['company_id' => $companyId],
            [
                'horario_inicio' => $data['horario_inicio'],
                'horario_fim' => $data['horario_fim'],
                'intervalo_minutos' => $data['intervalo_minutos'],
            ]
        );

        if (array_key_exists('dias_bloqueados', $data)) {
            BlockedDay::where('company_id', $companyId)->delete();

            foreach ($data['dias_bloqueados'] ?? [] as $dia) {
                BlockedDay::create([
                    'data' => $dia,
                    'company_id' => $companyId,
                ]);
            }
        }

        return $this->show($request);
    }
}

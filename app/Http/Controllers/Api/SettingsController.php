<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\BlockedDay;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'weeklySchedule' => $this->buildWeeklyScheduleResponse($settings),
        ]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        $data = $request->validated();
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $weeklySchedule = null;
        if (array_key_exists('weekly_schedule', $data) && is_array($data['weekly_schedule'])) {
            $weeklySchedule = $this->sanitizeWeeklySchedule(
                $data['weekly_schedule'],
                $data['horario_inicio'],
                $data['horario_fim']
            );
        }

        $attributes = [
            'horario_inicio' => $data['horario_inicio'],
            'horario_fim' => $data['horario_fim'],
            'intervalo_minutos' => $data['intervalo_minutos'],
        ];

        if ($weeklySchedule !== null) {
            $attributes['weekly_schedule'] = $weeklySchedule;
        }

        $settings = Setting::updateOrCreate(
            ['company_id' => $companyId],
            $attributes
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

    private function weekDays(): array
    {
        return [
            'monday' => 'Segunda-feira',
            'tuesday' => 'Terça-feira',
            'wednesday' => 'Quarta-feira',
            'thursday' => 'Quinta-feira',
            'friday' => 'Sexta-feira',
            'saturday' => 'Sábado',
            'sunday' => 'Domingo',
        ];
    }

    private function sanitizeWeeklySchedule(array $input, string $defaultStart, string $defaultEnd): array
    {
        $result = [];
        foreach ($this->weekDays() as $dayKey => $label) {
            $entry = $input[$dayKey] ?? null;
            $enabled = (bool) ($entry['enabled'] ?? true);
            $start = $entry['start'] ?? $defaultStart;
            $end = $entry['end'] ?? $defaultEnd;

            if ($enabled && $this->timeToMinutes($start) >= $this->timeToMinutes($end)) {
                throw ValidationException::withMessages([
                    "weekly_schedule.{$dayKey}" => "Horário inválido para {$label}.",
                ]);
            }

            $lunchEnabled = (bool) ($entry['lunch_enabled'] ?? false);
            $lunchStart = $entry['lunch_start'] ?? null;
            $lunchEnd = $entry['lunch_end'] ?? null;

            if ($lunchEnabled) {
                if (!$lunchStart || !$lunchEnd) {
                    throw ValidationException::withMessages([
                        "weekly_schedule.{$dayKey}" => "Informe início e fim do almoço para {$label}.",
                    ]);
                }
                if (
                    $this->timeToMinutes($lunchStart) >= $this->timeToMinutes($lunchEnd) ||
                    $this->timeToMinutes($lunchStart) < $this->timeToMinutes($start) ||
                    $this->timeToMinutes($lunchEnd) > $this->timeToMinutes($end)
                ) {
                    throw ValidationException::withMessages([
                        "weekly_schedule.{$dayKey}" => "Intervalo de almoço inválido para {$label}.",
                    ]);
                }
            }

            $result[$dayKey] = [
                'enabled' => $enabled,
                'start' => $start,
                'end' => $end,
                'lunch_enabled' => $lunchEnabled,
                'lunch_start' => $lunchStart,
                'lunch_end' => $lunchEnd,
            ];
        }

        return $result;
    }

    private function buildWeeklyScheduleResponse(Setting $settings): array
    {
        $stored = $settings->weekly_schedule ?? [];
        $response = [];
        foreach ($this->weekDays() as $dayKey => $label) {
            $config = $stored[$dayKey] ?? [];
            $response[$dayKey] = [
                'enabled' => $config['enabled'] ?? true,
                'start' => $config['start'] ?? $settings->horario_inicio,
                'end' => $config['end'] ?? $settings->horario_fim,
                'lunch_enabled' => $config['lunch_enabled'] ?? false,
                'lunch_start' => $config['lunch_start'] ?? null,
                'lunch_end' => $config['lunch_end'] ?? null,
            ];
        }

        return $response;
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = explode(':', $time);
        return ((int) $hour * 60) + (int) $minute;
    }
}

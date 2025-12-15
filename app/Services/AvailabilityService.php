<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BlockedDay;
use App\Models\Setting;
use Carbon\Carbon;

class AvailabilityService
{
    /**
     * Retorna os horários disponíveis para uma data, considerando bloqueios e agendamentos ativos.
     */
    public function horariosDisponiveis(string $data, int $companyId): array
    {
        $settings = Setting::where('company_id', $companyId)->firstOrFail();

        if (BlockedDay::where('company_id', $companyId)->whereDate('data', $data)->exists()) {
            return [];
        }

        $daySchedule = $this->resolveDaySchedule($settings, $data);
        if (!$daySchedule['enabled']) {
            return [];
        }

        [$horaInicio, $minInicio] = explode(':', $daySchedule['start']);
        [$horaFim, $minFim] = explode(':', $daySchedule['end']);
        $intervalo = $settings->intervalo_minutos;

        $ocupados = Appointment::where('company_id', $companyId)
            ->whereDate('data', $data)
            ->where('status', '!=', 'cancelado')
            ->pluck('horario')
            ->toArray();

        $slots = [];
        for ($h = (int) $horaInicio, $m = (int) $minInicio; $h < (int) $horaFim || ($h === (int) $horaFim && $m <= (int) $minFim); ) {
            $horarioStr = sprintf('%02d:%02d', $h, $m);
            if ($daySchedule['lunch_enabled'] && $this->timeToMinutes($horarioStr) >= $this->timeToMinutes($daySchedule['lunch_start']) && $this->timeToMinutes($horarioStr) < $this->timeToMinutes($daySchedule['lunch_end'])) {
                $m += $intervalo;
                if ($m >= 60) {
                    $h += intdiv($m, 60);
                    $m %= 60;
                }
                continue;
            }
            if (!in_array($horarioStr, $ocupados, true)) {
                $slots[] = $horarioStr;
            }
            $m += $intervalo;
            if ($m >= 60) {
                $h += intdiv($m, 60);
                $m %= 60;
            }
        }

        return $slots;
    }

    private function resolveDaySchedule(Setting $settings, string $date): array
    {
        $weeklySchedule = $settings->weekly_schedule ?? [];
        $dayKey = strtolower(Carbon::parse($date)->format('l'));
        $config = $weeklySchedule[$dayKey] ?? null;

        return [
            'enabled' => $config['enabled'] ?? true,
            'start' => $config['start'] ?? $settings->horario_inicio,
            'end' => $config['end'] ?? $settings->horario_fim,
            'lunch_enabled' => $config['lunch_enabled'] ?? false,
            'lunch_start' => $config['lunch_start'] ?? null,
            'lunch_end' => $config['lunch_end'] ?? null,
        ];
    }

    private function timeToMinutes(?string $time): int
    {
        if (!$time) {
            return 0;
        }
        [$hour, $minute] = explode(':', $time);
        return ((int) $hour * 60) + (int) $minute;
    }
}

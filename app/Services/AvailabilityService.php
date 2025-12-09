<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BlockedDay;
use App\Models\Setting;

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

        [$horaInicio, $minInicio] = explode(':', $settings->horario_inicio);
        [$horaFim, $minFim] = explode(':', $settings->horario_fim);
        $intervalo = $settings->intervalo_minutos;

        $ocupados = Appointment::where('company_id', $companyId)
            ->whereDate('data', $data)
            ->where('status', '!=', 'cancelado')
            ->pluck('horario')
            ->toArray();

        $slots = [];
        for ($h = (int) $horaInicio, $m = (int) $minInicio; $h < (int) $horaFim || ($h === (int) $horaFim && $m <= (int) $minFim); ) {
            $horarioStr = sprintf('%02d:%02d', $h, $m);
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
}

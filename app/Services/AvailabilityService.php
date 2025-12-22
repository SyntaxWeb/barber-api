<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BlockedDay;
use App\Models\Setting;
use App\Models\Service;
use Carbon\Carbon;

class AvailabilityService
{
    /**
     * Retorna os horários disponíveis para uma data, considerando bloqueios e agendamentos ativos.
     */
    public function horariosDisponiveis(
        string $data,
        int $companyId,
        ?int $serviceId = null,
        ?int $ignoreAppointmentId = null
    ): array
    {
        $settings = Setting::where('company_id', $companyId)->firstOrFail();

        if (BlockedDay::where('company_id', $companyId)->whereDate('data', $data)->exists()) {
            return [];
        }

        $daySchedule = $this->resolveDaySchedule($settings, $data);
        if (!$daySchedule['enabled']) {
            return [];
        }

        $intervalo = $settings->intervalo_minutos;

        $serviceDuration = $intervalo;
        if ($serviceId) {
            $service = Service::where('company_id', $companyId)->find($serviceId);
            if (!$service) {
                return [];
            }
            $serviceDuration = max(1, (int) $service->duracao_minutos);
        }

        $appointmentsQuery = Appointment::where('appointments.company_id', $companyId)
            ->whereDate('data', $data)
            ->where('status', '!=', 'cancelado');

        if ($ignoreAppointmentId) {
            $appointmentsQuery->where('id', '!=', $ignoreAppointmentId);
        }

        $appointments = $appointmentsQuery
            ->leftJoin('services', 'appointments.service_id', '=', 'services.id')
            ->get(['appointments.horario', 'services.duracao_minutos']);

        $ocupados = [];
        foreach ($appointments as $appointment) {
            $start = $this->timeToMinutes($appointment->horario);
            $duration = (int) ($appointment->duracao_minutos ?: $intervalo);
            if ($duration <= 0) {
                $duration = $intervalo;
            }
            $ocupados[] = [$start, $start + $duration];
        }

        $inicioDia = $this->timeToMinutes($daySchedule['start']);
        $fimDia = $this->timeToMinutes($daySchedule['end']);
        $almocoInicio = $daySchedule['lunch_enabled'] ? $this->timeToMinutes($daySchedule['lunch_start']) : null;
        $almocoFim = $daySchedule['lunch_enabled'] ? $this->timeToMinutes($daySchedule['lunch_end']) : null;

        $slots = [];
        for ($slot = $inicioDia; $slot <= $fimDia; $slot += $intervalo) {
            $slotFim = $slot + $serviceDuration;
            if ($slotFim > $fimDia) {
                continue;
            }
            if (
                $daySchedule['lunch_enabled'] &&
                $almocoInicio !== null &&
                $almocoFim !== null &&
                $slotFim > $almocoInicio &&
                $slot < $almocoFim
            ) {
                continue;
            }
            $conflito = false;
            foreach ($ocupados as [$ocupadoInicio, $ocupadoFim]) {
                if ($slotFim > $ocupadoInicio && $slot < $ocupadoFim) {
                    $conflito = true;
                    break;
                }
            }
            if ($conflito) {
                continue;
            }
            $slots[] = sprintf('%02d:%02d', intdiv($slot, 60), $slot % 60);
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

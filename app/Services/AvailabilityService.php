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
        $availability = $this->buildAvailability($data, $companyId, $serviceId, $ignoreAppointmentId);
        return $availability['horarios'];
    }

    /**
     * Retorna horários disponíveis agrupados por hora.
     */
    public function horariosDisponiveisPorHora(
        string $data,
        int $companyId,
        ?int $serviceId = null,
        ?int $ignoreAppointmentId = null
    ): array
    {
        return $this->buildAvailability($data, $companyId, $serviceId, $ignoreAppointmentId);
    }

    private function buildAvailability(
        string $data,
        int $companyId,
        ?int $serviceId,
        ?int $ignoreAppointmentId
    ): array
    {
        $settings = Setting::where('company_id', $companyId)->firstOrFail();

        if (BlockedDay::where('company_id', $companyId)->whereDate('data', $data)->exists()) {
            return $this->emptyAvailability();
        }

        $daySchedule = $this->resolveDaySchedule($settings, $data);
        if (!$daySchedule['enabled']) {
            return $this->emptyAvailability();
        }

        if (!$serviceId) {
            return $this->emptyAvailability();
        }

        $service = Service::where('company_id', $companyId)
            ->where('ativo', true)
            ->find($serviceId);
        if (!$service) {
            return $this->emptyAvailability();
        }
        $serviceDuration = max(1, (int) $service->duracao_minutos);

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
            $duration = max(1, (int) $appointment->duracao_minutos);
            $ocupados[] = [$start, $start + $duration];
        }

        $inicioDia = $this->timeToMinutes($daySchedule['start']);
        $fimDia = $this->timeToMinutes($daySchedule['end']);
        if ($fimDia <= $inicioDia) {
            return $this->emptyAvailability();
        }

        $almocoInicio = $daySchedule['lunch_enabled'] ? $this->timeToMinutes($daySchedule['lunch_start']) : null;
        $almocoFim = $daySchedule['lunch_enabled'] ? $this->timeToMinutes($daySchedule['lunch_end']) : null;

        $ultimoInicio = $fimDia - $serviceDuration;
        if ($ultimoInicio < $inicioDia) {
            return $this->emptyAvailability();
        }

        $minutoAtual = null;
        try {
            $agora = Carbon::now();
            if ($agora->toDateString() === Carbon::parse($data)->toDateString()) {
                $minutoAtual = ($agora->hour * 60) + $agora->minute;
            }
        } catch (\Throwable $e) {
            $minutoAtual = null;
        }

        $slots = [];
        $minutosPorHora = [];
        for ($slot = $inicioDia; $slot <= $ultimoInicio; $slot++) {
            if ($minutoAtual !== null && $slot < $minutoAtual) {
                continue;
            }
            $slotFim = $slot + $serviceDuration;
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

            $hora = sprintf('%02d', intdiv($slot, 60));
            $minuto = sprintf('%02d', $slot % 60);
            $slots[] = sprintf('%s:%s', $hora, $minuto);
            if (!array_key_exists($hora, $minutosPorHora)) {
                $minutosPorHora[$hora] = [];
            }
            $minutosPorHora[$hora][$minuto] = true;
        }

        $horas = array_keys($minutosPorHora);
        sort($horas);
        $minutosPorHoraOrdenado = [];
        foreach ($horas as $hora) {
            $minutos = array_keys($minutosPorHora[$hora]);
            sort($minutos);
            $minutosPorHoraOrdenado[$hora] = $minutos;
        }

        return [
            'horarios' => $slots,
            'horas' => $horas,
            'minutos_por_hora' => $minutosPorHoraOrdenado,
        ];
    }

    private function emptyAvailability(): array
    {
        return [
            'horarios' => [],
            'horas' => [],
            'minutos_por_hora' => [],
        ];
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

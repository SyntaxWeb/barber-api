<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentReminderNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Dispara lembretes para clientes com agendamentos proximos';

    private const REMINDER_OFFSET_MINUTES = 60;
    private const WINDOW_MINUTES = 5;

    public function handle(): int
    {
        $this->info('Iniciando verificacao de lembretes de agendamentos...');
        Log::warning('Iniciando verificacao de lembretes de agendamentos...');

        $windowStart = Carbon::now()->setSecond(0);
        $windowEnd = $windowStart->copy()->addMinutes(self::WINDOW_MINUTES);
        $appointmentDate = $windowStart->copy()->addMinutes(self::REMINDER_OFFSET_MINUTES)->toDateString();

        $this->info(sprintf(
            'Buscando agendamentos com lembretes entre %s e %s',
            $windowStart->format('d/m/Y H:i'),
            $windowEnd->format('d/m/Y H:i'),
        ));

        $appointments = Appointment::with(['user', 'service'])
            ->where('status', '!=', 'cancelado')
            ->whereNotNull('user_id')
            ->whereNull('reminded_at')
            ->whereDate('data', $appointmentDate)
            ->get()
            ->filter(fn (Appointment $appointment) => $this->reminderIsDue($appointment, $windowStart, $windowEnd));
        $count = 0;

        foreach ($appointments as $appointment) {
            if (!$appointment->user || !$appointment->user->email) {
                continue;
            }

            // Se já foi lembrado anteriormente, não reenviar — enviar apenas uma vez.
            if ($appointment->reminded_at) {
                continue;
            }
            $appointment->user->notify(new AppointmentReminderNotification($appointment));
            $appointment->forceFill(['reminded_at' => now()])->save();
            $count++;
        }

        $this->info("Lembretes enviados: {$count}");

        return self::SUCCESS;
    }

    private function reminderIsDue(Appointment $appointment, CarbonInterface $start, CarbonInterface $end): bool
    {
        $date = $appointment->data?->copy()->setTimeFromTimeString($appointment->horario);
        if (!$date) {
            return false;
        }

        $reminderTime = $date->copy()->subMinutes(self::REMINDER_OFFSET_MINUTES);

        return $reminderTime->betweenIncluded($start, $end);
    }
}

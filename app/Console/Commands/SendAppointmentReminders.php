<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentReminderNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Dispara lembretes para clientes com agendamentos próximos';

    public function handle(): int
    {
        $windowStart = Carbon::now()->setSecond(0);
        $windowEnd = $windowStart->copy()->addHour();

        $this->info(sprintf(
            'Buscando agendamentos entre %s e %s',
            $windowStart->format('d/m/Y H:i'),
            $windowEnd->format('d/m/Y H:i'),
        ));

        $appointments = Appointment::with(['user', 'service'])
            ->where('status', '!=', 'cancelado')
            ->whereNotNull('user_id')
            ->whereDate('data', $windowStart->toDateString())
            ->get()
            ->filter(fn(Appointment $appointment) => $this->isWithinWindow($appointment, $windowStart, $windowEnd));
        $count = 0;

        foreach ($appointments as $appointment) {
            if (!$appointment->user || !$appointment->user->email) {
                continue;
            }

            if ($appointment->reminded_at && Carbon::parse($appointment->reminded_at)->greaterThan($windowStart)) {
                continue;
            }
            $appointment->user->notify(new AppointmentReminderNotification($appointment));
            // $appointment->forceFill(['reminded_at' => now()])->save();
            $count++;
        }
        dd($count);

        $this->info("Lembretes enviados: {$count}");

        return self::SUCCESS;
    }

    private function isWithinWindow(Appointment $appointment, CarbonInterface $start, CarbonInterface $end): bool
    {
        $date = $appointment->data?->copy()->setTimeFromTimeString($appointment->horario);
        if (!$date) {
            return false;
        }

        return $date->betweenIncluded($start, $end);
    }
}

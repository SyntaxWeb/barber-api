<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewAppointmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Appointment $appointment)
    {
        $this->appointment->loadMissing('service');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'cliente' => $this->appointment->cliente,
            'telefone' => $this->appointment->telefone,
            'data' => $this->appointment->data?->toDateString(),
            'horario' => $this->appointment->horario,
            'service' => $this->appointment->service?->nome,
            'preco' => $this->appointment->preco,
        ];
    }
}

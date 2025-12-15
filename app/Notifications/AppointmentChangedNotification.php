<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AppointmentChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Appointment $appointment, protected string $action)
    {
        $this->appointment->loadMissing('service', 'company');
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
            'action' => $this->action,
            'company' => [
                'id' => $this->appointment->company?->id,
                'nome' => $this->appointment->company?->nome,
                'slug' => $this->appointment->company?->slug,
            ],
        ];
    }
}

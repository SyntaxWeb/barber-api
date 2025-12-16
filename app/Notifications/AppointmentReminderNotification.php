<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Appointment $appointment)
    {
        $this->appointment->loadMissing('service', 'company');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = $this->appointment->service?->nome ?? 'Servico';
        $company = $this->appointment->company?->nome ?? 'SyntaxAtendimento';
        $date = $this->appointment->data?->format('d/m/Y') ?? '';
        $time = $this->appointment->horario;
        $logoUrl = $this->appointment->company?->icon_url
            ?? rtrim(config('app.url'), '/') . '/syntax-logo.svg';

        $mail = (new MailMessage())
            ->subject("Lembrete: {$service} as {$time}")
            ->greeting("Ola, {$notifiable->name}!");

        if ($logoUrl) {
            $mail->line("![Logo da empresa]({$logoUrl})");
        }

        return $mail
            ->line("Seu atendimento **{$service}** com **{$company}** acontece logo mais.")
            ->line("**Data:** {$date}")
            ->line("**Horario:** {$time}")
            ->line("Chegue alguns minutos antes para aproveitar tudo com calma.")
            ->line("Se precisar reagendar, responda este email ou acesse o portal do cliente.")
            ->salutation('Equipe SyntaxAtendimento');
    }
}

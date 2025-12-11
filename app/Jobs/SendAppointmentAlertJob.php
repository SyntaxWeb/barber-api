<?php

namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $appointmentId) {}

    public function handle(): void
    {
        $appointment = Appointment::with(['company', 'service'])->find($this->appointmentId);
        if (!$appointment || !$appointment->company) {
            return;
        }

        $company = $appointment->company;
        $priceText = $appointment->preco !== null
            ? 'Valor previsto: R$ ' . number_format((float) $appointment->preco, 2, ',', '.')
            : null;


        $priceText = $appointment->preco !== null
            ? 'R$ ' . number_format($appointment->preco, 2, ',', '.')
            : '—';

        $companyText = $company?->nome ?? '—';

        $message = sprintf(
            "💈 *Novo Agendamento Confirmado!* 💈\n\n" .
                "👤 *Cliente:* %s\n" .
                "📅 *Data:* %s\n" .
                "⏰ *Horário:* %s\n" .
                "💈 *Serviço:* %s\n" .
                "💲 *Valor:* %s\n" .
                "🏪 *Empresa:* %s\n\n" .
                "✨ Prepare-se! Um novo cliente garantiu um horário com você!",
            $appointment->cliente,
            $appointment->data?->format('d/m/Y'),
            $appointment->horario,
            $appointment->service->nome ?? 'Serviço',
            $priceText,
            $companyText
        );

        if ($company->notify_via_email && $company->notify_email) {
            Mail::raw($message, function ($mail) use ($company) {
                $mail->to($company->notify_email)
                    ->subject('Novo agendamento recebido');
            });
        }

        $this->notifyTelegram($company->notify_telegram, $message, (bool) $company->notify_via_telegram);
    }

    protected function notifyTelegram(?string $companyChatId, string $message, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $token = config('services.telegram.bot_token');
        $chatId = $companyChatId;

        if (!$token || !$chatId) {
            Log::warning('Telegram notification skipped: missing bot token or chat id.');
            return;
        }

        try {
            Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Falha ao enviar notificação para o Telegram', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

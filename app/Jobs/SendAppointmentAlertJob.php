<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAppointmentAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $appointmentId, protected string $action = 'created') {}

    public function handle(): void
    {
        $appointment = Appointment::with(['company', 'service', 'user'])->find($this->appointmentId);
        if (!$appointment || !$appointment->company) {
            return;
        }

        $company = $appointment->company;
        $serviceName = optional($appointment->service)->nome ?? 'Serviço';
        $dateText = optional($appointment->data)->format('d/m/Y') ?: (string) $appointment->data;
        $priceText = $appointment->preco !== null
            ? 'R$ ' . number_format((float) $appointment->preco, 2, ',', '.')
            : 'A combinar';
        $companyText = $company?->nome ?? 'Sua barbearia';

        $titles = $this->resolveTitles();

        $message = sprintf(
            "💈 *%s* 💈\n\n" .
                "👤 *Cliente:* %s\n" .
                "📅 *Data:* %s\n" .
                "⏰ *Horário:* %s\n" .
                "💈 *Serviço:* %s\n" .
                "💲 *Valor:* %s\n" .
                "🏪 *Empresa:* %s\n\n" .
                "%s",
            $titles['provider_title'],
            $appointment->cliente,
            $dateText,
            $appointment->horario,
            $serviceName,
            $priceText,
            $companyText,
            $titles['provider_footer']
        );

        if ($company->notify_via_email && $company->notify_email) {
            try {
                Mail::raw($message, function ($mail) use ($company, $titles) {
                    $mail->to($company->notify_email)
                        ->subject($titles['provider_subject']);
                });
                $this->logNotification($company, 'email', $company->notify_email, $message);
            } catch (Throwable $exception) {
                Log::error('Falha ao enviar notificação por e-mail.', [
                    'company_id' => $company->id,
                    'error' => $exception->getMessage(),
                ]);

                $this->logNotification($company, 'email', $company->notify_email, $message, 'failed', [], $exception->getMessage());
            }
        }

        if ($appointment->user && $appointment->user->email) {
            $clientMessage = sprintf(
                "💈 *%s* 💈\n\n" .
                    "👤 *Cliente:* %s\n" .
                    "💈 *Serviço:* %s\n" .
                    "📅 *Data:* %s\n" .
                    "⏰ *Horário:* %s\n" .
                    "💲 *Valor:* %s\n" .
                    "🏪 *Empresa:* %s\n\n" .
                    "%s",
                $titles['client_title'],
                $appointment->user->name ?? $appointment->cliente,
                $serviceName,
                $dateText,
                $appointment->horario,
                $priceText,
                $companyText,
                $titles['client_footer']
            );

            Mail::raw($clientMessage, function ($mail) use ($appointment, $titles) {
                $mail->to($appointment->user->email)
                    ->subject($titles['client_subject']);
            });
        }

        $this->notifyTelegram($company, $company->notify_telegram, $message, (bool) $company->notify_via_telegram);
        $this->notifyWhatsapp($company, $message);
    }

    protected function notifyTelegram(Company $company, ?string $companyChatId, string $message, bool $enabled): void
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
                'parse_mode' => 'Markdown',
            ]);
            $this->logNotification($company, 'telegram', $chatId, $message);
        } catch (Throwable $exception) {
            Log::error('Falha ao enviar notificação para o Telegram', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
            $this->logNotification($company, 'telegram', $chatId, $message, 'failed', [], $exception->getMessage());
        }
    }

    protected function notifyWhatsapp(Company $company, string $message): void
    {
        if (!$company->notify_via_whatsapp || !$company->notify_whatsapp) {
            return;
        }

        try {
            $this->whatsappService()->sendMessage($company, $company->notify_whatsapp, $message);
            $this->logNotification($company, 'whatsapp', $company->notify_whatsapp, $message);
        } catch (Throwable $exception) {
            Log::error('Falha ao enviar notificação para o WhatsApp', [
                'company_id' => $company->id,
                'error' => $exception->getMessage(),
            ]);

            $this->logNotification($company, 'whatsapp', $company->notify_whatsapp, $message, 'failed', [], $exception->getMessage());
        }
    }

    protected function whatsappService(): WhatsappService
    {
        return app(WhatsappService::class);
    }

    protected function logNotification(Company $company, string $channel, ?string $recipient, string $message, string $status = 'sent', array $meta = [], ?string $error = null): void
    {
        NotificationLog::create([
            'company_id' => $company->id,
            'channel' => $channel,
            'recipient' => $recipient,
            'message' => $message,
            'status' => $status,
            'meta' => array_merge([
                'appointment_id' => $this->appointmentId,
                'action' => $this->action,
            ], $meta),
            'error' => $error,
        ]);
    }

    protected function resolveTitles(): array
    {
        return match ($this->action) {
            'updated' => [
                'provider_title' => 'Agendamento atualizado',
                'provider_subject' => 'Agendamento atualizado por um cliente',
                'provider_footer' => '⚙️ Revise o novo horário e confirme os detalhes.',
                'client_title' => 'Atualização confirmada',
                'client_subject' => 'Seu agendamento foi atualizado',
                'client_footer' => '✅ Guardamos sua alteração. Nos vemos em breve!',
            ],
            'cancelled' => [
                'provider_title' => 'Agendamento cancelado',
                'provider_subject' => 'Um cliente cancelou o agendamento',
                'provider_footer' => '🚫 O horário foi liberado automaticamente.',
                'client_title' => 'Cancelamento confirmado',
                'client_subject' => 'Seu agendamento foi cancelado',
                'client_footer' => 'Se precisar reagendar, estamos por aqui.',
            ],
            default => [
                'provider_title' => 'Novo agendamento confirmado!',
                'provider_subject' => 'Novo agendamento recebido',
                'provider_footer' => '✨ Prepare-se! Um novo cliente garantiu um horário com você!',
                'client_title' => 'Seu agendamento foi recebido!',
                'client_subject' => 'Recebemos seu agendamento',
                'client_footer' => '✨ Qualquer mudança é só avisar por aqui.',
            ],
        };
    }
}

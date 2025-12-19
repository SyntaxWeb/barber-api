<?php

namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendFeedbackInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $appointmentId) {}

    public function handle(): void
    {
        $appointment = Appointment::with(['company', 'user', 'service'])->find($this->appointmentId);
        if (!$appointment || !$appointment->user || !$appointment->user->email) {
            return;
        }

        $token = $appointment->feedback_token;
        if (!$token || ($appointment->feedback_token_expires_at && $appointment->feedback_token_expires_at->isPast())) {
            $token = Str::random(64);
        }

        $appointment->forceFill([
            'feedback_token' => $token,
            'feedback_token_expires_at' => now()->addDays(30),
            'feedback_requested_at' => now(),
        ])->save();

        $companyName = $appointment->company?->nome ?? config('app.name');
        $clientName = $appointment->user->name ?? $appointment->cliente ?? 'cliente';
        $serviceName = $appointment->service?->nome ?? 'seu atendimento';

        $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $feedbackLink = $baseUrl . '/cliente/feedback?token=' . $token;

        Mail::send('emails.feedback-invitation', [
            'clientName'   => $clientName,
            'companyName'  => $companyName,
            'serviceName'  => $serviceName,
            'feedbackLink' => $feedbackLink,
        ], function ($mail) use ($appointment, $companyName) {
            $mail->to($appointment->user->email)
                ->subject("Como foi seu atendimento na {$companyName}?");
        });
    }
}

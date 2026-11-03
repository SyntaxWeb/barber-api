<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\Whatsapp\WhatsappDeliveryService;
use App\Services\WhatsappService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WhatsappSetupController extends Controller
{
    public function __construct(protected WhatsappService $whatsappService) {}

    public function status(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) return response()->json(['message' => 'Empresa não encontrada.'], 404);
        try {
            $sessionId = $this->whatsappService->ensureSessionId($company);
            $configured = $this->whatsappService->configured();
            $status = $configured ? $this->whatsappService->fetchStatus($company) : ['connected' => false, 'state' => 'unconfigured'];
            $pairing = $configured && empty($status['connected']) && $this->whatsappService->isPairing($company);
            $qr = null;
            if ($pairing) {
                $qr = ($this->whatsappService->providerName() === 'wppconnect' || $request->boolean('refresh_qr')) && ($status['state'] ?? null) !== 'not_created'
                    ? $this->whatsappService->fetchQrCode($company) : $this->whatsappService->cachedQrCode($company);
            }
            return response()->json(['session_id' => $sessionId, 'provider' => $this->whatsappService->providerName(), 'configured' => $configured, 'pairing' => $pairing, 'status' => $status, 'qr_code' => $qr]);
        } catch (Throwable $exception) { return $this->failure($exception, 'Não foi possível consultar o WhatsApp.'); }
    }

    public function start(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) return response()->json(['message' => 'Empresa não encontrada.'], 404);
        try {
            $status = $this->whatsappService->startSession($company);
            $qr = $status['qr_code'] ?? $this->whatsappService->cachedQrCode($company);
            unset($status['qr_code']);
            return response()->json(['session_id' => $this->whatsappService->ensureSessionId($company), 'provider' => $this->whatsappService->providerName(), 'configured' => true, 'pairing' => empty($status['connected']) && $this->whatsappService->isPairing($company), 'status' => $status, 'qr_code' => $qr]);
        } catch (Throwable $exception) { return $this->failure($exception, 'Não foi possível iniciar a conexão do WhatsApp.'); }
    }

    public function logout(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) return response()->json(['message' => 'Empresa não encontrada.'], 404);
        try { return response()->json(['status' => $this->whatsappService->logoutSession($company)]); }
        catch (Throwable $exception) { return $this->failure($exception, 'Não foi possível desconectar o WhatsApp.'); }
    }

    public function test(Request $request, WhatsappDeliveryService $delivery): JsonResponse
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) return response()->json(['message' => 'Empresa não encontrada.'], 404);
        if (!$company->notify_whatsapp) return response()->json(['message' => 'Salve o número de WhatsApp para alertas antes de enviar um teste.'], 422);
        try {
            $log = $delivery->send($company, $company->notify_whatsapp, "💈 SyntaxAtendimento\n\nOlá! O WhatsApp da {$company->nome} está pronto para enviar os avisos de novos agendamentos para este número.", ['action' => 'test', 'audience' => 'provider']);
            return response()->json(['message' => 'Teste enviado. Confira o recebimento no WhatsApp.', 'notification_id' => $log->id, 'status' => $log->status]);
        } catch (Throwable $exception) { return $this->failure($exception, 'Não foi possível enviar a mensagem de teste.'); }
    }

    public function notifications(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) return response()->json(['message' => 'Empresa não encontrada.'], 404);
        $logs = NotificationLog::where('company_id', $company->id)->where('channel', 'whatsapp')->latest('id')->limit(10)
            ->get(['id', 'recipient', 'status', 'error', 'meta', 'created_at'])->map(fn ($log) => [
                'id' => $log->id, 'recipient' => $log->recipient, 'status' => $log->status, 'error' => $log->error,
                'action' => $log->meta['action'] ?? 'created', 'appointment_id' => $log->meta['appointment_id'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);
        return response()->json(['data' => $logs]);
    }

    private function failure(Throwable $exception, string $fallback): JsonResponse
    {
        report($exception);
        $known = $this->whatsappService->providerName() === 'evolution'
            && ($exception instanceof \RuntimeException || $exception instanceof \InvalidArgumentException);
        return response()->json(['message' => $known ? $exception->getMessage() : $fallback], 422);
    }
}

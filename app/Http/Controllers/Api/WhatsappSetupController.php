<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        try {
            $sessionId = $this->whatsappService->ensureSessionId($company);
            $status = $this->whatsappService->fetchStatus($company);
            $qrCode = empty($status['connected']) ? $this->whatsappService->fetchQrCode($company) : null;

            return response()->json([
                'session_id' => $sessionId,
                'status' => $status,
                'qr_code' => $qrCode,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Não foi possível consultar o status do WhatsApp.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function start(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        try {
            $sessionId = $this->whatsappService->ensureSessionId($company);
            $status = $this->whatsappService->startSession($company);

            return response()->json([
                'session_id' => $sessionId,
                'status' => $status,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Não foi possível iniciar a sessão do WhatsApp.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $company = $request->user('sanctum')?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        try {
            $response = $this->whatsappService->logoutSession($company);

            return response()->json([
                'status' => $response,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Não foi possível desconectar a sessão do WhatsApp.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }
}

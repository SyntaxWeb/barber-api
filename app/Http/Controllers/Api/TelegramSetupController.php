<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramSetupController extends Controller
{
    public function createLink(Request $request)
    {
        $user = $request->user('sanctum');
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $botUsername = ltrim(config('services.telegram.bot_username', ''), '@');

        if (!$botUsername) {
            return response()->json(['message' => 'Configure o TELEGRAM_BOT_USERNAME no servidor.'], 422);
        }

        $token = Str::random(24);
        $company->forceFill([
            'telegram_link_token' => $token,
            'telegram_link_token_created_at' => now(),
        ])->save();

        $deepLink = "https://t.me/{$botUsername}?start={$token}";

        return response()->json([
            'link' => $deepLink,
            'token' => $token,
        ]);
    }

    public function verifyLink(Request $request)
    {
        $user = $request->user('sanctum');
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        if (!$company->telegram_link_token || !$company->telegram_link_token_created_at) {
            return response()->json(['message' => 'Gere um link antes de verificar.'], 422);
        }

        if ($company->telegram_link_token_created_at->lt(now()->subMinutes(30))) {
            return response()->json(['message' => 'O link expirou, gere um novo.'], 422);
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return response()->json(['message' => 'Configure o TELEGRAM_BOT_TOKEN no servidor.'], 422);
        }

        $response = Http::get("https://api.telegram.org/bot{$botToken}/getUpdates", [
            'allowed_updates' => json_encode(['message']),
        ]);

        if (!$response->ok()) {
            Log::error('Falha ao consultar updates do Telegram', ['body' => $response->body()]);
            return response()->json(['message' => 'Não foi possível consultar o Telegram.'], 502);
        }

        $updates = $response->json('result', []);
        $chatId = null;
        $lastUpdateId = null;

        foreach ($updates as $update) {
            $lastUpdateId = max($lastUpdateId ?? 0, $update['update_id'] ?? 0);
            $text = data_get($update, 'message.text');
            if (!$text) {
                continue;
            }
            if (str_contains($text, $company->telegram_link_token)) {
                $chatId = data_get($update, 'message.chat.id');
                $lastUpdateId = max($lastUpdateId ?? 0, $update['update_id'] ?? 0);
                break;
            }
        }

        if ($lastUpdateId !== null) {
            Http::get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                'offset' => $lastUpdateId + 1,
                'allowed_updates' => json_encode(['message']),
            ]);
        }

        if (!$chatId) {
            return response()->json(['message' => 'Não encontramos sua mensagem. Envie /start pelo bot e clique em verificar novamente.'], 404);
        }

        $company->forceFill([
            'notify_telegram' => (string) $chatId,
            'notify_via_telegram' => true,
            'telegram_link_token' => null,
            'telegram_link_token_created_at' => null,
        ])->save();

        return response()->json([
            'chat_id' => (string) $chatId,
        ]);
    }
}

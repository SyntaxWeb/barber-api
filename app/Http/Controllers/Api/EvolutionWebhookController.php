<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Whatsapp\EvolutionProvider;
use App\Services\Whatsapp\WhatsappDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, EvolutionProvider $evolution, WhatsappDeliveryService $delivery)
    {
        $instance = $request->input('instance');
        $token = $request->header('X-Syntax-Webhook-Token');
        if (!is_string($instance) || strlen($instance) > 255 || !is_string($token)
            || !config('services.evolution.webhook_secret') || !hash_equals($evolution->webhookToken($instance), $token)) {
            return response()->json(['message' => 'Webhook não autorizado.'], 401);
        }
        if (config('services.whatsapp.provider') !== 'evolution') return response()->json(['received' => true]);
        $company = Company::where('whatsapp_session_id', $instance)->first();
        if (!$company) return response()->json(['received' => true]);
        $event = strtolower(str_replace('_', '.', (string) $request->input('event')));
        $data = $request->input('data', []);
        if (!is_array($data)) return response()->json(['received' => true]);
        if ($event === 'connection.update') {
            // Discard delayed connection events to avoid restoring a stale connection state.
            $timestamp = strtotime((string) $request->input('date_time', '')) ?: time();
            Cache::lock('evolution:connection:'.$company->id, 10)->block(2, function () use ($company, $data, $timestamp) {
                $key = 'evolution:connection-time:'.$company->id;
                if ($timestamp < (int) Cache::get($key, 0)) return;
                Cache::put($key, $timestamp, now()->addDay());
                $connected = ($data['state'] ?? null) === 'open';
                $company->forceFill([
                    'whatsapp_status' => $connected ? 'connected' : (($data['state'] ?? null) === 'connecting' ? 'connecting' : 'disconnected'),
                    'whatsapp_connected_at' => $connected ? ($company->whatsapp_connected_at ?? now()) : null,
                ])->save();
                if ($connected) Cache::forget('evolution:qr:'.$company->whatsapp_session_id);
            });
        } elseif ($event === 'qrcode.updated') {
            if ($company->whatsapp_status !== 'connecting') return response()->json(['received' => true]);
            $qr = $data['qrcode']['base64'] ?? $data['base64'] ?? null;
            if (is_string($qr) && strlen($qr) < 200000) $evolution->rememberQrCode($instance, $qr);
        } elseif ($event === 'messages.update') {
            $updates = array_is_list($data) ? $data : [$data];
            foreach (array_slice($updates, 0, 100) as $update) {
                if (!is_array($update)) continue;
                $id = $update['keyId'] ?? $update['key']['id'] ?? $update['messageId'] ?? null;
                $status = $update['status'] ?? $update['update']['status'] ?? null;
                if (is_string($id) && strlen($id) < 256 && (is_string($status) || is_int($status))) $delivery->updateStatus($company, $id, (string) $status);
            }
        }
        return response()->json(['received' => true]);
    }
}

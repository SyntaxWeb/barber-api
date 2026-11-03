<?php

namespace App\Services\Whatsapp;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EvolutionProvider
{
    public function ensureSessionId(Company $company): string
    {
        if (!$company->whatsapp_session_id) {
            $company->forceFill(['whatsapp_session_id' => 'syntax-company-'.$company->id])->save();
        }
        return $company->whatsapp_session_id;
    }

    public function configured(): bool
    {
        return filled(config('services.evolution.base_url')) && filled(config('services.evolution.api_key'))
            && filled(config('services.evolution.webhook_secret'));
    }

    public function webhookToken(string $instance): string
    {
        $secret = config('services.evolution.webhook_secret');
        if (!$secret) throw new RuntimeException('Configure o segredo dos webhooks da Evolution API no servidor.');
        return hash_hmac('sha256', $instance, $secret);
    }

    public function startSession(Company $company): array
    {
        $instance = $this->ensureSessionId($company);
        $status = $this->fetchStatus($company);
        $webhook = $this->webhookConfiguration($instance);
        if ($status['connected']) {
            // Repair callbacks after server URL or webhook secret changes, without unpairing.
            $this->syncWebhook($company);
            return $status;
        }
        $company->forceFill(['whatsapp_phone' => null])->save();
        if ($status['state'] === 'not_created') {
            $data = $this->request('POST', '/instance/create', [
                'instanceName' => $instance,
                'integration' => 'WHATSAPP-BAILEYS',
                'qrcode' => true,
                'webhook' => $webhook,
                'groupsIgnore' => true,
                'readMessages' => false,
                'readStatus' => false,
                'syncFullHistory' => false,
            ]);
            $this->rememberQrCode($instance, $data['qrcode']['base64'] ?? null);
        } else {
            Cache::forget($this->qrKey($instance));
            $this->syncWebhook($company);
        }
        $qr = $this->cachedQrCode($company) ?: $this->fetchQrCode($company);
        $company->forceFill(['whatsapp_status' => 'connecting'])->save();
        return ['connected' => false, 'state' => 'connecting', 'qr_code' => $qr];
    }

    public function syncWebhook(Company $company): void
    {
        $instance = $this->ensureSessionId($company);
        $this->request('POST', '/webhook/set/'.rawurlencode($instance), ['webhook' => $this->webhookConfiguration($instance)]);
    }

    private function webhookConfiguration(string $instance): array
    {
        return [
            'enabled' => true,
            'url' => rtrim(config('services.evolution.webhook_base_url') ?: config('app.url'), '/').'/api/webhooks/whatsapp/evolution',
            'headers' => ['X-Syntax-Webhook-Token' => $this->webhookToken($instance)],
            'byEvents' => false,
            'base64' => false,
            'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPDATE'],
        ];
    }

    public function fetchStatus(Company $company): array
    {
        $instance = $this->ensureSessionId($company);
        $data = $this->request('GET', '/instance/connectionState/'.rawurlencode($instance), [], true);
        $state = $data === null ? 'not_created' : ($data['instance']['state'] ?? 'close');
        $connected = $state === 'open';
        $phone = $company->whatsapp_phone;
        $pushname = null;
        if ($connected && !$phone) {
            $records = $this->request('GET', '/instance/fetchInstances?'.http_build_query(['instanceName' => $instance]));
            foreach ($records as $record) {
                if (!is_array($record)) continue;
                $record = $record['instance'] ?? $record;
                if (($record['name'] ?? $record['instanceName'] ?? null) !== $instance) continue;
                $jid = $record['ownerJid'] ?? $record['owner'] ?? null;
                $phone = $jid ? preg_replace('/[:@].*$/', '', $jid) : null;
                $pushname = $record['profileName'] ?? null;
            }
        }
        $company->forceFill([
            'whatsapp_status' => $connected ? 'connected' : ($state === 'connecting' ? 'connecting' : 'disconnected'),
            'whatsapp_connected_at' => $connected ? ($company->whatsapp_connected_at ?? now()) : null,
            'whatsapp_phone' => $phone,
        ])->save();
        if ($connected) Cache::forget($this->qrKey($instance));
        return [
            'connected' => $connected,
            'state' => $connected ? 'connected' : ($state === 'close' ? 'disconnected' : $state),
            'phone' => ['wid' => ['user' => $phone], 'pushname' => $pushname],
        ];
    }

    public function fetchQrCode(Company $company): ?string
    {
        $instance = $this->ensureSessionId($company);
        $data = $this->request('GET', '/instance/connect/'.rawurlencode($instance), [], true);
        if ($data === null) return null;
        if (!empty($data['error'])) throw new RuntimeException('A Evolution não conseguiu iniciar a conexão. Tente gerar o QR Code novamente.');
        $qr = $data['base64'] ?? $data['qrcode']['base64'] ?? null;
        $this->rememberQrCode($instance, $qr);
        return $qr ? $this->normalizeQr($qr) : $this->cachedQrCode($company);
    }

    public function rememberQrCode(string $instance, ?string $qr): void
    {
        if ($qr) Cache::put($this->qrKey($instance), $this->normalizeQr($qr), now()->addSeconds(60));
    }

    public function cachedQrCode(Company $company): ?string
    {
        return Cache::get($this->qrKey($this->ensureSessionId($company)));
    }

    public function logoutSession(Company $company): array
    {
        $instance = $this->ensureSessionId($company);
        $status = $this->fetchStatus($company);
        if (in_array($status['state'], ['connected', 'connecting'], true)) {
            try { $this->request('DELETE', '/instance/logout/'.rawurlencode($instance)); }
            catch (RuntimeException $exception) {
                // Baileys may close an unpaired socket and still return an error.
                $after = $this->fetchStatus($company);
                if ($after['connected'] || $after['state'] === 'connecting') throw $exception;
            }
        }
        Cache::forget($this->qrKey($instance));
        $company->forceFill(['whatsapp_status' => 'disconnected', 'whatsapp_connected_at' => null, 'whatsapp_phone' => null])->save();
        return ['connected' => false, 'state' => 'disconnected'];
    }

    public function sendMessage(Company $company, string $phone, string $message): array
    {
        if (!$this->fetchStatus($company)['connected']) {
            throw new RuntimeException('O WhatsApp da empresa está desconectado. Conecte-o em Configurações antes de enviar os alertas.');
        }
        $data = $this->request('POST', '/message/sendText/'.rawurlencode($this->ensureSessionId($company)), [
            'number' => $phone, 'text' => $message, 'linkPreview' => false,
        ], false, max(12, (int) config('services.evolution.send_timeout', 45)));
        if (empty($data['key']['id'])) throw new RuntimeException('A Evolution não confirmou o recebimento da mensagem. Consulte o histórico antes de tentar novamente.');
        return ['message_id' => $data['key']['id'], 'provider' => 'evolution', 'status' => $data['status'] ?? 'PENDING'];
    }

    private function qrKey(string $instance): string { return 'evolution:qr:'.$instance; }
    private function normalizeQr(string $qr): string { return str_starts_with($qr, 'data:image/') ? $qr : 'data:image/png;base64,'.$qr; }

    private function request(string $method, string $path, array $payload = [], bool $allowMissing = false, int $timeout = 12): ?array
    {
        if (!$this->configured()) throw new RuntimeException('A Evolution API ainda não foi configurada no servidor.');
        try {
            $response = Http::acceptJson()->withHeaders(['apikey' => config('services.evolution.api_key')])
                ->connectTimeout(3)->timeout($timeout)->send($method, rtrim(config('services.evolution.base_url'), '/').$path,
                    $payload ? ['json' => $payload] : []);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 28')) {
                $message = str_starts_with($path, '/message/sendText/')
                    ? 'A Evolution API demorou para confirmar o envio. A mensagem pode ter sido enviada. Confira o WhatsApp e o histórico antes de tentar novamente.'
                    : 'A Evolution API demorou para responder. Aguarde e atualize o status do WhatsApp.';
                throw new RuntimeException($message, 0, $exception);
            }
            throw new RuntimeException('Não foi possível conectar à Evolution API. Verifique o serviço de WhatsApp no servidor.', 0, $exception);
        }
        if ($allowMissing && $response->status() === 404) return null;
        if ($response->failed()) {
            $message = match ($response->status()) {
                400 => $this->rejectionMessage($response->json()),
                401, 403 => 'A Evolution API recusou as credenciais. Verifique a chave configurada no servidor.',
                429 => 'A Evolution API recebeu muitas solicitações. Aguarde e tente novamente.',
                default => 'A Evolution API não conseguiu concluir a operação (HTTP '.$response->status().').',
            };
            throw new RuntimeException($message);
        }
        $data = $response->json();
        if (!is_array($data)) throw new RuntimeException('A Evolution API retornou uma resposta inválida.');
        return $data;
    }

    private function rejectionMessage(mixed $payload): string
    {
        $details = is_array($payload) ? ($payload['response']['message'] ?? $payload['message'] ?? null) : null;
        $details = is_array($details) ? $details : [$details];
        $messages = [];
        foreach (array_slice($details, 0, 3) as $detail) {
            if (is_array($detail) && ($detail['exists'] ?? null) === false) {
                return 'A Evolution não reconheceu o destinatário como um número com WhatsApp. Confira o DDI, DDD e número salvo.';
            }
            if (!is_string($detail) || preg_match('/apikey|password|secret|token|stack/i', $detail)) continue;
            foreach (['api_key', 'webhook_secret'] as $key) {
                $secret = config('services.evolution.'.$key);
                if (is_string($secret) && $secret !== '') $detail = str_replace($secret, '[oculto]', $detail);
            }
            $detail = preg_replace('~https?://\S+~', '[endereço omitido]', $detail);
            $detail = trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($detail)));
            if ($detail !== '') $messages[] = mb_substr($detail, 0, 200);
        }
        return $messages
            ? 'A Evolution recusou a solicitação: '.implode(' ', $messages)
            : 'A Evolution recusou a solicitação (HTTP 400), sem informar um motivo detalhado.';
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsappService
{
    public function ensureSessionId(Company $company): string
    {
        if ($company->whatsapp_session_id) {
            return $company->whatsapp_session_id;
        }

        $slug = $company->slug ?: Str::slug($company->nome);
        $sessionId = sprintf('%s-company-%d', $slug ?: 'company', $company->id);

        $company->forceFill(['whatsapp_session_id' => strtolower($sessionId)])->save();

        return $company->whatsapp_session_id;
    }

    public function startSession(Company $company): array
    {
        $sessionId = $this->ensureSessionId($company);
        $payload = [
            'waitQrCode' => true,
        ];

        if ($webhook = config('services.whatsapp.webhook')) {
            $payload['webhook'] = $webhook;
        }

        $response = $this->request($company, 'POST', "/api/{$sessionId}/start-session", $payload);

        return (array) $response;
    }

    public function logoutSession(Company $company): array
    {
        $sessionId = $this->ensureSessionId($company);
        $response = $this->request($company, 'POST', "/api/{$sessionId}/logout");

        $company->forceFill([
            'whatsapp_status' => 'disconnected',
            'whatsapp_connected_at' => null,
            'whatsapp_phone' => null,
        ])->save();

        return (array) $response;
    }

    public function fetchStatus(Company $company): array
    {
        $sessionId = $this->ensureSessionId($company);
        $response = $this->request($company, 'GET', "/api/{$sessionId}/check-connection-session");

        $status = (array) $response;
        $connected = (bool) ($status['connected'] ?? false);
        $state = $status['state'] ?? $status['status'] ?? null;
        $phone = $status['phone']['wid']['user'] ?? $status['phone']['device_model'] ?? null;

        $company->forceFill([
            'whatsapp_status' => $connected ? 'connected' : ($state ?: 'disconnected'),
            'whatsapp_connected_at' => $connected ? now() : $company->whatsapp_connected_at,
            'whatsapp_phone' => $connected ? $phone : $company->whatsapp_phone,
        ])->save();

        return $status;
    }

    public function fetchQrCode(Company $company): ?string
    {
        $sessionId = $this->ensureSessionId($company);
        $response = $this->request($company, 'GET', "/api/{$sessionId}/qrcode", [], ['image' => true]);

        $data = (array) $response;
        $raw = $data['base64'] ?? $data['qrcode'] ?? $data['qrCode'] ?? $data['base64Qr'] ?? null;

        if (!$raw) {
            return null;
        }

        return str_starts_with($raw, 'data:image') ? $raw : "data:image/png;base64,{$raw}";
    }

    public function sendMessage(Company $company, string $phone, string $message): array
    {
        $sessionId = $this->ensureSessionId($company);
        $normalized = $this->normalizePhone($phone);

        if (!$normalized) {
            throw new \InvalidArgumentException('Número de WhatsApp inválido.');
        }

        $payload = [
            'phone' => $normalized,
            'text' => $message,
        ];

        return (array) $this->request($company, 'POST', "/api/{$sessionId}/send-text", $payload);
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits ?: null;
    }

    protected function request(Company $company, string $method, string $path, array $payload = [], array $query = []): array
    {
        $baseUrl = rtrim(config('services.whatsapp.base_url', ''), '/');
        if (!$baseUrl) {
            throw new \RuntimeException('Configure a URL do WPPConnect.');
        }

        $secret = config('services.whatsapp.secret');
        if ($secret) {
            $query['secret_key'] = $secret;
        }

        $token = $this->ensureAuthToken($company);

        $response = Http::timeout(20)
            ->withToken($token)
            ->withOptions(['query' => $query])
            ->send(strtoupper($method), "{$baseUrl}{$path}", $method === 'GET' ? [] : ['json' => $payload]);

        if ($response->status() === 401) {
            $this->invalidateAuthToken($company);
            $token = $this->refreshAuthToken($company);
            $response = Http::timeout(20)
                ->withToken($token)
                ->withOptions(['query' => $query])
                ->send(strtoupper($method), "{$baseUrl}{$path}", $method === 'GET' ? [] : ['json' => $payload]);
        }

        if ($response->failed()) {
            throw new \RuntimeException($response->body() ?: 'Falha ao comunicar com o WPPConnect.');
        }

        return $response->json() ?? [];
    }

    protected function ensureAuthToken(Company $company): string
    {
        if (
            $company->whatsapp_api_token &&
            $company->whatsapp_api_token_expires_at &&
            $company->whatsapp_api_token_expires_at->isFuture()
        ) {
            return $company->whatsapp_api_token;
        }

        return $this->refreshAuthToken($company);
    }

    protected function refreshAuthToken(Company $company): string
    {
        $credentials = $this->credentials();
        $baseUrl = rtrim(config('services.whatsapp.base_url', ''), '/');

        if (!$baseUrl) {
            throw new \RuntimeException('Configure a URL do WPPConnect.');
        }

        $response = Http::timeout(20)->post("{$baseUrl}/api/login", [
            'email' => $credentials['user'],
            'password' => $credentials['password'],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException($response->body() ?: 'Falha ao autenticar no WPPConnect.');
        }

        $payload = $response->json() ?? [];
        $token = $payload['token'] ?? null;

        if (!$token) {
            throw new \RuntimeException('Token não retornado pelo WPPConnect.');
        }

        $expiresIn = (int) ($payload['expiresIn'] ?? 3600);
        if ($expiresIn <= 0) {
            $expiresIn = 3600;
        }

        $company->forceFill([
            'whatsapp_api_token' => $token,
            'whatsapp_api_token_expires_at' => now()->addSeconds($expiresIn),
        ])->save();

        return $token;
    }

    protected function invalidateAuthToken(Company $company): void
    {
        $company->forceFill([
            'whatsapp_api_token' => null,
            'whatsapp_api_token_expires_at' => null,
        ])->save();
    }

    protected function credentials(): array
    {
        $user = config('services.whatsapp.user');
        $password = config('services.whatsapp.password');

        if (!$user || !$password) {
            throw new \RuntimeException('Configure as credenciais de login do WPPConnect.');
        }

        return ['user' => $user, 'password' => $password];
    }
}

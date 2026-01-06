<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleClientVerifier
{
    private string $clientId;

    public function __construct(?string $clientId = null)
    {
        $this->clientId = $clientId ?? config('services.google.client_id', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $credential): array
    {
        if (!$this->clientId) {
            throw new RuntimeException('Google Client ID is not configured.');
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $credential,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google credential is invalid.');
        }

        $payload = $response->json();

        if (!is_array($payload) || ($payload['aud'] ?? null) !== $this->clientId) {
            throw new RuntimeException('Google credential audience mismatch.');
        }

        if (!filter_var($payload['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google credential missing verified email.');
        }

        return $payload;
    }
}

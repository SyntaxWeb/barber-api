<?php

namespace App\Services;

use App\Models\Company;
use App\Services\Whatsapp\EvolutionProvider;
use App\Services\Whatsapp\WhatsappPhone;
use App\Services\Whatsapp\WppConnectProvider;
use Illuminate\Support\Facades\Cache;

class WhatsappService
{
    public function __construct(private EvolutionProvider $evolution, private WppConnectProvider $wppconnect) {}

    public function providerName(): string { return config('services.whatsapp.provider', 'evolution'); }
    private function provider(): EvolutionProvider|WppConnectProvider
    {
        return match ($this->providerName()) {
            'evolution' => $this->evolution,
            'wppconnect' => $this->wppconnect,
            default => throw new \RuntimeException('Provedor de WhatsApp inválido na configuração do servidor.'),
        };
    }
    public function configured(): bool { return $this->providerName() === 'evolution' ? $this->evolution->configured() : (filled(config('services.whatsapp.base_url')) && filled(config('services.whatsapp.user')) && filled(config('services.whatsapp.password'))); }
    public function ensureSessionId(Company $company): string { return $this->provider()->ensureSessionId($company); }
    public function startSession(Company $company): array
    {
        return Cache::lock('whatsapp:connect:'.$company->id, 90)->block(3, function () use ($company) {
            if ($this->providerName() === 'evolution') Cache::put($this->pairingKey($company), true, now()->addMinutes(3));
            try { $result = $this->provider()->startSession($company); }
            catch (\Throwable $exception) { Cache::forget($this->pairingKey($company)); throw $exception; }
            if (!empty($result['connected'])) Cache::forget($this->pairingKey($company));
            if ($this->providerName() === 'wppconnect') {
                $result = $this->wppconnect->fetchStatus($company);
                $result['qr_code'] = empty($result['connected']) ? $this->wppconnect->fetchQrCode($company) : null;
            }
            return $result;
        });
    }
    public function fetchStatus(Company $company): array
    {
        $status = $this->provider()->fetchStatus($company);
        if (!empty($status['connected'])) Cache::forget($this->pairingKey($company));
        return $status;
    }
    public function fetchQrCode(Company $company): ?string
    {
        return Cache::lock('whatsapp:connect:'.$company->id, 90)->block(3, function () use ($company) {
            $company->refresh();
            return $this->isPairing($company) ? $this->provider()->fetchQrCode($company) : null;
        });
    }
    public function logoutSession(Company $company): array
    {
        // Revoke pairing before waiting for the lock: late QR polling must not reconnect.
        Cache::forget($this->pairingKey($company));
        return Cache::lock('whatsapp:connect:'.$company->id, 90)->block(3, function () use ($company) {
            $company->refresh();
            Cache::forget($this->pairingKey($company));
            return $this->provider()->logoutSession($company);
        });
    }
    public function isPairing(Company $company): bool { return $this->providerName() === 'wppconnect' || (bool) Cache::get($this->pairingKey($company), false); }
    private function pairingKey(Company $company): string { return 'whatsapp:pairing:'.$company->id; }
    public function cachedQrCode(Company $company): ?string { return $this->providerName() === 'evolution' ? $this->evolution->cachedQrCode($company) : null; }
    public function sendMessage(Company $company, string $phone, string $message): array { return $this->provider()->sendMessage($company, WhatsappPhone::normalize($phone), $message); }
}

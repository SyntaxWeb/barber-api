<?php

namespace App\Services\Whatsapp;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsappDeliveryService
{
    public function send(Company $company, string $recipient, string $message, array $meta = []): NotificationLog
    {
        $log = NotificationLog::create([
            'company_id' => $company->id, 'channel' => 'whatsapp', 'recipient' => $recipient,
            'message' => $message, 'status' => 'pending',
            'meta' => array_merge($meta, ['provider' => app(WhatsappService::class)->providerName()]),
        ]);
        try {
            $result = app(WhatsappService::class)->sendMessage($company, $recipient, $message);
            $id = $result['message_id'] ?? $result['key']['id'] ?? null;
            $log->update(['status' => 'accepted', 'meta' => array_merge($log->meta ?? [], ['provider_message_id' => $id])]);
            if ($id && ($cached = Cache::get($this->statusKey($company->id, $id)))) {
                $this->updateStatus($company, $id, $cached);
            }
            return $log->fresh();
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function updateStatus(Company $company, string $id, string $status): void
    {
        $status = $this->normalizeStatus($status);
        if (!$status) return;
        // Webhooks may reach Laravel before the send response and its message id.
        $key = $this->statusKey($company->id, $id);
        Cache::lock('evolution:delivery-lock:'.$company->id.':'.sha1($id), 10)->block(2, function () use ($key, $status) {
            $cached = Cache::get($key);
            if (!$cached || $this->rank($status) > $this->rank($cached)) Cache::put($key, $status, now()->addDay());
        });
        DB::transaction(function () use ($company, $id, $status) {
            $log = NotificationLog::where('company_id', $company->id)->where('channel', 'whatsapp')
                ->where('meta->provider', 'evolution')->where('meta->provider_message_id', $id)->lockForUpdate()->first();
            if ($log && $this->rank($status) > $this->rank($log->status)) {
                $log->update(['status' => $status, 'error' => $status === 'failed' ? 'O WhatsApp informou falha na entrega.' : null]);
            }
        });
    }

    private function normalizeStatus(string $status): ?string
    {
        return match (strtoupper($status)) {
            'ERROR', 'FAILED', '0' => 'failed',
            'PENDING', 'ACCEPTED', '1' => 'accepted',
            'SERVER_ACK', 'SENT', '2' => 'sent',
            'DELIVERY_ACK', 'DELIVERED', '3' => 'delivered',
            'READ', 'PLAYED', 'READ_ACK', '4', '5' => 'read',
            default => null,
        };
    }
    private function rank(string $status): int { return ['pending' => 0, 'accepted' => 1, 'sent' => 2, 'failed' => 3, 'delivered' => 4, 'read' => 5][$status] ?? 0; }
    private function statusKey(int $company, string $id): string { return 'evolution:delivery:'.$company.':'.$id; }
}

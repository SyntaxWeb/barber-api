<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MercadoPagoService
{
    protected string $baseUri;
    protected ?string $accessToken;

    public function __construct()
    {
        $this->baseUri = rtrim(config('services.mercadopago.base_uri', 'https://api.mercadopago.com'), '/');
        $this->accessToken = config('services.mercadopago.access_token');
    }

    protected function http(): PendingRequest
    {
        if (empty($this->accessToken)) {
            throw new \RuntimeException('Token de acesso do Mercado Pago nao configurado.');
        }

        return  Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json'
        ])->acceptJson();
    }

    protected function handleResponse($response): array
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                'Falha ao consultar o Mercado Pago: ' . $response->body()
            );
        }

        return $response->json();
    }

    public function createPaymentLink(array $data): array
    {
        $baseFront = rtrim(config('app.frontend_url', config('app.url')), '/');
        $backUrls = $data['back_urls'] ?? [
            'success' => "{$baseFront}/assinatura/sucesso",
            'failure' => "{$baseFront}/assinatura/erro",
            'pending' => "{$baseFront}/assinatura/pendente",
        ];

        $payload = array_filter([
            'items' => [
                [
                    'title' => $data['title'] ?? 'Assinatura',
                    'quantity' => $data['quantity'] ?? 1,
                    'currency_id' => $data['currency_id'] ?? 'BRL',
                    'unit_price' => $data['unit_price'],
                ],
            ],
            'payer' => array_filter([
                'email' => $data['payer']['email'] ?? null,
                'name' => $data['payer']['name'] ?? null,
            ]),
            'external_reference' => $data['external_reference'] ?? null,
            'auto_return' => $data['auto_return'] ?? 'approved',
            'back_urls' => $backUrls,
            'notification_url' => $data['notification_url'] ?? null,
        ], fn ($value) => $value !== null && $value !== []);

        if (empty($payload['notification_url'])) {
            $payload['notification_url'] = rtrim(config('app.url'), '/') . '/api/mercadopago/webhook';
        }

        return $this->handleResponse(
            $this->http()->post("{$this->baseUri}/checkout/preferences", $payload)
        );
    }

    public function findPayment(string $paymentId): array
    {
        return $this->handleResponse(
            $this->http()->get("{$this->baseUri}/v1/payments/{$paymentId}")
        );
    }

    /**
     * Retrieve subscriptions (preapprovals) from Mercado Pago.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSubscriptions(array $filters = []): array
    {
        $query = array_filter(array_merge([
            'sort' => 'date_created',
            'criteria' => 'desc',
        ], $filters), fn($value) => $value !== null && $value !== '');

        $payload = $this->handleResponse(
            $this->http()->get("{$this->baseUri}/preapproval_plan/search", $query)
        );

        $results = $payload['results'] ?? [];

        return array_map(function (array $item) {
            $auto = Arr::get($item, 'auto_recurring', []);

            return [
                'id' => $item['id'] ?? null,
                'status' => $item['status'] ?? null,
                'reason' => $item['reason'] ?? null,
                'payer_email' => $item['payer_email'] ?? null,
                'payer_id' => $item['payer_id'] ?? Arr::get($item, 'payer.id'),
                'preapproval_plan_id' => $item['preapproval_plan_id'] ?? null,
                'external_reference' => $item['external_reference'] ?? null,
                'subscription_plan_id' => Arr::get($auto, 'preapproval_plan_id'),
                'next_payment_date' => $item['next_payment_date'] ?? Arr::get($auto, 'next_payment_date'),
                'card_id' => Arr::get($item, 'card_id'),
                'auto_recurring' => [
                    'frequency' => Arr::get($auto, 'frequency'),
                    'frequency_type' => Arr::get($auto, 'frequency_type'),
                    'transaction_amount' => Arr::get($auto, 'transaction_amount'),
                    'currency_id' => Arr::get($auto, 'currency_id'),
                ],
                'raw' => $item,
            ];
        }, $results);
    }

    public function findPreapprovalPlanByReference(string $reference): ?array
    {
        $payload = $this->handleResponse(
            $this->http()->get("{$this->baseUri}/preapproval_plan/search", [
                'external_reference' => $reference,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ])
        );

        $results = $payload['results'] ?? [];

        return $results[0] ?? null;
    }

    public function createPreapprovalPlan(string $reference, array $data): array
    {
        $payload = [
            'back_url' => $data['back_url'] ?? config('app.frontend_url', config('app.url')),
            'reason' => $data['reason'],
            'auto_recurring' => [
                'frequency' => $data['frequency'],
                'frequency_type' => $data['frequency_type'],
                'transaction_amount' => $data['price'],
                'currency_id' => $data['currency'] ?? 'BRL',
            ],
            'status' => 'active',
            'external_reference' => $reference,
        ];

        return $this->handleResponse(
            $this->http()->post("{$this->baseUri}/preapproval_plan", $payload)
        );
    }

    /**
     * Synchronize configured plans with Mercado Pago, creating them when necessary.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    public function syncConfiguredPlans(array $plans): array
    {
        $synced = [];
        $defaultBackUrl = config('app.frontend_url', config('app.url'));

        foreach ($plans as $key => $plan) {
            $reference = $plan['external_reference'] ?? $key;
            $frequencyType = $plan['frequency_type'] ?? 'months';
            $frequency = $plan['frequency'] ?? ($plan['months'] ?? 1);

            $existing = $this->findPreapprovalPlanByReference($reference);
            if ($existing) {
                $synced[] = [
                    'key' => $key,
                    'status' => 'exists',
                    'plan_id' => $existing['id'] ?? null,
                    'details' => $existing,
                ];
                continue;
            }

            $created = $this->createPreapprovalPlan($reference, [
                'reason' => $plan['name'] ?? ucfirst($key),
                'frequency' => max(1, (int) $frequency),
                'frequency_type' => $frequencyType,
                'price' => $plan['price'] ?? 0,
                'currency' => $plan['currency'] ?? 'BRL',
                'back_url' => $plan['back_url'] ?? $defaultBackUrl,
            ]);

            $synced[] = [
                'key' => $key,
                'status' => 'created',
                'plan_id' => $created['id'] ?? null,
                'details' => $created,
            ];
        }

        return $synced;
    }

    protected function ensurePlanExists(string $key, array $planData): array
    {
        $existing = $this->findPreapprovalPlanByReference($key);
        if ($existing) {
            return $existing;
        }

        $frequencyType = $planData['frequency_type'] ?? 'months';
        $frequency = $planData['frequency'] ?? ($planData['months'] ?? 1);

        return $this->createPreapprovalPlan($key, [
            'reason' => $planData['name'] ?? ucfirst($key),
            'frequency' => max(1, (int) $frequency),
            'frequency_type' => $frequencyType,
            'price' => $planData['price'] ?? 0,
            'currency' => $planData['currency'] ?? 'BRL',
            'back_url' => $planData['back_url'] ?? config('app.frontend_url', config('app.url')),
        ]);
    }

    public function createSubscriptionCheckout(string $planKey, array $planData, array $options): array
    {
        $plan = $this->ensurePlanExists($planKey, $planData);
        $planId = $plan['id'] ?? $plan['preapproval_plan_id'] ?? null;

        if (!$planId) {
            throw new \RuntimeException('Nao foi possivel localizar o plano no Mercado Pago.');
        }

        $reference = $options['reference'] ?? ('company-' . ($options['company_id'] ?? 'unknown') . '-' . $planKey);
        $backUrl = $options['back_url'] ?? config('app.frontend_url', config('app.url'));

        $payload = [
            'preapproval_plan_id' => $planId,
            'reason' => $planData['name'] ?? ucfirst($planKey),
            'payer_email' => $options['payer_email'] ?? null,
            'external_reference' => $planData['external_reference'] ?? $planKey,
            'back_url' => $backUrl,
            'status' => 'pending',
        ];


        if (!empty($options['payer_name'])) {
            $payload['payer_name'] = $options['payer_name'];
        }
        return $this->handleResponse(
            $this->http()->post("{$this->baseUri}/preapproval", $payload)
        );
    }
}

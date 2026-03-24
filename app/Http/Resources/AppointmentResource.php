<?php

namespace App\Http\Resources;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $services = $this->whenLoaded('services', function () {
            return $this->services->map(fn ($service) => [
                'id' => $service->id,
                'nome' => $service->nome,
                'preco' => (float) $service->preco,
                'duracao' => (int) $service->duracao_minutos,
            ])->values();
        });

        $serviceNames = $this->relationLoaded('services') && $this->services->isNotEmpty()
            ? $this->services->pluck('nome')->implode(' + ')
            : ($this->service->nome ?? null);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cliente' => $this->cliente,
            'telefone' => $this->telefone,
            'data' => $this->data?->toDateString(),
            'horario' => $this->horario,
            'servico' => $serviceNames,
            'service_id' => $this->service_id,
            'service_ids' => $this->relationLoaded('services')
                ? $this->services->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : ($this->service_id ? [(int) $this->service_id] : []),
            'services' => $services,
            'preco' => (float) $this->preco,
            'status' => $this->status,
            'observacoes' => $this->observacoes,
            'loyalty_redemption' => $this->whenLoaded('loyaltyRedemption', function () {
                return [
                    'id' => $this->loyaltyRedemption?->id,
                    'status' => $this->loyaltyRedemption?->status,
                    'reward' => [
                        'id' => $this->loyaltyRedemption?->reward?->id,
                        'name' => $this->loyaltyRedemption?->reward?->name,
                        'grants_free_appointment' => (bool) $this->loyaltyRedemption?->reward?->grants_free_appointment,
                    ],
                ];
            }),
            'loyalty' => $this->when($this->user_id && $this->company_id, function () {
                $pointsBalance = LoyaltyAccount::query()
                    ->where('company_id', $this->company_id)
                    ->where('user_id', $this->user_id)
                    ->value('points_balance') ?? 0;

                $availableRewards = LoyaltyReward::query()
                    ->where('company_id', $this->company_id)
                    ->where('active', true)
                    ->where('points_cost', '<=', $pointsBalance)
                    ->orderBy('points_cost')
                    ->get(['id', 'name', 'description', 'points_cost'])
                    ->map(fn (LoyaltyReward $reward) => [
                        'id' => $reward->id,
                        'name' => $reward->name,
                        'description' => $reward->description,
                        'image_url' => $reward->image_url,
                        'points_cost' => $reward->points_cost,
                    ])
                    ->values();

                return [
                    'points_balance' => (int) $pointsBalance,
                    'available_rewards_count' => $availableRewards->count(),
                    'available_rewards' => $availableRewards,
                ];
            }),
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company?->id,
                    'nome' => $this->company?->nome,
                    'slug' => $this->company?->slug,
                ];
            }),
            'feedback' => $this->whenLoaded('feedback', function () {
                return [
                    'service_rating' => $this->feedback?->service_rating,
                    'professional_rating' => $this->feedback?->professional_rating,
                    'scheduling_rating' => $this->feedback?->scheduling_rating,
                    'comment' => $this->feedback?->comment,
                    'allow_public_testimonial' => $this->feedback?->allow_public_testimonial,
                    'submitted_at' => $this->feedback?->submitted_at?->toIso8601String(),
                    'average_rating' => $this->feedback
                        ? round(
                            (($this->feedback->service_rating ?? 0)
                                + ($this->feedback->professional_rating ?? 0)
                                + ($this->feedback->scheduling_rating ?? 0)) / 3,
                            2
                        )
                        : null,
                ];
            }),
        ];
    }
}

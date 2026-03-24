<?php

namespace App\Notifications;

use App\Models\LoyaltyReward;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoyaltyRewardRedeemedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $client,
        protected LoyaltyReward $reward,
        protected ?int $redemptionId = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reward_redeemed',
            'action' => 'reward_redeemed',
            'cliente' => $this->client->name,
            'telefone' => $this->client->telefone,
            'reward_id' => $this->reward->id,
            'reward_name' => $this->reward->name,
            'reward_points_cost' => $this->reward->points_cost,
            'redemption_id' => $this->redemptionId,
            'data' => now()->toDateString(),
            'horario' => now()->format('H:i'),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public const RULE_SPEND = 'spend';
    public const RULE_VISITS = 'visits';

    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_REVERSAL = 'reversal';
    public const TYPE_ADJUST = 'adjust';

    public function settingsForCompany(int $companyId): LoyaltySetting
    {
        return LoyaltySetting::firstOrCreate(
            ['company_id' => $companyId],
            [
                'enabled' => false,
                'rule_type' => self::RULE_SPEND,
                'spend_amount_cents_per_point' => 1000,
                'points_per_visit' => 1,
                'expiration_enabled' => false,
                'expiration_days' => null,
            ]
        );
    }

    public function awardForAppointment(Appointment $appointment): void
    {
        $user = $appointment->user;
        if (!$user || $user->role !== 'client') {
            return;
        }

        $settings = $this->settingsForCompany($appointment->company_id);
        if (!$settings->enabled) {
            return;
        }

        $alreadyEarned = LoyaltyTransaction::where('appointment_id', $appointment->id)
            ->where('type', self::TYPE_EARN)
            ->exists();

        if ($alreadyEarned) {
            return;
        }

        $points = $this->calculatePoints($settings, $appointment);
        if ($points <= 0) {
            return;
        }

        $expiresAt = null;
        if ($settings->expiration_enabled && $settings->expiration_days) {
            $expiresAt = now()->addDays($settings->expiration_days);
        }

        DB::transaction(function () use ($appointment, $user, $points, $expiresAt, $settings) {
            $account = LoyaltyAccount::firstOrCreate(
                [
                    'company_id' => $appointment->company_id,
                    'user_id' => $user->id,
                ],
                ['points_balance' => 0]
            );

            $this->syncExpiredPoints($account, $settings);

            $this->applyTransaction($account, [
                'company_id' => $appointment->company_id,
                'user_id' => $user->id,
                'appointment_id' => $appointment->id,
                'type' => self::TYPE_EARN,
                'points' => $points,
                'reason' => 'Atendimento concluído',
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public function revokeForAppointment(Appointment $appointment): void
    {
        $user = $appointment->user;
        if (!$user || $user->role !== 'client') {
            return;
        }

        $earned = LoyaltyTransaction::where('appointment_id', $appointment->id)
            ->where('type', self::TYPE_EARN)
            ->sum('points');

        if ($earned <= 0) {
            return;
        }

        $reversed = LoyaltyTransaction::where('appointment_id', $appointment->id)
            ->where('type', self::TYPE_REVERSAL)
            ->sum('points');

        $remaining = $earned + $reversed;
        if ($remaining <= 0) {
            return;
        }

        DB::transaction(function () use ($appointment, $user, $remaining) {
            $account = LoyaltyAccount::firstOrCreate(
                [
                    'company_id' => $appointment->company_id,
                    'user_id' => $user->id,
                ],
                ['points_balance' => 0]
            );

            $this->applyTransaction($account, [
                'company_id' => $appointment->company_id,
                'user_id' => $user->id,
                'appointment_id' => $appointment->id,
                'type' => self::TYPE_REVERSAL,
                'points' => -$remaining,
                'reason' => 'Reversão de atendimento',
            ]);
        });
    }

    public function redeemReward(LoyaltyReward $reward, User $user): LoyaltyAccount
    {
        $account = LoyaltyAccount::firstOrCreate(
            [
                'company_id' => $reward->company_id,
                'user_id' => $user->id,
            ],
            ['points_balance' => 0]
        );

        $settings = $this->settingsForCompany($reward->company_id);
        $this->syncExpiredPoints($account, $settings);

        if ($account->points_balance < $reward->points_cost) {
            abort(422, 'Saldo insuficiente para resgatar esta recompensa.');
        }

        $this->applyTransaction($account, [
            'company_id' => $reward->company_id,
            'user_id' => $user->id,
            'type' => self::TYPE_REDEEM,
            'points' => -$reward->points_cost,
            'reason' => sprintf('Resgate: %s', $reward->name),
        ]);

        return $account->fresh();
    }

    public function syncExpiredPoints(LoyaltyAccount $account, LoyaltySetting $settings): void
    {
        if (!$settings->expiration_enabled || !$settings->expiration_days) {
            return;
        }

        $expiredEarns = LoyaltyTransaction::where('loyalty_account_id', $account->id)
            ->where('type', self::TYPE_EARN)
            ->whereNull('expired_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expiredEarns->isEmpty()) {
            return;
        }

        $expiredPoints = $expiredEarns->sum('points');
        $pointsToExpire = min($account->points_balance, $expiredPoints);

        $expiredEarns->each(function (LoyaltyTransaction $transaction) {
            $transaction->forceFill(['expired_at' => now()])->save();
        });

        if ($pointsToExpire <= 0) {
            return;
        }

        $this->applyTransaction($account, [
            'company_id' => $account->company_id,
            'user_id' => $account->user_id,
            'type' => self::TYPE_EXPIRE,
            'points' => -$pointsToExpire,
            'reason' => 'Pontos expirados',
        ]);
    }

    private function calculatePoints(LoyaltySetting $settings, Appointment $appointment): int
    {
        if ($settings->rule_type === self::RULE_VISITS) {
            return max(0, (int) $settings->points_per_visit);
        }

        $appointment->loadMissing('service');

        $price = $appointment->preco ?? $appointment->service?->preco;
        if ($price === null) {
            return 0;
        }

        $amountCents = (int) round(((float) $price) * 100);
        $unit = max(1, (int) $settings->spend_amount_cents_per_point);
        return (int) floor($amountCents / $unit);
    }

    private function applyTransaction(LoyaltyAccount $account, array $payload): LoyaltyTransaction
    {
        $transaction = $account->transactions()->create($payload + [
            'loyalty_account_id' => $account->id,
        ]);

        $newBalance = $account->points_balance + $transaction->points;
        if ($newBalance < 0) {
            $newBalance = 0;
        }

        $account->forceFill(['points_balance' => $newBalance])->save();

        return $transaction;
    }
}

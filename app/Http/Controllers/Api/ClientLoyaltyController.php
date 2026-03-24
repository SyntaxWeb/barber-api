<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Notifications\LoyaltyRewardRedeemedNotification;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ClientLoyaltyController extends Controller
{
    public function show(Request $request, LoyaltyService $loyalty)
    {
        $user = $request->user('sanctum');
        if (!$user?->company_id) {
            abort(403, 'Cliente não vinculado a uma empresa.');
        }

        $account = LoyaltyAccount::firstOrCreate(
            [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
            ],
            ['points_balance' => 0]
        );

        $settings = $loyalty->settingsForCompany($user->company_id);
        $loyalty->syncExpiredPoints($account, $settings);
        $account->refresh();

        $rewards = LoyaltyReward::where('company_id', $user->company_id)
            ->where('active', true)
            ->orderBy('points_cost')
            ->get();

        $transactions = LoyaltyTransaction::where('loyalty_account_id', $account->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'id',
                'type',
                'points',
                'reason',
                'created_at',
            ]);

        $pendingRedemptions = LoyaltyRedemption::with('reward')
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'points_balance' => $account->points_balance,
            'rewards' => $rewards,
            'transactions' => $transactions,
            'pending_redemptions' => $pendingRedemptions->map(function (LoyaltyRedemption $redemption) {
                return [
                    'id' => $redemption->id,
                    'status' => $redemption->status,
                    'created_at' => $redemption->created_at?->toIso8601String(),
                    'reward' => [
                        'id' => $redemption->reward?->id,
                        'name' => $redemption->reward?->name,
                        'description' => $redemption->reward?->description,
                        'image_url' => $redemption->reward?->image_url,
                        'points_cost' => $redemption->reward?->points_cost,
                        'grants_free_appointment' => (bool) $redemption->reward?->grants_free_appointment,
                    ],
                ];
            })->values(),
        ]);
    }

    public function redeem(Request $request, LoyaltyService $loyalty)
    {
        $user = $request->user('sanctum');
        if (!$user?->company_id) {
            abort(403, 'Cliente não vinculado a uma empresa.');
        }

        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:loyalty_rewards,id'],
        ]);

        $reward = LoyaltyReward::where('company_id', $user->company_id)
            ->where('id', $data['reward_id'])
            ->where('active', true)
            ->firstOrFail();

        $result = $loyalty->redeemReward($reward, $user);
        $account = $result['account'];
        $redemption = $result['redemption'];

        $providers = $user->company?->users()->where('role', 'provider')->get() ?? collect();
        if ($providers->isNotEmpty()) {
            Notification::send($providers, new LoyaltyRewardRedeemedNotification($user, $reward, $redemption?->id));
        }

        return response()->json([
            'points_balance' => $account->points_balance,
            'redemption' => $redemption ? [
                'id' => $redemption->id,
                'status' => $redemption->status,
                'reward' => [
                    'id' => $redemption->reward?->id,
                    'name' => $redemption->reward?->name,
                    'grants_free_appointment' => (bool) $redemption->reward?->grants_free_appointment,
                ],
            ] : null,
        ]);
    }
}

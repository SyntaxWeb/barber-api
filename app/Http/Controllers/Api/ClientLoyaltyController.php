<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

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

        return response()->json([
            'points_balance' => $account->points_balance,
            'rewards' => $rewards,
            'transactions' => $transactions,
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

        $account = $loyalty->redeemReward($reward, $user);

        return response()->json([
            'points_balance' => $account->points_balance,
        ]);
    }
}

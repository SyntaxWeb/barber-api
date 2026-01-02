<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoyaltyRewardRequest;
use App\Models\LoyaltyReward;
use Illuminate\Http\Request;

class LoyaltyRewardController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        return response()->json(
            LoyaltyReward::where('company_id', $companyId)->orderBy('name')->get()
        );
    }

    public function store(LoyaltyRewardRequest $request)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $reward = LoyaltyReward::create($request->validated() + [
            'company_id' => $companyId,
            'active' => $request->boolean('active', true),
        ]);

        return response()->json($reward, 201);
    }

    public function update(LoyaltyRewardRequest $request, LoyaltyReward $loyaltyReward)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if ($loyaltyReward->company_id !== $companyId) {
            abort(403, 'Recompensa não pertence à sua empresa.');
        }

        $payload = $request->validated();
        if (array_key_exists('active', $payload)) {
            $payload['active'] = (bool) $payload['active'];
        }

        $loyaltyReward->update($payload);

        return response()->json($loyaltyReward->fresh());
    }

    public function destroy(Request $request, LoyaltyReward $loyaltyReward)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if ($loyaltyReward->company_id !== $companyId) {
            abort(403, 'Recompensa não pertence à sua empresa.');
        }

        $loyaltyReward->delete();

        return response()->noContent();
    }
}

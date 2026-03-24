<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoyaltyRewardRequest;
use App\Models\LoyaltyReward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $payload = $request->validated();

        if ($request->hasFile('image')) {
            $payload['image_path'] = $request->file('image')->store('loyalty-rewards', 'public');
        }

        unset($payload['image'], $payload['remove_image']);

        $reward = LoyaltyReward::create($payload + [
            'company_id' => $companyId,
            'active' => $request->boolean('active', true),
            'grants_free_appointment' => $request->boolean('grants_free_appointment', false),
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
        if ($request->boolean('remove_image') && $loyaltyReward->image_path) {
            Storage::disk('public')->delete($loyaltyReward->image_path);
            $payload['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($loyaltyReward->image_path) {
                Storage::disk('public')->delete($loyaltyReward->image_path);
            }
            $payload['image_path'] = $request->file('image')->store('loyalty-rewards', 'public');
        }

        unset($payload['image'], $payload['remove_image']);

        if (array_key_exists('active', $payload)) {
            $payload['active'] = (bool) $payload['active'];
        }
        if (array_key_exists('grants_free_appointment', $payload)) {
            $payload['grants_free_appointment'] = (bool) $payload['grants_free_appointment'];
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

        if ($loyaltyReward->image_path) {
            Storage::disk('public')->delete($loyaltyReward->image_path);
        }

        $loyaltyReward->delete();

        return response()->noContent();
    }
}

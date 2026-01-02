<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLoyaltySettingsRequest;
use App\Models\LoyaltySetting;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltySettingsController extends Controller
{
    public function show(Request $request, LoyaltyService $loyalty)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $settings = $loyalty->settingsForCompany($companyId);
        return response()->json($this->formatSettings($settings));
    }

    public function update(UpdateLoyaltySettingsRequest $request, LoyaltyService $loyalty)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $settings = $loyalty->settingsForCompany($companyId);
        $data = $request->validated();

        if (empty($data['expiration_enabled'])) {
            $data['expiration_days'] = null;
        }

        $settings->update($data);

        return response()->json($this->formatSettings($settings->fresh()));
    }

    private function formatSettings(LoyaltySetting $settings): array
    {
        return [
            'enabled' => (bool) $settings->enabled,
            'rule_type' => $settings->rule_type,
            'spend_amount_cents_per_point' => $settings->spend_amount_cents_per_point,
            'points_per_visit' => $settings->points_per_visit,
            'expiration_enabled' => (bool) $settings->expiration_enabled,
            'expiration_days' => $settings->expiration_days,
        ];
    }
}

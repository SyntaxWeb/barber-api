<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('company')
            ->where('role', 'provider')
            ->orderBy('name');

        if ($request->filled('plan')) {
            $query->whereHas('company', fn ($builder) => $builder->where('subscription_plan', $request->plan));
        }

        if ($request->filled('status')) {
            $query->whereHas('company', fn ($builder) => $builder->where('subscription_status', $request->status));
        }

        return $query->get();
    }

    public function plans()
    {
        return response()->json([
            'plans' => config('subscriptions.plans'),
            'statuses' => config('subscriptions.statuses'),
        ]);
    }

    public function updateSubscription(UpdateSubscriptionRequest $request, Company $company)
    {
        $data = $request->validated();
        $company->update([
            'subscription_plan' => $data['plan'],
            'subscription_status' => $data['status'],
            'subscription_price' => $data['price'],
            'subscription_renews_at' => $data['renews_at'],
        ]);

        return $company->only([
            'id',
            'nome',
            'subscription_plan',
            'subscription_status',
            'subscription_price',
            'subscription_renews_at',
        ]);
    }
}

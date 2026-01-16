<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentFeedback;
use App\Models\Company;
use App\Models\SubscriptionOrder;
use App\Models\User;
use Carbon\Carbon;

class SystemReportController extends Controller
{
    public function show()
    {
        $today = Carbon::today();
        $startMonth = $today->copy()->startOfMonth();
        $start30 = $today->copy()->subDays(30);

        $baseQuery = Appointment::query();

        $summary = [
            'total_appointments' => (clone $baseQuery)->count(),
            'confirmed' => (clone $baseQuery)->where('status', 'confirmado')->count(),
            'completed' => (clone $baseQuery)->where('status', 'concluido')->count(),
            'upcoming_week' => (clone $baseQuery)
                ->whereBetween('data', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                ->count(),
            'revenue_month' => (float) (clone $baseQuery)
                ->where('status', 'concluido')
                ->whereBetween('data', [$startMonth->toDateString(), $today->toDateString()])
                ->sum('preco'),
        ];

        $feedbackStats = AppointmentFeedback::selectRaw('COUNT(*) as total, AVG((service_rating + professional_rating + scheduling_rating)/3) as average')
            ->first();

        $pendingFeedback = (clone $baseQuery)
            ->where('status', 'concluido')
            ->whereDoesntHave('feedback')
            ->count();

        $feedback = [
            'average' => $feedbackStats && $feedbackStats->average !== null ? round((float) $feedbackStats->average, 2) : null,
            'responses' => (int) ($feedbackStats->total ?? 0),
            'pending' => (int) $pendingFeedback,
        ];

        $topClients = Appointment::selectRaw('COALESCE(cliente, "Cliente") as cliente, telefone, COUNT(*) as total, MAX(data) as last_visit')
            ->groupBy('cliente', 'telefone')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                return [
                    'cliente' => $row->cliente,
                    'telefone' => $row->telefone,
                    'total' => (int) $row->total,
                    'last_visit' => $row->last_visit ? Carbon::parse($row->last_visit)->toDateString() : null,
                ];
            })
            ->values();

        $servicePerformance = Appointment::with('service:id,nome')
            ->selectRaw('service_id, COUNT(*) as total, SUM(preco) as revenue')
            ->groupBy('service_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'service_id' => $row->service_id,
                    'servico' => $row->service?->nome ?? 'Servico',
                    'total' => (int) $row->total,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $trend = Appointment::selectRaw('DATE(data) as date, COUNT(*) as total')
            ->whereBetween('data', [$start30->toDateString(), $today->toDateString()])
            ->groupByRaw('DATE(data)')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'total' => (int) $row->total,
                ];
            })
            ->values();

        $subscriptionRevenue = SubscriptionOrder::query()
            ->where('status', 'pago')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startMonth->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->sum('price');

        $systemOverview = [
            'total_companies' => Company::count(),
            'active_companies' => Company::where('subscription_status', 'ativo')->count(),
            'new_companies_30d' => Company::where('created_at', '>=', $start30)->count(),
            'active_providers' => User::where('role', 'provider')->count(),
            'total_clients' => User::where('role', 'client')->count(),
            'new_clients_30d' => User::where('role', 'client')->where('created_at', '>=', $start30)->count(),
            'revenue_month' => (float) $subscriptionRevenue,
        ];

        $planBreakdown = Company::selectRaw('COALESCE(subscription_plan, "Sem plano") as label, COUNT(*) as total')
            ->groupBy('label')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'label' => $row->label,
                    'total' => (int) $row->total,
                ];
            })
            ->values();

        $statusBreakdown = Company::selectRaw('COALESCE(subscription_status, "pendente") as label, COUNT(*) as total')
            ->groupBy('label')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'label' => $row->label,
                    'total' => (int) $row->total,
                ];
            })
            ->values();

        $recentCompanies = Company::select('id', 'nome', 'subscription_plan', 'subscription_status', 'created_at')
            ->latest()
            ->limit(6)
            ->get()
            ->map(function ($company) {
                return [
                    'id' => $company->id,
                    'nome' => $company->nome,
                    'subscription_plan' => $company->subscription_plan,
                    'subscription_status' => $company->subscription_status,
                    'created_at' => optional($company->created_at)->toDateString(),
                ];
            })
            ->values();

        return response()->json([
            'summary' => $summary,
            'feedback' => $feedback,
            'top_clients' => $topClients,
            'services' => $servicePerformance,
            'trend' => $trend,
            'system_overview' => $systemOverview,
            'plans_breakdown' => $planBreakdown,
            'status_breakdown' => $statusBreakdown,
            'recent_companies' => $recentCompanies,
        ]);
    }
}

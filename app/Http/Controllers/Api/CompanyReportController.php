<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentFeedback;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CompanyReportController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user('sanctum');
        $companyId = $user?->company_id;

        if (!$companyId) {
            return response()->json(['message' => 'Empresa não encontrada.'], 403);
        }

        $baseQuery = Appointment::where('company_id', $companyId);
        $today = Carbon::today();
        $startMonth = $today->copy()->startOfMonth();
        $start30 = $today->copy()->subDays(30);

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
            ->whereHas('appointment', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
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
            ->where('company_id', $companyId)
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
            ->where('company_id', $companyId)
            ->groupBy('service_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'service_id' => $row->service_id,
                    'servico' => $row->service?->nome ?? 'Serviço',
                    'total' => (int) $row->total,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $trend = Appointment::selectRaw('DATE(data) as date, COUNT(*) as total')
            ->where('company_id', $companyId)
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

        return response()->json([
            'summary' => $summary,
            'feedback' => $feedback,
            'top_clients' => $topClients,
            'services' => $servicePerformance,
            'trend' => $trend,
        ]);
    }
}

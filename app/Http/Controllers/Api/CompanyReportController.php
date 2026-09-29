<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentFeedback;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyReportController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user('sanctum');
        $companyId = $user?->company_id;

        if (!$companyId) {
            return response()->json(['message' => 'Empresa não encontrada.'], 403);
        }

        $today = Carbon::today();
        $period = $request->validate([
            'period' => ['nullable', 'in:day,week,month'],
        ])['period'] ?? 'month';
        [$periodStart, $periodEnd] = match ($period) {
            'day' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'week' => [$today->copy()->startOfWeek()->startOfDay(), $today->copy()->endOfWeek()->endOfDay()],
            default => [$today->copy()->startOfMonth()->startOfDay(), $today->copy()->endOfMonth()->endOfDay()],
        };

        $baseQuery = Appointment::where('company_id', $companyId)
            ->whereBetween('data', [$periodStart->toDateString(), $periodEnd->toDateString()]);
        $salesQuery = Sale::where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$periodStart, $periodEnd]);

        $summary = [
            'total_appointments' => (clone $baseQuery)->count(),
            'confirmed' => (clone $baseQuery)->where('status', 'confirmado')->count(),
            'completed' => (clone $baseQuery)->where('status', 'concluido')->count(),
            'upcoming_week' => (clone $baseQuery)->where('status', '!=', 'cancelado')->count(),
            'revenue_month' => (float) (clone $salesQuery)->sum('total'),
            'services_revenue_month' => (float) (clone $salesQuery)->sum('services_total'),
            'products_revenue_month' => (float) (clone $salesQuery)->sum('products_total'),
            'closed_sales_month' => (int) (clone $salesQuery)->count(),
        ];

        $feedbackStats = AppointmentFeedback::selectRaw('COUNT(*) as total, AVG((service_rating + professional_rating + scheduling_rating)/3) as average')
            ->whereHas('appointment', function ($query) use ($companyId, $periodStart, $periodEnd) {
                $query->where('company_id', $companyId)
                    ->where('status', 'concluido')
                    ->whereBetween('data', [$periodStart->toDateString(), $periodEnd->toDateString()]);
            })
            ->whereBetween('service_rating', [1, 5])
            ->whereBetween('professional_rating', [1, 5])
            ->whereBetween('scheduling_rating', [1, 5])
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
            ->where('status', 'concluido')
            ->whereBetween('data', [$periodStart->toDateString(), $periodEnd->toDateString()])
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

        $servicePerformance = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', 'closed')
            ->whereBetween('sales.closed_at', [$periodStart, $periodEnd])
            ->where('sale_items.type', 'service')
            ->selectRaw('sale_items.service_id, sale_items.description as servico, SUM(sale_items.quantity) as total, SUM(sale_items.total) as revenue')
            ->groupBy('sale_items.service_id', 'sale_items.description')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                return [
                    'service_id' => $row->service_id,
                    'servico' => $row->servico ?? 'Serviço',
                    'total' => (int) $row->total,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $productPerformance = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', 'closed')
            ->whereBetween('sales.closed_at', [$periodStart, $periodEnd])
            ->where('sale_items.type', 'product')
            ->selectRaw('sale_items.product_id, sale_items.description as produto, SUM(sale_items.quantity) as total, SUM(sale_items.total) as revenue')
            ->groupBy('sale_items.product_id', 'sale_items.description')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                return [
                    'product_id' => $row->product_id,
                    'produto' => $row->produto ?? 'Produto',
                    'total' => (int) $row->total,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $trend = Appointment::selectRaw('DATE(data) as date, COUNT(*) as total')
            ->where('company_id', $companyId)
            ->whereBetween('data', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->where('status', '!=', 'cancelado')
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
            'period' => [
                'type' => $period,
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
            'summary' => $summary,
            'feedback' => $feedback,
            'top_clients' => $topClients,
            'services' => $servicePerformance,
            'products' => $productPerformance,
            'trend' => $trend,
        ]);
    }
}

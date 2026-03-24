<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability)
    {
        $request->validate([
            'date' => 'required|date',
            'company' => 'nullable|string',
            'service_id' => 'nullable|integer',
            'service_ids' => 'nullable',
            'appointment_id' => 'nullable|integer',
        ]);

        $companyId = $this->resolveCompanyId($request);
        $serviceIds = $this->resolveServiceIds($request);
        $appointmentId = $request->integer('appointment_id') ?: null;

        $disponibilidade = $availability->horariosDisponiveisPorHora(
            $request->date,
            $companyId,
            $serviceIds,
            $appointmentId
        );

        return response()->json($disponibilidade);
    }

    private function resolveCompanyId(Request $request): int
    {
        if ($request->user('sanctum')?->company_id) {
            return $request->user('sanctum')->company_id;
        }

        if ($slug = $request->query('company')) {
            $company = Company::where('slug', $slug)->first();
            if ($company) {
                return $company->id;
            }
            abort(404, 'Empresa não encontrada.');
        }
        abort(400, 'Empresa não informada.');
    }

    private function resolveServiceIds(Request $request): array
    {
        $raw = $request->query('service_ids');

        if (is_string($raw) && trim($raw) !== '') {
            return collect(explode(',', $raw))
                ->map(fn ($value) => (int) trim($value))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (is_array($raw)) {
            return collect($raw)
                ->map(fn ($value) => (int) $value)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $single = $request->integer('service_id');
        return $single ? [$single] : [];
    }
}

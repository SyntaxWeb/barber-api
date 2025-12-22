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
            'appointment_id' => 'nullable|integer',
        ]);

        return response()->json([
            'horarios' => $availability->horariosDisponiveis(
                $request->date,
                $this->resolveCompanyId($request),
                $request->integer('service_id') ?: null,
                $request->integer('appointment_id') ?: null
            ),
        ]);
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

        $fallback = Company::first();
        if (!$fallback) {
            abort(404, 'Nenhuma empresa configurada.');
        }

        return $fallback->id;
    }
}

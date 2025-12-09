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
        ]);

        return response()->json([
            'horarios' => $availability->horariosDisponiveis($request->date, $this->resolveCompanyId($request)),
        ]);
    }

    private function resolveCompanyId(Request $request): int
    {
        if ($request->user()?->company_id) {
            return $request->user()->company_id;
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

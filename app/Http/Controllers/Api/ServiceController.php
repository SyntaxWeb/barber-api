<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Company;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        return ServiceResource::collection(Service::where('company_id', $companyId)->get());
    }

    public function store(ServiceRequest $request)
    {
        if (!$request->user()->company_id) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $service = Service::create($request->validated() + [
            'company_id' => $request->user()->company_id,
        ]);
        return new ServiceResource($service);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        if ($service->company_id !== $request->user()->company_id) {
            abort(403, 'Serviço não pertence à sua empresa.');
        }

        $service->update($request->validated());
        return new ServiceResource($service);
    }

    public function destroy(Request $request, Service $service)
    {
        if ($service->company_id !== $request->user()->company_id) {
            abort(403, 'Serviço não pertence à sua empresa.');
        }

        $service->delete();
        return response()->noContent();
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Company;
use App\Models\Service;
use Illuminate\Http\Request;
use App\Services\ActivityLogger;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        return ServiceResource::collection(
            Service::where('company_id', $companyId)->where('ativo', true)->get()
        );
    }

    public function store(ServiceRequest $request)
    {
        $user = $request->user('sanctum');

        if (!$user?->company_id) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $service = Service::create($request->validated() + [
            'company_id' => $user->company_id,
        ]);
        ActivityLogger::record($user, 'service.created', [
            'service_id' => $service->id,
            'nome' => $service->nome,
        ], $request);
        return new ServiceResource($service);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $user = $request->user('sanctum');

        if ($service->company_id !== $user?->company_id) {
            abort(403, 'Serviço não pertence à sua empresa.');
        }

        $service->update($request->validated());
        ActivityLogger::record($user, 'service.updated', [
            'service_id' => $service->id,
            'nome' => $service->nome,
        ], $request);
        return new ServiceResource($service);
    }

    public function destroy(Request $request, Service $service)
    {
        $user = $request->user('sanctum');

        if ($service->company_id !== $user?->company_id) {
            abort(403, 'Serviço não pertence à sua empresa.');
        }

        if ($service->ativo) {
            $service->update(['ativo' => false]);
        }
        ActivityLogger::record($user, 'service.inactivated', [
            'service_id' => $service->id,
            'nome' => $service->nome,
            'ativo' => false,
        ], $request);
        return response()->noContent();
    }

    private function resolveCompanyId(Request $request): int
    {
        $user = $request->user('sanctum');

        if ($user?->company_id) {
            return $user->company_id;
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
}

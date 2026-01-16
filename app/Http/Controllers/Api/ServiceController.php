<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Company;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    public function store(Request $request)
    {
        $user = $request->user('sanctum');

        if (!$user?->company_id) {
            abort(403, 'Usuário não associado a uma empresa.');
        }

        $companyId = $user->company_id;
        $validator = Validator::make($request->all(), [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'nome')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('ativo', true)),
            ],
            'preco' => 'required|numeric|min:0',
            'duracao_minutos' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $service = Service::create($validated + [
            'company_id' => $user->company_id,
        ]);
        ActivityLogger::record($user, 'service.created', [
            'service_id' => $service->id,
            'nome' => $service->nome,
        ], $request);
        return new ServiceResource($service);
    }

    public function update(Request $request, Service $service)
    {
        $user = $request->user('sanctum');

        if (!$user?->company_id) {
            abort(403, 'Usuário precisa estar vinculado a uma empresa.');
        }

        if ($service->company_id !== $user?->company_id) {
            abort(403, 'Serviço não pertence à sua empresa.');
        }

        $companyId = $user->company_id;
        $validator = Validator::make($request->all(), [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'nome')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('ativo', true))
                    ->ignore($service->id),
            ],
            'preco' => 'required|numeric|min:0',
            'duracao_minutos' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $service->update($validated);
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

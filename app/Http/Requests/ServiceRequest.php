<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('sanctum')?->company_id;
    }

    public function rules(): array
    {
        $serviceId = $this->route('service');
        if ($serviceId instanceof \App\Models\Service) {
            $serviceId = $serviceId->getKey();
        }

        $companyId = $this->user('sanctum')?->company_id;

        if (!$companyId) {
            abort(403, 'Usuário precisa estar vinculado a uma empresa.');
        }

        return [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'nome')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($serviceId),
            ],
            'preco' => 'required|numeric|min:0',
            'duracao_minutos' => 'required|integer|min:1',
        ];
    }
}

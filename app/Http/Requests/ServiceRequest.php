<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $serviceId = $this->route('service');

        return [
            'nome' => 'required|string|max:255|unique:services,nome,' . $serviceId,
            'preco' => 'required|numeric|min:0',
            'duracao_minutos' => 'required|integer|min:1',
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'cliente' => $this->cliente,
            'telefone' => $this->telefone,
            'data' => $this->data?->toDateString(),
            'horario' => $this->horario,
            'servico' => $this->service->nome ?? null,
            'service_id' => $this->service_id,
            'preco' => (float) $this->preco,
            'status' => $this->status,
            'observacoes' => $this->observacoes,
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company?->id,
                    'nome' => $this->company?->nome,
                    'slug' => $this->company?->slug,
                ];
            }),
        ];
    }
}

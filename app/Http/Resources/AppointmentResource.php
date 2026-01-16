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
            'feedback' => $this->whenLoaded('feedback', function () {
                return [
                    'service_rating' => $this->feedback?->service_rating,
                    'professional_rating' => $this->feedback?->professional_rating,
                    'scheduling_rating' => $this->feedback?->scheduling_rating,
                    'comment' => $this->feedback?->comment,
                    'allow_public_testimonial' => $this->feedback?->allow_public_testimonial,
                    'submitted_at' => $this->feedback?->submitted_at?->toIso8601String(),
                    'average_rating' => $this->feedback
                        ? round(
                            (($this->feedback->service_rating ?? 0)
                                + ($this->feedback->professional_rating ?? 0)
                                + ($this->feedback->scheduling_rating ?? 0)) / 3,
                            2
                        )
                        : null,
                ];
            }),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicFeedbackAppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'servico' => $this->service?->nome,
            'data' => $this->data?->toDateString(),
            'horario' => $this->horario,
            'company' => $this->whenLoaded('company', function () {
                return [
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

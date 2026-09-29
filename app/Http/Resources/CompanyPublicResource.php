<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyPublicResource extends JsonResource
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
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'slug' => $this->slug,
            'agendamento_url' => $this->agendamento_url,
            'icon_url' => $this->icon_url,
            'gallery_photos' => $this->gallery_photos,
            'address' => implode(', ', array_filter([
                $this->address_line,
                $this->neighborhood,
                $this->city,
                $this->state,
            ])) ?: null,
            'address_line' => $this->address_line,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'dashboard_theme' => $this->dashboard_theme,
            'client_theme' => $this->client_theme,
        ];
    }
}

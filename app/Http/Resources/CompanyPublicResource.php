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
            'dashboard_theme' => $this->dashboard_theme,
            'client_theme' => $this->client_theme,
        ];
    }
}

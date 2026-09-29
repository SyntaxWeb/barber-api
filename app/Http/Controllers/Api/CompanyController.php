<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyPublicResource;
use App\Models\AppointmentFeedback;
use App\Models\Company;
use App\Models\CompanyPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user('sanctum')?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $this->ensureQrCode($company);

        return response()->json($company);
    }

    public function update(Request $request)
    {
        $user = $request->user('sanctum');

        $request->merge([
            'notify_via_email'     => filter_var($request->notify_via_email, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'notify_via_telegram'  => filter_var($request->notify_via_telegram, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'notify_via_whatsapp'  => filter_var($request->notify_via_whatsapp, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'discovery_enabled'    => filter_var($request->discovery_enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);
        
        $company = $user->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $hexRule = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/i'];

        $validator = Validator::make($request->all(), [
            'nome'                 => 'required|string|max:255',
            'descricao'            => 'nullable|string|max:1000',
            'address_line'         => 'nullable|string|max:255',
            'neighborhood'         => 'nullable|string|max:120',
            'city'                 => 'nullable|string|max:120',
            'state'                => 'nullable|string|size:2',
            'postal_code'          => 'nullable|string|max:12',
            'latitude'             => 'nullable|numeric|between:-90,90',
            'longitude'            => 'nullable|numeric|between:-180,180',
            'discovery_enabled'    => 'nullable|boolean',
            'icone'                => 'nullable|image|max:2048',
            'notify_email'         => 'nullable|email',
            'notify_via_email'     => 'nullable|boolean',
            'notify_whatsapp'      => 'nullable|string|max:32',
            'notify_via_telegram'  => 'nullable|boolean',
            'notify_via_whatsapp'  => 'nullable|boolean',
            'gallery_photos'       => 'nullable|array|max:20',
            'gallery_photos.*'     => 'image|max:20480',
            'gallery_remove'       => 'nullable|array',
            'gallery_remove.*'     => 'string',
            'dashboard_theme'             => ['nullable', 'array'],
            'dashboard_theme.primary'    => $hexRule,
            'dashboard_theme.secondary'  => $hexRule,
            'dashboard_theme.background' => $hexRule,
            'dashboard_theme.surface'    => $hexRule,
            'dashboard_theme.text'       => $hexRule,
            'dashboard_theme.accent'     => $hexRule,
            'client_theme'               => ['nullable', 'array'],
            'client_theme.primary'       => $hexRule,
            'client_theme.secondary'     => $hexRule,
            'client_theme.background'    => $hexRule,
            'client_theme.surface'       => $hexRule,
            'client_theme.text'          => $hexRule,
            'client_theme.accent'        => $hexRule,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Erro de validação.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('icone')) {
            if ($company->icon_path) {
                Storage::disk('public')->delete($company->icon_path);
            }
            $data['icon_path'] = $request->file('icone')->store('company-icons', 'public');
        }

        $updateData = [
            'nome' => $data['nome'],
            'descricao' => $data['descricao'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => isset($data['state']) ? mb_strtoupper($data['state']) : null,
            'postal_code' => $data['postal_code'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'discovery_enabled' => $request->has('discovery_enabled')
                ? $request->boolean('discovery_enabled')
                : $company->discovery_enabled,
            'icon_path' => $data['icon_path'] ?? $company->icon_path,
            'notify_email' => $data['notify_email'] ?? $company->notify_email,
            'notify_via_email' => $request->boolean('notify_via_email'),
            'notify_whatsapp' => $data['notify_whatsapp'] ?? $company->notify_whatsapp,
            'notify_via_telegram' => $request->boolean('notify_via_telegram'),
            'notify_via_whatsapp' => $request->boolean('notify_via_whatsapp'),
        ];

        if (array_key_exists('dashboard_theme', $data)) {
            $updateData['dashboard_theme'] = $data['dashboard_theme'];
        }

        if (array_key_exists('client_theme', $data)) {
            $updateData['client_theme'] = $data['client_theme'];
        }

        $companyColumns = array_flip(Schema::getColumnListing($company->getTable()));
        $company->update(array_intersect_key($updateData, $companyColumns));

        $galleryRemove = collect($request->input('gallery_remove', []))
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values();

        if ($galleryRemove->isNotEmpty()) {
            $company->galleryPhotosRelation()
                ->whereIn('path', $galleryRemove->all())
                ->get()
                ->each(function (CompanyPhoto $photo) {
                    Storage::disk('public')->delete($photo->path);
                    $photo->delete();
                });
        }

        if ($request->hasFile('gallery_photos')) {
            $lastPosition = (int) $company->galleryPhotosRelation()->max('position');
            foreach ($request->file('gallery_photos') as $index => $image) {
                $path = $image->store('company-gallery', 'public');
                $company->galleryPhotosRelation()->create([
                    'path' => $path,
                    'position' => $lastPosition + $index + 1,
                ]);
            }
        }

        $company->refresh();
        $company->load('galleryPhotosRelation');
        $this->ensureQrCode($company);

        return response()->json($company);
    }

    public function publicShow(Company $company)
    {
        $company->load(['services' => fn ($query) => $query->where('ativo', true)->orderBy('nome')]);
        $this->ensureQrCode($company);
        return new CompanyPublicResource($company);
    }

    public function feedbackSummary(Company $company)
    {
        $feedbackQuery = AppointmentFeedback::whereHas('appointment', function ($query) use ($company) {
            $query->where('company_id', $company->id)->where('status', 'concluido');
        })->whereBetween('service_rating', [1, 5])
            ->whereBetween('professional_rating', [1, 5])
            ->whereBetween('scheduling_rating', [1, 5]);

        $count = (clone $feedbackQuery)->count();

        if ($count === 0) {
            return response()->json([
                'average' => null,
                'count' => 0,
                'recent' => [],
            ]);
        }

        $average = (clone $feedbackQuery)
            ->selectRaw('avg((service_rating + professional_rating + scheduling_rating) / 3) as avg_rating')
            ->value('avg_rating');

        $recent = (clone $feedbackQuery)
            ->where('allow_public_testimonial', true)
            ->with([
                'appointment' => function ($query) {
                    $query->select('id', 'cliente', 'user_id', 'company_id');
                },
                'appointment.user:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get()
            ->map(function (AppointmentFeedback $feedback) {
                $appointment = $feedback->appointment;

                return [
                    'id' => $feedback->id,
                    'client_name' => $this->publicClientName($appointment?->cliente ?? $appointment?->user?->name),
                    'rating' => round($feedback->average_rating, 2),
                    'comment' => $feedback->comment,
                    'created_at' => ($feedback->submitted_at ?? $feedback->created_at)?->toIso8601String(),
                ];
            })
            ->filter(fn ($item) => $item['comment'])
            ->values();

        return response()->json([
            'average' => $average !== null ? round((float) $average, 2) : null,
            'count' => $count,
            'recent' => $recent,
        ]);
    }

    private function publicClientName(?string $name): ?string
    {
        if (!$name) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return $parts[0] ?? null;
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    protected function ensureQrCode(?Company $company): void
    {
        if (!$company) {
            return;
        }

        if ($company->qr_code_svg) {
            return;
        }

        $company->qr_code_svg = null;
    }
}

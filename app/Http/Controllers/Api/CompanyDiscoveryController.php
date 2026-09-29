<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyDiscoveryController extends Controller
{
    public function index(Request $request, AvailabilityService $availability)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'rating_min' => ['nullable', 'numeric', 'between:1,5'],
            'available_on' => ['nullable', 'date', 'after_or_equal:today'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $validFeedback = fn ($query) => $query
            ->whereColumn('appointments.company_id', 'companies.id')
            ->where('appointments.status', 'concluido')
            ->whereBetween('appointment_feedback.service_rating', [1, 5])
            ->whereBetween('appointment_feedback.professional_rating', [1, 5])
            ->whereBetween('appointment_feedback.scheduling_rating', [1, 5]);

        $query = Company::query()
            ->select('companies.*')
            ->selectSub(
                $validFeedback(DB::table('appointment_feedback')
                    ->join('appointments', 'appointments.id', '=', 'appointment_feedback.appointment_id')
                    ->selectRaw('COUNT(*)')),
                'public_reviews_count'
            )
            ->selectSub(
                $validFeedback(DB::table('appointment_feedback')
                    ->join('appointments', 'appointments.id', '=', 'appointment_feedback.appointment_id')
                    ->selectRaw('AVG((service_rating + professional_rating + scheduling_rating) / 3.0)')),
                'public_rating'
            )
            ->where('discovery_enabled', true)
            ->where('subscription_status', 'ativo')
            ->whereHas('services', fn ($builder) => $builder->where('ativo', true))
            ->with(['services' => fn ($builder) => $builder->where('ativo', true)->orderBy('preco')]);

        $query->when($data['q'] ?? null, function ($builder, $term) {
            $builder->where(function ($nested) use ($term) {
                $nested->where('nome', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('neighborhood', 'like', "%{$term}%")
                    ->orWhereHas('services', fn ($services) => $services
                        ->where('ativo', true)
                        ->where('nome', 'like', "%{$term}%"));
            });
        });

        $query->when($data['location'] ?? null, function ($builder, $location) {
            $builder->where(function ($nested) use ($location) {
                $nested->where('city', 'like', "%{$location}%")
                    ->orWhere('neighborhood', 'like', "%{$location}%")
                    ->orWhere('address_line', 'like', "%{$location}%")
                    ->orWhere('postal_code', 'like', "%{$location}%");
            });
        });

        $query->when($data['service'] ?? null, fn ($builder, $service) => $builder
            ->whereHas('services', fn ($services) => $services
                ->where('ativo', true)
                ->where('nome', 'like', "%{$service}%")));

        $query->when(isset($data['price_min']), fn ($builder) => $builder
            ->whereHas('services', fn ($services) => $services->where('ativo', true)->where('preco', '>=', $data['price_min'])));
        $query->when(isset($data['price_max']), fn ($builder) => $builder
            ->whereHas('services', fn ($services) => $services->where('ativo', true)->where('preco', '<=', $data['price_max'])));

        $companies = $query->orderBy('nome')->limit(200)->get();
        $clientLatitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $clientLongitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $items = $companies->map(function (Company $company) use ($availability, $data, $clientLatitude, $clientLongitude) {
            $distance = $this->distanceKm($clientLatitude, $clientLongitude, $company->latitude, $company->longitude);
            $referenceService = $this->referenceService($company, $data['service'] ?? null);

            return [
                'id' => $company->id,
                'nome' => $company->nome,
                'slug' => $company->slug,
                'descricao' => $company->descricao,
                'icon_url' => $company->icon_url,
                'cover_url' => $company->gallery_photos[0] ?? $company->icon_url,
                'address' => $this->address($company),
                'city' => $company->city,
                'neighborhood' => $company->neighborhood,
                'latitude' => $company->latitude,
                'longitude' => $company->longitude,
                'distance_km' => $distance,
                'rating' => $company->public_rating !== null ? round((float) $company->public_rating, 2) : null,
                'reviews_count' => (int) $company->public_reviews_count,
                'services' => $company->services->take(5)->map(fn ($service) => [
                    'id' => $service->id,
                    'nome' => $service->nome,
                    'preco' => (float) $service->preco,
                    'duracao' => $service->duracao_minutos,
                ])->values(),
                'next_availability' => null,
                '_reference_service_id' => $referenceService?->id,
            ];
        })->when(isset($data['rating_min']), fn ($collection) => $collection
            ->filter(fn ($item) => $item['rating'] !== null && $item['rating'] >= (float) $data['rating_min']))
            ->when(isset($data['radius_km']) && $clientLatitude !== null && $clientLongitude !== null, fn ($collection) => $collection
                ->filter(fn ($item) => $item['distance_km'] !== null && $item['distance_km'] <= (float) $data['radius_km']))
            ->when($clientLatitude !== null && $clientLongitude !== null, fn ($collection) => $collection
                ->sortBy(fn ($item) => $item['distance_km'] ?? PHP_FLOAT_MAX))
            ->values();

        if (isset($data['available_on'])) {
            $items = $items->map(function ($item) use ($availability, $companies, $data) {
                $company = $companies->firstWhere('id', $item['id']);
                $item['next_availability'] = $company && $item['_reference_service_id']
                    ? $this->nextAvailability($company, $item['_reference_service_id'], $availability, $data['available_on'])
                    : null;
                return $item;
            })->filter(fn ($item) => $item['next_availability'] !== null)->values();
        }

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 12);
        $pageItems = $items->forPage($page, $perPage)->map(function ($item) use ($availability, $companies, $data) {
            if ($item['next_availability'] === null && !isset($data['available_on'])) {
                $company = $companies->firstWhere('id', $item['id']);
                $item['next_availability'] = $company && $item['_reference_service_id']
                    ? $this->nextAvailability($company, $item['_reference_service_id'], $availability, null)
                    : null;
            }
            unset($item['_reference_service_id']);
            return $item;
        })->values();

        $paginator = new LengthAwarePaginator(
            $pageItems,
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    public function mine(Request $request)
    {
        $user = $request->user('sanctum');
        $companies = Company::query()
            ->whereHas('appointments', fn ($appointments) => $appointments->where('user_id', $user->id))
            ->withCount(['appointments as client_appointments_count' => fn ($appointments) => $appointments->where('user_id', $user->id)])
            ->withMax(['appointments as last_appointment_date' => fn ($appointments) => $appointments->where('user_id', $user->id)], 'data')
            ->orderByDesc('last_appointment_date')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'nome' => $company->nome,
                'slug' => $company->slug,
                'icon_url' => $company->icon_url,
                'address' => $this->address($company),
                'appointments_count' => $company->client_appointments_count,
                'last_appointment_date' => $company->last_appointment_date,
            ]);

        return response()->json(['data' => $companies]);
    }

    private function referenceService(Company $company, ?string $serviceTerm)
    {
        $services = $company->services;
        if ($serviceTerm) {
            $match = $services->first(fn ($service) => str_contains(mb_strtolower($service->nome), mb_strtolower($serviceTerm)));
            if ($match) {
                return $match;
            }
        }

        return $services->sortBy('duracao_minutos')->first();
    }

    private function nextAvailability(Company $company, int $serviceId, AvailabilityService $availability, ?string $specificDate): ?array
    {
        $start = $specificDate ? Carbon::parse($specificDate) : today();
        $days = $specificDate ? 1 : 14;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            try {
                $slots = $availability->horariosDisponiveis($date, $company->id, [$serviceId]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return null;
            }
            if ($slots !== []) {
                return ['date' => $date, 'time' => $slots[0]];
            }
        }

        return null;
    }

    private function distanceKm(?float $fromLat, ?float $fromLng, $toLat, $toLng): ?float
    {
        if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
            return null;
        }

        $earthRadius = 6371;
        $latDelta = deg2rad((float) $toLat - $fromLat);
        $lngDelta = deg2rad((float) $toLng - $fromLng);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad((float) $toLat)) * sin($lngDelta / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    private function address(Company $company): ?string
    {
        $parts = array_filter([$company->address_line, $company->neighborhood, $company->city, $company->state]);
        return $parts ? implode(', ', $parts) : null;
    }
}

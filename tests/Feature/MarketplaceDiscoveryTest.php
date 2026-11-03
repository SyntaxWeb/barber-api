<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_identity_can_access_appointments_from_multiple_companies(): void
    {
        $client = User::factory()->create(['role' => 'client', 'company_id' => null]);
        [$firstCompany, $firstService] = $this->companyFixture('Studio Norte', 'studio-norte');
        [$secondCompany, $secondService] = $this->companyFixture('Studio Sul', 'studio-sul');

        $this->appointmentFixture($client, $firstCompany, $firstService, '10:00');
        $this->appointmentFixture($client, $secondCompany, $secondService, '11:00');

        Sanctum::actingAs($client, ['client']);

        $this->getJson('/api/clients/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.slug', 'studio-norte')
            ->assertJsonPath('data.1.company.slug', 'studio-sul');

        $this->getJson('/api/clients/companies')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('company_client', 2);
    }

    public function test_appointment_link_is_not_duplicated_for_same_client_and_company(): void
    {
        $client = User::factory()->create(['role' => 'client', 'company_id' => null]);
        [$company, $service] = $this->companyFixture('Barbearia Central', 'barbearia-central');

        $this->appointmentFixture($client, $company, $service, '10:00');
        $this->appointmentFixture($client, $company, $service, '11:00');

        $this->assertDatabaseCount('company_client', 1);
        $this->assertDatabaseHas('company_client', [
            'company_id' => $company->id,
            'user_id' => $client->id,
        ]);
    }

    public function test_provider_cannot_list_or_open_client_from_another_company(): void
    {
        [$firstCompany, $firstService] = $this->companyFixture('Empresa A', 'empresa-a');
        [$secondCompany, $secondService] = $this->companyFixture('Empresa B', 'empresa-b');
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $firstCompany->id]);
        $otherClient = User::factory()->create(['role' => 'client', 'company_id' => null]);
        $this->appointmentFixture($otherClient, $secondCompany, $secondService, '10:00');

        Sanctum::actingAs($provider, ['provider']);

        $this->getJson('/api/clients')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/clients/{$otherClient->id}/history")->assertForbidden();
    }

    public function test_discovery_filters_companies_and_only_counts_valid_completed_feedback(): void
    {
        [$company, $service] = $this->companyFixture('Corte & Arte', 'corte-arte', [
            'city' => 'Curitiba',
            'neighborhood' => 'Centro',
            'latitude' => -25.4284,
            'longitude' => -49.2733,
        ]);
        $client = User::factory()->create(['role' => 'client', 'company_id' => null]);
        $completed = $this->appointmentFixture($client, $company, $service, '10:00', 'concluido');
        $completed->feedback()->create([
            'service_rating' => 5,
            'professional_rating' => 4,
            'scheduling_rating' => 5,
            'comment' => 'Ótimo atendimento',
            'allow_public_testimonial' => true,
            'submitted_at' => now(),
        ]);
        $pending = $this->appointmentFixture($client, $company, $service, '11:00');
        $pending->feedback()->create([
            'service_rating' => 1,
            'professional_rating' => 1,
            'scheduling_rating' => 1,
            'submitted_at' => now(),
        ]);

        $this->getJson('/api/discover/companies?location=Centro&service=Corte&rating_min=4')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.slug', 'corte-arte')
            ->assertJsonPath('data.0.reviews_count', 1)
            ->assertJsonPath('data.0.rating', 4.67);

        $this->getJson('/api/discover/companies?location=Outra%20cidade')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_client_cannot_submit_feedback_twice_for_same_appointment(): void
    {
        [$company, $service] = $this->companyFixture('Studio Feedback', 'studio-feedback');
        $client = User::factory()->create(['role' => 'client', 'company_id' => null]);
        $appointment = $this->appointmentFixture($client, $company, $service, '10:00', 'concluido');
        Sanctum::actingAs($client, ['client']);
        $payload = [
            'service_rating' => 5,
            'professional_rating' => 5,
            'scheduling_rating' => 5,
            'comment' => 'Excelente',
            'allow_public_testimonial' => true,
        ];

        $this->postJson("/api/clients/appointments/{$appointment->id}/feedback", $payload)->assertOk();
        $this->postJson("/api/clients/appointments/{$appointment->id}/feedback", $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Feedback já enviado para este atendimento.');
        $this->assertDatabaseCount('appointment_feedback', 1);
    }

    public function test_discovery_combines_city_and_neighborhood(): void
    {
        $this->companyFixture('Centro Curitiba', 'centro-curitiba', ['city' => 'Curitiba', 'neighborhood' => 'Centro']);
        $this->companyFixture('Centro Londrina', 'centro-londrina', ['city' => 'Londrina', 'neighborhood' => 'Centro']);
        $this->getJson('/api/discover/companies?location=Centro%2C%20Curitiba')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.slug', 'centro-curitiba');
    }

    public function test_service_and_price_must_match_the_same_service(): void
    {
        [$company] = $this->companyFixture('Servicos', 'servicos');
        Service::create(['company_id' => $company->id, 'nome' => 'Barba', 'preco' => 100, 'duracao_minutos' => 30, 'ativo' => true]);
        $this->getJson('/api/discover/companies?service=Barba&price_max=60')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/discover/companies?service=Barba&price_max=120')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data.0.services')
            ->assertJsonPath('data.0.services.0.nome', 'Barba');
    }

    public function test_radius_is_applied_before_the_old_two_hundred_company_limit(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $this->companyFixture(sprintf('A distant %03d', $i), "distant-$i", ['latitude' => 0, 'longitude' => 0]);
        }
        $this->companyFixture('Z nearby', 'nearby', ['latitude' => -25.4284, 'longitude' => -49.2733]);
        $this->companyFixture('No coordinates', 'no-coordinates');
        $this->getJson('/api/discover/companies?latitude=-25.4284&longitude=-49.2733&radius_km=5')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.slug', 'nearby')
            ->assertJsonPath('data.0.distance_km', 0);
    }

    public function test_discovery_paginates_all_results_without_truncation(): void
    {
        for ($i = 0; $i < 25; $i++) $this->companyFixture(sprintf('Company %02d', $i), "company-$i");
        $this->getJson('/api/discover/companies?page=3&per_page=12')
            ->assertOk()->assertJsonPath('total', 25)->assertJsonPath('last_page', 3)->assertJsonCount(1, 'data');
    }

    public function test_clients_can_be_found_beyond_the_first_fifty_and_search_is_normalized(): void
    {
        [$company] = $this->companyFixture('Clientes', 'clientes');
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $company->id]);
        $target = User::factory()->create(['name' => 'João Antigo', 'role' => 'client', 'company_id' => $company->id, 'telefone' => '+55 (41) 99999-1234']);
        $target->clientCompanies()->attach($company->id);
        for ($i = 0; $i < 55; $i++) {
            $client = User::factory()->create(['role' => 'client', 'company_id' => $company->id]);
            $client->clientCompanies()->attach($company->id);
        }
        $other = User::factory()->create(['name' => 'João Outra Empresa', 'role' => 'client']);
        Sanctum::actingAs($provider, ['provider']);
        $this->getJson('/api/clients?page=1&per_page=20')->assertOk()
            ->assertJsonPath('meta.total', 56)->assertJsonPath('meta.last_page', 3)->assertJsonCount(20, 'data');
        $this->getJson('/api/clients?search=joao&page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/clients?search=41999991234&page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/clients?search=joao')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_duplicate_phone_is_rejected_only_within_the_same_company(): void
    {
        [$company] = $this->companyFixture('Clientes', 'clientes');
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $company->id]);
        $client = User::factory()->create(['name' => 'Cliente Existente', 'role' => 'client', 'company_id' => $company->id, 'telefone' => '+55 (41) 99999-1234']);
        $client->clientCompanies()->attach($company->id);
        Sanctum::actingAs($provider, ['provider']);
        $this->postJson('/api/clients', ['nome' => 'Duplicado', 'telefone' => '41999991234'])->assertStatus(409);
        $this->postJson('/api/clients', ['nome' => 'Telefone inválido', 'telefone' => '123'])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['name' => 'Duplicado']);
        $this->postJson('/api/clients', ['nome' => 'Outra pessoa', 'telefone' => '41999991234', 'allow_duplicate_phone' => true])->assertSuccessful();
        [$otherCompany] = $this->companyFixture('Outra empresa', 'outra-empresa');
        $otherClient = User::factory()->create(['role' => 'client', 'company_id' => $otherCompany->id, 'telefone' => '41988881234']);
        $otherClient->clientCompanies()->attach($otherCompany->id);
        $this->postJson('/api/clients', ['nome' => 'Cliente novo', 'telefone' => '41988881234'])->assertSuccessful();
    }

    public function test_provider_schedule_links_selected_client_and_rejects_other_company_client(): void
    {
        [$company, $service] = $this->companyFixture('Agenda clientes', 'agenda-clientes');
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $company->id]);
        $client = User::factory()->create(['role' => 'client', 'telefone' => '41999991234']);
        $client->clientCompanies()->attach($company->id);
        $other = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($provider, ['provider']);
        $payload = ['client_id' => $client->id, 'cliente' => $client->name, 'telefone' => $client->telefone,
            'data' => now()->addDay()->toDateString(), 'horario' => '10:37', 'service_id' => $service->id];
        $this->postJson('/api/appointments', $payload)->assertSuccessful();
        $this->assertDatabaseHas('appointments', ['company_id' => $company->id, 'user_id' => $client->id, 'horario' => '10:37']);
        $payload['client_id'] = $other->id;
        $payload['horario'] = '11:37';
        $this->postJson('/api/appointments', $payload)->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['user_id' => $other->id]);
    }

    private function companyFixture(string $name, string $slug, array $attributes = []): array
    {
        $company = Company::create(array_merge([
            'nome' => $name,
            'slug' => $slug,
            'subscription_status' => 'ativo',
            'discovery_enabled' => true,
        ], $attributes));
        $service = Service::create([
            'company_id' => $company->id,
            'nome' => "Corte {$slug}",
            'preco' => 50,
            'duracao_minutos' => 30,
            'ativo' => true,
        ]);
        Setting::create([
            'company_id' => $company->id,
            'horario_inicio' => '08:00',
            'horario_fim' => '18:00',
            'intervalo_minutos' => 30,
        ]);

        return [$company, $service];
    }

    private function appointmentFixture(
        User $client,
        Company $company,
        Service $service,
        string $time,
        string $status = 'confirmado'
    ): Appointment {
        $appointment = Appointment::create([
            'company_id' => $company->id,
            'user_id' => $client->id,
            'service_id' => $service->id,
            'cliente' => $client->name,
            'telefone' => '11999999999',
            'data' => now()->addDays(2)->toDateString(),
            'horario' => $time,
            'preco' => $service->preco,
            'status' => $status,
        ]);
        $appointment->services()->sync([$service->id]);

        return $appointment;
    }
}

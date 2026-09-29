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

<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyReportAndRetroactiveAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_register_a_past_appointment(): void
    {
        [$company, $provider, $service] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);

        $response = $this->postJson('/api/appointments', [
            'cliente' => 'Cliente esquecido',
            'telefone' => '11999999999',
            'data' => now()->subDay()->toDateString(),
            'horario' => '08:00',
            'service_id' => $service->id,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('appointments', [
            'company_id' => $company->id,
            'cliente' => 'Cliente esquecido',
            'data' => now()->subDay()->startOfDay()->toDateTimeString(),
            'horario' => '08:00',
        ]);
    }

    public function test_client_cannot_register_a_past_appointment(): void
    {
        [$company, , $service] = $this->fixture();
        $client = User::factory()->create(['role' => 'client', 'company_id' => null, 'telefone' => '11888888888']);
        Sanctum::actingAs($client, ['client']);

        $this->postJson('/api/appointments', [
            'data' => now()->subDay()->toDateString(),
            'horario' => '08:00',
            'service_id' => $service->id,
            'company_slug' => $company->slug,
        ])->assertStatus(422)->assertJsonValidationErrors('data');
    }

    public function test_company_report_uses_closed_sales_from_current_month(): void
    {
        [$company, $provider, $service] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);

        $current = Sale::create([
            'company_id' => $company->id,
            'user_id' => $provider->id,
            'status' => 'closed',
            'services_total' => 80,
            'products_total' => 20,
            'discount' => 10,
            'total' => 90,
            'closed_at' => now(),
        ]);
        $current->items()->create(['service_id' => $service->id, 'type' => 'service', 'description' => 'Corte', 'quantity' => 1, 'unit_price' => 80, 'total' => 80]);

        $old = Sale::create([
            'company_id' => $company->id,
            'user_id' => $provider->id,
            'status' => 'closed',
            'services_total' => 500,
            'total' => 500,
            'closed_at' => now()->subMonth()->endOfMonth(),
        ]);
        $old->items()->create(['service_id' => $service->id, 'type' => 'service', 'description' => 'Corte', 'quantity' => 1, 'unit_price' => 500, 'total' => 500]);

        $response = $this->getJson('/api/company/report')->assertOk();
        $response->assertJsonPath('summary.revenue_month', 90)
            ->assertJsonPath('summary.services_revenue_month', 80)
            ->assertJsonPath('summary.products_revenue_month', 20)
            ->assertJsonPath('summary.closed_sales_month', 1)
            ->assertJsonPath('services.0.revenue', 80);
    }

    private function fixture(): array
    {
        $company = Company::create(['nome' => 'Barbearia Relatorio', 'slug' => uniqid('relatorio-'), 'subscription_status' => 'ativo']);
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $company->id, 'telefone' => '11777777777']);
        $service = Service::create(['company_id' => $company->id, 'nome' => 'Corte', 'preco' => 80, 'duracao_minutos' => 30, 'ativo' => true]);

        return [$company, $provider, $service];
    }
}

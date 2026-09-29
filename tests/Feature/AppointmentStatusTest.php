<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_complete_appointment_before_closing_cash_register(): void
    {
        [$appointment, $user] = $this->appointmentFixture();
        Sanctum::actingAs($user, ['provider']);

        $response = $this->postJson("/api/appointments/{$appointment->id}/status", [
            'status' => 'concluido',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Feche o caixa do atendimento antes de concluir o agendamento.');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'confirmado',
        ]);
    }

    public function test_can_complete_appointment_when_cash_register_is_closed(): void
    {
        Queue::fake();

        [$appointment, $user] = $this->appointmentFixture();
        Sale::create([
            'company_id' => $appointment->company_id,
            'appointment_id' => $appointment->id,
            'user_id' => $user->id,
            'customer_name' => $appointment->cliente,
            'customer_phone' => $appointment->telefone,
            'status' => 'closed',
            'services_total' => 50,
            'total' => 50,
            'payment_method' => 'dinheiro',
            'closed_at' => now(),
        ]);
        Sanctum::actingAs($user, ['provider']);

        $response = $this->postJson("/api/appointments/{$appointment->id}/status", [
            'status' => 'concluido',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'concluido');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'concluido',
        ]);
    }

    private function appointmentFixture(): array
    {
        $company = Company::create([
            'nome' => 'Barbearia Teste',
            'slug' => uniqid('barbearia-'),
            'subscription_status' => 'ativo',
        ]);

        $user = User::factory()->create([
            'role' => 'provider',
            'company_id' => $company->id,
        ]);

        $service = Service::create([
            'company_id' => $company->id,
            'nome' => 'Corte',
            'preco' => 50,
            'duracao_minutos' => 30,
            'ativo' => true,
        ]);

        $appointment = Appointment::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'cliente' => 'Cliente Teste',
            'telefone' => '11999999999',
            'data' => now()->toDateString(),
            'horario' => '10:00',
            'preco' => 50,
            'status' => 'confirmado',
        ]);

        $appointment->services()->sync([$service->id]);

        return [$appointment, $user];
    }
}

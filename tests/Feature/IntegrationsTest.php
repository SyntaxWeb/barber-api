<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_available_integrations_without_credentials(): void
    {
        [$company, $user] = $this->providerUser();

        Integration::create([
            'company_id' => $company->id,
            'provider' => 'mercado_pago',
            'type' => 'payment',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => [
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
            ],
            'settings' => ['online_payments_enabled' => true],
            'metadata' => ['account_email' => 'conta@example.com'],
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user, ['provider']);

        $response = $this->getJson('/api/settings/integrations');

        $response->assertOk()
            ->assertJsonPath('data.0.provider', 'mercado_pago')
            ->assertJsonPath('data.0.name', 'Mercado Pago')
            ->assertJsonPath('data.0.status', 'connected')
            ->assertJsonMissing(['access_token' => 'secret-access-token'])
            ->assertJsonMissing(['refresh_token' => 'secret-refresh-token']);
    }

    public function test_disconnect_marks_integration_inactive_and_preserves_record(): void
    {
        [$company, $user] = $this->providerUser();
        $integration = Integration::create([
            'company_id' => $company->id,
            'provider' => 'mercado_pago',
            'type' => 'payment',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'secret-access-token'],
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user, ['provider']);

        $this->deleteJson('/api/settings/integrations/mercado-pago')->assertNoContent();

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'status' => Integration::STATUS_DISCONNECTED,
        ]);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [$company] = $this->providerUser();

        $integration = Integration::create([
            'company_id' => $company->id,
            'provider' => 'mercado_pago',
            'type' => 'payment',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'secret-access-token'],
        ]);

        $raw = Integration::query()->whereKey($integration->id)->value('credentials');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-access-token', $raw);
        $this->assertSame('secret-access-token', $integration->fresh()->credentials['access_token']);
    }

    private function providerUser(): array
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

        return [$company, $user];
    }
}

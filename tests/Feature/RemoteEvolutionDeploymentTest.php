<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteEvolutionDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.provider' => 'evolution',
            'services.evolution.base_url' => 'https://evolution.example.test',
            'services.evolution.api_key' => 'private-api-key',
            'services.evolution.webhook_secret' => 'private-webhook-secret',
            'services.evolution.webhook_base_url' => 'https://api.example.test',
        ]);
        Http::preventStrayRequests();
    }

    private function company(): Company
    {
        return Company::create(['nome' => 'Remote', 'slug' => 'remote', 'whatsapp_session_id' => 'remote-instance']);
    }

    public function test_diagnosis_only_reads_state_and_webhook_and_never_sends(): void
    {
        $company = $this->company();
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/webhook/find/*' => Http::response(['enabled' => true, 'url' => 'https://api.example.test/api/webhooks/whatsapp/evolution']),
        ]);
        $this->artisan('whatsapp:diagnose', ['--company' => $company->id])
            ->expectsOutput('Evolution acessível e autenticada.')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_old_internal_webhook_causes_diagnostic_failure(): void
    {
        $company = $this->company();
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/webhook/find/*' => Http::response(['enabled' => true, 'url' => 'http://app:8000/api/webhooks/whatsapp/evolution']),
        ]);
        $this->artisan('whatsapp:diagnose', ['--company' => $company->id])
            ->expectsOutput('Webhook ausente ou com endereço antigo. Execute novamente com --sync-webhook.')->assertFailed();
    }

    public function test_sync_updates_public_webhook_without_restarting_or_pairing(): void
    {
        $company = $this->company();
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/webhook/set/*' => Http::response(['enabled' => true]),
            '*/webhook/find/*' => Http::response(['webhook' => ['enabled' => true, 'url' => 'https://api.example.test/api/webhooks/whatsapp/evolution']]),
        ]);
        $this->artisan('whatsapp:diagnose', ['--company' => $company->id, '--sync-webhook' => true])->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/webhook/set/')
            && $request['webhook']['url'] === 'https://api.example.test/api/webhooks/whatsapp/evolution'
            && $request['webhook']['headers']['X-Syntax-Webhook-Token'] === hash_hmac('sha256', 'remote-instance', 'private-webhook-secret'));
    }

    public function test_bad_credentials_do_not_leak_upstream_response(): void
    {
        $company = $this->company();
        Http::fake(['*' => Http::response(['message' => 'private-api-key'], 401)]);
        $this->artisan('whatsapp:diagnose', ['--company' => $company->id])
            ->expectsOutput('Consulta de estado recusada: HTTP 401. Confira URL, chave e proxy HTTPS.')->assertFailed();
    }

    public function test_plain_http_and_api_suffix_are_rejected_before_network_access(): void
    {
        config(['services.evolution.base_url' => 'http://evolution:8080']);
        $this->artisan('whatsapp:diagnose', ['--company' => 2])->assertFailed();
        config(['services.evolution.base_url' => 'https://evolution.example.test/api']);
        $this->artisan('whatsapp:diagnose', ['--company' => 2])->assertFailed();
        Http::assertNothingSent();
    }
}

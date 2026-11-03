<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentAlertJob;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\User;
use App\Notifications\NewAppointmentNotification;
use App\Services\Whatsapp\EvolutionProvider;
use App\Services\Whatsapp\WhatsappDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvolutionWhatsappTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.provider' => 'evolution',
            'services.evolution.base_url' => 'https://evolution.test',
            'services.evolution.api_key' => 'test-administrative-key',
            'services.evolution.webhook_secret' => 'test-webhook-secret',
            'services.evolution.webhook_base_url' => 'https://syntax.test',
        ]);
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_status_before_connect_does_not_create_an_instance(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake(['*/instance/connectionState/*' => Http::response([], 404)]);
        $this->getJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('status.state', 'not_created')->assertJsonPath('qr_code', null);
        Http::assertSentCount(1);
    }

    public function test_connect_creates_company_instance_and_returns_qr_without_credentials(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::response([], 404),
            '*/instance/create' => Http::response(['hash' => 'must-not-leak', 'qrcode' => ['base64' => 'test-png']], 201),
        ]);
        $response = $this->postJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('provider', 'evolution')
            ->assertJsonPath('status.state', 'connecting')->assertJsonPath('qr_code', 'data:image/png;base64,test-png');
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->assertStringNotContainsString('test-administrative-key', $response->getContent());
        Http::assertSent(fn ($request) => $request->url() === 'https://evolution.test/instance/create'
            && $request->hasHeader('apikey', 'test-administrative-key')
            && $request['instanceName'] === $company->whatsapp_session_id
            && $request['integration'] === 'WHATSAPP-BAILEYS'
            && $request['webhook']['url'] === 'https://syntax.test/api/webhooks/whatsapp/evolution'
            && $request['webhook']['headers']['X-Syntax-Webhook-Token'] === app(EvolutionProvider::class)->webhookToken($company->whatsapp_session_id));
    }

    public function test_reconnecting_existing_instance_does_not_create_it_again(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']]),
            '*/webhook/set/*' => Http::response(['webhook' => ['enabled' => true]]),
            '*/instance/connect/*' => Http::response(['base64' => 'new-qr']),
        ]);
        $this->postJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('qr_code', 'data:image/png;base64,new-qr');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/instance/create'));
    }

    public function test_pairing_refresh_gets_a_new_qr_and_connected_status_clears_it(): void
    {
        [$company, $provider] = $this->fixture();
        Cache::put('whatsapp:pairing:'.$company->id, true, now()->addMinutes(3));
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::sequence()->push(['instance' => ['state' => 'connecting']])->push(['instance' => ['state' => 'open']]),
            '*/instance/connect/*' => Http::response(['base64' => 'qr-refresh']),
        ]);
        $this->getJson('/api/company/whatsapp/session?refresh_qr=1')->assertOk()->assertJsonPath('qr_code', 'data:image/png;base64,qr-refresh');
        $this->getJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('status.connected', true)->assertJsonPath('qr_code', null);
        $this->assertSame('connected', $company->fresh()->whatsapp_status);
    }

    public function test_logout_is_idempotent_and_does_not_reconnect(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::sequence()->push(['instance' => ['state' => 'open']])->push(['instance' => ['state' => 'close']]),
            '*/instance/logout/*' => Http::response(['status' => 'SUCCESS']),
        ]);
        $this->deleteJson('/api/company/whatsapp/session')->assertOk();
        $this->deleteJson('/api/company/whatsapp/session')->assertOk();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/instance/connect/'));
        $this->assertNull($company->fresh()->whatsapp_phone);
    }

    public function test_configuration_missing_has_a_clean_status_without_http_calls(): void
    {
        [, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        config(['services.evolution.api_key' => null]);
        $this->getJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('configured', false);
        Http::assertNothingSent();
    }

    public function test_test_message_uses_saved_recipient_and_normalizes_brazilian_phone(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        $this->fakeConnected();
        $this->postJson('/api/company/whatsapp/test', ['recipient' => 'malicious-override'])->assertOk()->assertJsonPath('status', 'accepted');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '5541999991234' && str_contains($request['text'], $company->nome)
            && !isset($request['textMessage']));
        $this->assertDatabaseHas('notification_logs', ['company_id' => $company->id, 'status' => 'accepted', 'channel' => 'whatsapp']);
    }

    public function test_provider_can_save_alert_recipient_before_sending_a_test(): void
    {
        [$company, $provider] = $this->fixture();
        $company->update(['notify_whatsapp' => null, 'notify_via_whatsapp' => false]);
        Sanctum::actingAs($provider, ['provider']);
        $this->postJson('/api/company/whatsapp/test')->assertUnprocessable();
        Http::assertNothingSent();

        $this->postJson('/api/company', ['nome' => $company->nome, 'notify_whatsapp' => '(41) 99999-1234', 'notify_via_whatsapp' => true])
            ->assertOk()->assertJsonPath('notify_whatsapp', '5541999991234')->assertJsonPath('notify_via_whatsapp', true);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'notify_whatsapp' => '5541999991234', 'notify_via_whatsapp' => true]);
        $provider->unsetRelation('company');
        $this->fakeConnected();
        $this->postJson('/api/company/whatsapp/test')->assertOk()->assertJsonPath('status', 'accepted');
    }

    public function test_new_appointment_uses_existing_notification_flow_and_whatsapp(): void
    {
        [$company, $provider, $service] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Notification::fake();
        $this->fakeConnected();
        $this->postJson('/api/appointments', ['cliente' => 'Cliente Teste', 'telefone' => '41911112222',
            'data' => now()->addDay()->toDateString(), 'horario' => '10:37', 'service_id' => $service->id])->assertSuccessful();
        Notification::assertSentTo($provider, NewAppointmentNotification::class);
        $log = NotificationLog::where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('accepted', $log->status);
        $this->assertSame('created', $log->meta['action']);
        $this->assertStringContainsString('Cliente Teste', $log->message);
        $this->assertStringContainsString('10:37', $log->message);
        $this->assertStringContainsString('Corte', $log->message);
        $this->assertNotEmpty($log->meta['provider_message_id']);
    }

    public function test_whatsapp_outage_does_not_prevent_booking_and_is_logged(): void
    {
        [, $provider, $service] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Notification::fake();
        Http::fake(['*' => Http::response(['api_key' => 'never-echo-this-secret'], 503)]);
        $this->postJson('/api/appointments', ['cliente' => 'Cliente', 'telefone' => '41911112222',
            'data' => now()->addDay()->toDateString(), 'horario' => '10:37', 'service_id' => $service->id])->assertSuccessful();
        $this->assertDatabaseHas('appointments', ['cliente' => 'Cliente']);
        $log = NotificationLog::where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('never-echo-this-secret', $log->error);
    }

    public function test_disabled_alerts_do_not_send_whatsapp(): void
    {
        [$company, $provider, $service] = $this->fixture();
        $company->update(['notify_via_whatsapp' => false]);
        $appointment = Appointment::create(['company_id' => $company->id, 'user_id' => $provider->id, 'service_id' => $service->id,
            'cliente' => 'Cliente', 'telefone' => '41911112222', 'data' => now()->addDay(), 'horario' => '10:37', 'preco' => 50, 'status' => 'confirmado']);
        (new SendAppointmentAlertJob($appointment->id))->handle();
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_invalid_secret_and_updates_only_the_own_company(): void
    {
        [$company] = $this->fixture();
        [$other] = $this->fixture('Outra');
        $payload = ['instance' => $company->whatsapp_session_id, 'event' => 'connection.update', 'data' => ['state' => 'open']];
        $this->postJson('/api/webhooks/whatsapp/evolution', $payload)->assertUnauthorized();
        $this->withHeader('X-Syntax-Webhook-Token', app(EvolutionProvider::class)->webhookToken($other->whatsapp_session_id))
            ->postJson('/api/webhooks/whatsapp/evolution', $payload)->assertUnauthorized();
        $this->webhook($company, $payload)->assertOk();
        $this->assertSame('connected', $company->fresh()->whatsapp_status);
        $this->assertNotSame('connected', $other->fresh()->whatsapp_status);
    }

    public function test_delivery_webhooks_are_idempotent_and_do_not_regress_read_status(): void
    {
        [$company] = $this->fixture();
        $this->fakeConnected();
        $log = app(WhatsappDeliveryService::class)->send($company, $company->notify_whatsapp, 'Mensagem', ['action' => 'created']);
        $payload = ['instance' => $company->whatsapp_session_id, 'event' => 'messages.update', 'data' => ['keyId' => 'test-message-id', 'status' => 'READ']];
        $this->webhook($company, $payload)->assertOk();
        $this->webhook($company, $payload)->assertOk();
        $payload['data']['status'] = 'SERVER_ACK';
        $this->webhook($company, $payload)->assertOk();
        $this->assertSame('read', $log->fresh()->status);
        $this->assertDatabaseCount('notification_logs', 1);
    }

    public function test_delivery_can_arrive_before_the_send_response_is_persisted(): void
    {
        [$company] = $this->fixture();
        Http::fake(function ($request) use ($company) {
            if (str_contains($request->url(), '/connectionState/')) return Http::response(['instance' => ['state' => 'open']]);
            app(WhatsappDeliveryService::class)->updateStatus($company, 'early-id', 'DELIVERY_ACK');
            return Http::response(['key' => ['id' => 'early-id']], 201);
        });
        $log = app(WhatsappDeliveryService::class)->send($company, $company->notify_whatsapp, 'Mensagem');
        $this->assertSame('delivered', $log->status);
    }

    public function test_provider_cannot_read_other_company_delivery_history(): void
    {
        [$company, $provider] = $this->fixture();
        [$other] = $this->fixture('Outra');
        NotificationLog::create(['company_id' => $other->id, 'channel' => 'whatsapp', 'recipient' => 'hidden', 'status' => 'accepted']);
        Sanctum::actingAs($provider, ['provider']);
        $this->getJson('/api/company/whatsapp/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recipient_validation_and_international_number_normalization(): void
    {
        $this->assertSame('14155551234', \App\Services\Whatsapp\WhatsappPhone::normalize('+1 (415) 555-1234'));
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        $this->postJson('/api/company', ['nome' => $company->nome, 'notify_via_whatsapp' => true, 'notify_whatsapp' => '123'])
            ->assertStatus(422);
        $this->postJson('/api/company', ['nome' => $company->nome, 'notify_via_whatsapp' => true, 'notify_whatsapp' => '(41) 99999-1234'])
            ->assertSuccessful();
        $this->assertSame('5541999991234', $company->fresh()->notify_whatsapp);
    }

    public function test_unpaired_logout_error_is_successful_when_socket_is_actually_closed(): void
    {
        [, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::sequence()->push(['instance' => ['state' => 'connecting']])->push(['instance' => ['state' => 'close']]),
            '*/instance/logout/*' => Http::response(['message' => 'Connection closed'], 400),
        ]);
        $this->deleteJson('/api/company/whatsapp/session')->assertOk();
    }

    public function test_legacy_wppconnect_connection_keeps_the_qr_contract(): void
    {
        [, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        config(['services.whatsapp.provider' => 'wppconnect', 'services.whatsapp.base_url' => 'https://legacy.test',
            'services.whatsapp.user' => 'test-user', 'services.whatsapp.password' => 'test-password']);
        Http::fake([
            'https://legacy.test/api/login' => Http::response(['token' => 'legacy-token', 'expiresIn' => 3600]),
            '*start-session*' => Http::response(['started' => true]),
            '*check-connection-session*' => Http::response(['connected' => false, 'state' => 'disconnected']),
            '*qrcode*' => Http::response(['base64' => 'legacy-qr']),
        ]);
        $this->postJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('qr_code', 'data:image/png;base64,legacy-qr');
        $this->getJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('qr_code', 'data:image/png;base64,legacy-qr');
    }

    public function test_reused_cancelled_slot_notifies_the_new_services(): void
    {
        [$company, $provider, $oldService] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Notification::fake();
        $this->fakeConnected();
        $newService = Service::create(['company_id' => $company->id, 'nome' => 'Barba nova', 'preco' => 40, 'duracao_minutos' => 20, 'ativo' => true]);
        $appointment = Appointment::create(['company_id' => $company->id, 'user_id' => $provider->id, 'service_id' => $oldService->id,
            'cliente' => 'Antigo', 'telefone' => '41911112222', 'data' => now()->addDay(), 'horario' => '10:37', 'preco' => 50, 'status' => 'cancelado']);
        $appointment->services()->sync([$oldService->id]);
        $this->postJson('/api/appointments', ['cliente' => 'Novo cliente', 'telefone' => '41911112222',
            'data' => now()->addDay()->toDateString(), 'horario' => '10:37', 'service_id' => $newService->id])->assertSuccessful();
        $log = NotificationLog::where('channel', 'whatsapp')->firstOrFail();
        $this->assertStringContainsString('Barba nova', $log->message);
        $this->assertStringNotContainsString('Corte', $log->message);
    }

    public function test_qr_polling_without_an_explicit_pairing_never_reconnects(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        app(EvolutionProvider::class)->rememberQrCode($company->whatsapp_session_id, 'stale-qr');
        Http::fake(['*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']])]);
        $this->getJson('/api/company/whatsapp/session?refresh_qr=1')->assertOk()
            ->assertJsonPath('pairing', false)->assertJsonPath('qr_code', null);
        Http::assertSentCount(1);
    }

    public function test_late_qr_refresh_after_logout_does_not_reopen_the_connection(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Cache::put('whatsapp:pairing:'.$company->id, true, now()->addMinutes(3));
        Http::fake([
            '*/instance/connectionState/*' => Http::sequence()->push(['instance' => ['state' => 'open']])->push(['instance' => ['state' => 'close']]),
            '*/instance/logout/*' => Http::response(['status' => 'SUCCESS']),
        ]);
        $this->deleteJson('/api/company/whatsapp/session')->assertOk();
        $this->getJson('/api/company/whatsapp/session?refresh_qr=1')->assertOk()->assertJsonPath('pairing', false)->assertJsonPath('qr_code', null);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/instance/connect/'));
    }

    public function test_connected_instance_can_reconfigure_webhook_without_pairing_again(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/webhook/set/*' => Http::response(['webhook' => ['enabled' => true]]),
        ]);
        $this->postJson('/api/company/whatsapp/session')->assertOk()->assertJsonPath('status.connected', true)->assertJsonPath('pairing', false);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/webhook/set/')
            && $request['webhook']['headers']['X-Syntax-Webhook-Token'] === app(EvolutionProvider::class)->webhookToken($company->whatsapp_session_id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/instance/connect/') || str_contains($request->url(), '/instance/create'));
    }

    public function test_failed_start_revokes_the_pairing_permission(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson('/api/company/whatsapp/session')->assertStatus(422);
        $this->assertFalse(app(\App\Services\WhatsappService::class)->isPairing($company));
    }

    public function test_expired_pairing_stops_exposing_and_refreshing_qr(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Cache::put('whatsapp:pairing:'.$company->id, true, now()->addMinutes(3));
        $this->travel(4)->minutes();
        app(EvolutionProvider::class)->rememberQrCode($company->whatsapp_session_id, 'stale-qr');
        Http::fake(['*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']])]);
        $this->getJson('/api/company/whatsapp/session?refresh_qr=1')->assertOk()->assertJsonPath('pairing', false)->assertJsonPath('qr_code', null);
        Http::assertSentCount(1);
    }

    public function test_send_timeout_warns_about_uncertain_delivery_without_retrying(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        $attempts = 0;
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/message/sendText/*' => function () use (&$attempts) {
                $attempts++;
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            },
        ]);
        $response = $this->postJson('/api/company/whatsapp/test')->assertStatus(422);
        $this->assertStringContainsString('A mensagem pode ter sido enviada', $response->json('message'));
        $this->assertSame(1, $attempts);
        $this->assertSame('connected', $company->fresh()->whatsapp_status);
        $this->assertDatabaseCount('notification_logs', 1);
    }

    public function test_connection_failure_is_not_reported_as_uncertain_delivery(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Connection refused'),
        ]);
        $this->postJson('/api/company/whatsapp/test')->assertStatus(422)
            ->assertJsonPath('message', 'Não foi possível conectar à Evolution API. Verifique o serviço de WhatsApp no servidor.');
    }

    public function test_bad_request_preserves_provider_reason_without_exposing_credentials(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/message/sendText/*' => Http::response(['response' => ['message' => ['Connection closed test-administrative-key']]], 400),
        ]);
        $this->postJson('/api/company/whatsapp/test')->assertStatus(422)
            ->assertJsonPath('message', 'A Evolution recusou a solicitação: Connection closed [oculto]');
    }

    public function test_bad_request_for_unknown_number_has_actionable_message(): void
    {
        [$company, $provider] = $this->fixture();
        Sanctum::actingAs($provider, ['provider']);
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/message/sendText/*' => Http::response(['response' => ['message' => [['exists' => false, 'number' => '5541999991234']]]], 400),
        ]);
        $this->postJson('/api/company/whatsapp/test')->assertStatus(422)
            ->assertJsonPath('message', 'A Evolution não reconheceu o destinatário como um número com WhatsApp. Confira o DDI, DDD e número salvo.');
    }

    private function fakeConnected(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'test-message-id'], 'status' => 'PENDING'], 201),
        ]);
    }

    private function webhook(Company $company, array $payload)
    {
        return $this->withHeader('X-Syntax-Webhook-Token', app(EvolutionProvider::class)->webhookToken($company->whatsapp_session_id))
            ->postJson('/api/webhooks/whatsapp/evolution', $payload);
    }

    private function fixture(string $name = 'Empresa'): array
    {
        $company = Company::create(['nome' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'subscription_status' => 'ativo',
            'notify_whatsapp' => '(41) 99999-1234', 'notify_via_whatsapp' => true, 'whatsapp_phone' => '5541999990000']);
        $company->update(['whatsapp_session_id' => 'syntax-company-'.$company->id]);
        $provider = User::factory()->create(['role' => 'provider', 'company_id' => $company->id]);
        $service = Service::create(['company_id' => $company->id, 'nome' => 'Corte', 'preco' => 50, 'duracao_minutos' => 30, 'ativo' => true]);
        return [$company, $provider, $service];
    }
}

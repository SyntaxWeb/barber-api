<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Whatsapp\EvolutionProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class DiagnoseEvolution extends Command
{
    protected $signature = 'whatsapp:diagnose {--company= : ID da empresa} {--sync-webhook : Atualizar o webhook público da instância} {--allow-http : Permitir HTTP apenas no diagnóstico local}';
    protected $description = 'Verifica a Evolution sem enviar mensagens, desconectar ou gerar QR Code';

    public function handle(EvolutionProvider $evolution): int
    {
        if (config('services.whatsapp.provider') !== 'evolution' || !$evolution->configured()) {
            $this->error('Configure o provedor evolution, a URL, a chave e o segredo do webhook no .env.');
            return self::FAILURE;
        }
        $base = rtrim((string) config('services.evolution.base_url'), '/');
        $webhook = rtrim(config('services.evolution.webhook_base_url') ?: config('app.url'), '/');
        foreach ([$base, $webhook] as $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['https', 'http'], true)
                || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)
                || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)
                || (parse_url($url, PHP_URL_PATH) ?: '') !== ''
                || (!$this->option('allow-http') && $scheme !== 'https')) {
                $this->error('Use origens HTTPS sem /api, caminhos ou credenciais nas URLs. Para Docker local, use --allow-http.');
                return self::FAILURE;
            }
        }
        if (!$this->option('company') || !ctype_digit((string) $this->option('company'))) {
            $this->error('Informe --company=ID para verificar uma empresa existente.');
            return self::FAILURE;
        }
        $company = Company::find($this->option('company'));
        if (!$company || !$company->whatsapp_session_id) {
            $this->error('Empresa ou instância não encontrada. Inicie a conexão pelo painel.');
            return self::FAILURE;
        }
        try {
            $client = Http::acceptJson()->withHeaders(['apikey' => config('services.evolution.api_key')])->connectTimeout(3)->timeout(12);
            $response = $client->get($base.'/instance/connectionState/'.rawurlencode($company->whatsapp_session_id));
            if (!$response->successful()) {
                $this->error('Consulta de estado recusada: HTTP '.$response->status().'. Confira URL, chave e proxy HTTPS.');
                return self::FAILURE;
            }
            $state = $response->json('instance.state');
            $this->info('Evolution acessível e autenticada.');
            $this->line('Estado informado: '.(in_array($state, ['open', 'close', 'connecting'], true) ? $state : 'desconhecido'));
            if ($this->option('sync-webhook')) {
                $evolution->syncWebhook($company);
                $this->info('Webhook atualizado sem reiniciar ou desconectar a sessão.');
            }
            $response = $client->get($base.'/webhook/find/'.rawurlencode($company->whatsapp_session_id));
            $data = $response->json();
            $config = is_array($data) ? ($data['webhook'] ?? $data) : [];
            $expected = $webhook.'/api/webhooks/whatsapp/evolution';
            if (!$response->successful() || !is_array($config) || empty($config['enabled']) || ($config['url'] ?? null) !== $expected) {
                $this->error('Webhook ausente ou com endereço antigo. Execute novamente com --sync-webhook.');
                return self::FAILURE;
            }
            $this->info('Webhook habilitado com o endereço esperado.');
            $this->warn('Este diagnóstico não comprova entrega nem testa o caminho de volta. Valide um envio explícito pelo painel e confira o status Entregue.');
            return $state === 'open' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            // Never print upstream exception URLs, response bodies or credentials.
            $this->error('Não foi possível concluir o diagnóstico. Confira DNS, certificado HTTPS, firewall e chave da Evolution.');
            return self::FAILURE;
        }
    }
}

# Evolution API: conexão e avisos ao prestador

Para hospedar o Laravel na Hostinger compartilhada e manter a Evolution no homelab, consulte [HOSTINGER_HOMELAB.md](HOSTINGER_HOMELAB.md). Há um Compose separado, modelos de ambiente e diagnóstico sem envio de mensagens.

Implementação baseada na Evolution API **2.3.7**, modalidade **Baileys / QR Code**. O fluxo existente de notificações de novos agendamentos foi preservado. Este módulo não inclui chatbot de agendamento nem onboarding da Cloud API oficial.

## Ambiente local

```bash
python3 scripts/setup-evolution.py
docker compose --profile whatsapp up -d evolution
docker compose up -d --build --no-deps app
```

O script gera a chave da API, o segredo de webhook e a senha do banco dedicado em `.env`, sem exibi-los e sem substituir segredos já preenchidos. Não versione esse arquivo. O Redis, o PostgreSQL e as sessões da Evolution possuem volumes próprios. A Evolution fica acessível ao Laravel em `http://evolution:8080`; a porta de diagnóstico do host é `127.0.0.1:8085` e não precisa ser aberta para o navegador do prestador.

O frontend local usa `npm run build:local` antes de recriar o container do dashboard.

## Uso pelo prestador

1. Abrir **Configurações → Integrações → WhatsApp para alertas**.
2. Informar o número que receberá os avisos, com DDD; habilitar **Receber via WhatsApp** e **salvar as alterações**. Números internacionais devem começar com `+` ou `00` e o código do país.
3. Em **WhatsApp automático**, clicar em **Conectar WhatsApp**.
4. No aplicativo do celular, abrir **Aparelhos conectados → Conectar aparelho** e ler o QR Code.
5. A tela detecta a conexão automaticamente, sem substituir o formulário nem disparar consultas ao digitar o telefone. Depois de pareado, consulta o estado a cada 30 segundos enquanto a página está visível. A tela também informa se falta salvar o destinatário ou habilitar os alertas, usando as preferências efetivamente salvas.
6. Clicar em **Enviar mensagem de teste**. O destinatário é sempre o número salvo da empresa; a requisição não permite escolher outro destinatário.
7. Conferir **Últimos envios**. Um novo agendamento continua gerando a notificação no sino do painel e, quando habilitado, o alerta de WhatsApp no formato atual com cliente, telefone, serviços, data, horário, valor e empresa.

A falha de WhatsApp é registrada e não desfaz o agendamento. Os canais de e-mail e Telegram mantêm seu comportamento. `SendAppointmentAlertJob` continua sendo o disparador; `WhatsappDeliveryService` registra o envio e usa a fachada `WhatsappService`.

## Estados de envio

- `pending`: preparando a tentativa.
- `accepted`: Evolution retornou um identificador; ainda não comprova entrega.
- `sent`: confirmação do servidor do WhatsApp.
- `delivered`: confirmação de entrega ao destinatário.
- `read`: confirmação de leitura, quando disponibilizada pelo WhatsApp.
- `failed`: falha na tentativa ou informada pelo canal. Timeout não é motivo para repetir automaticamente uma mensagem sem conferir o histórico.

Eventos duplicados não criam logs novos. Atualizações atrasadas não rebaixam uma confirmação de leitura/entrega. Eventos que chegam antes da resposta de envio são guardados temporariamente e conciliados pelo identificador da mensagem. Não existe retentativa automática cega.

## Configuração do servidor

| Variável | Uso |
| --- | --- |
| `WHATSAPP_PROVIDER` | `evolution` (padrão), ou `wppconnect` para manter o transporte anterior. |
| `WHATSAPP_COUNTRY_CODE` | DDI aplicado a números locais sem prefixo internacional; padrão `55`. |
| `EVOLUTION_API_URL` | URL interna ou externa da Evolution, sem barra final. |
| `EVOLUTION_SEND_TIMEOUT` | Tempo máximo para confirmar um envio, em segundos; padrão 45. Consultas de estado continuam limitadas a 12 segundos. Timeout não comprova que a mensagem deixou de sair; confira o WhatsApp antes de repetir. |
| `EVOLUTION_API_KEY` | Chave administrativa usada somente pelo backend. |
| `EVOLUTION_WEBHOOK_SECRET` | Segredo separado para derivar o token de cada instância. |
| `EVOLUTION_WEBHOOK_BASE_URL` | Endereço do Laravel alcançável pela Evolution, sem `/api` final. Local: `http://app:8000`. |
| `EVOLUTION_DB_PASSWORD` | Senha do banco exclusivo da Evolution local. |

Em hospedagem externa, definir a URL HTTPS real da API Laravel em `EVOLUTION_WEBHOOK_BASE_URL`. Recriar o serviço ou limpar o cache de configuração Laravel após alterar variáveis. A conexão de cada empresa é criada ou reconfigurada pelo botão de conectar; o navegador nunca recebe as chaves.

A implementação não requer migração de banco: reutiliza os campos de WhatsApp de `companies` e `notification_logs`. O identificador da instância é persistido e continua estável após renomear a empresa. Não reutilize o mesmo identificador entre empresas.

## Rotas Laravel

- `GET /api/company/whatsapp/session`: estado e QR em cache. Não cria uma instância nem reconecta uma sessão desconectada.
- `GET /api/company/whatsapp/session?refresh_qr=1`: atualiza QR somente durante um pareamento iniciado explicitamente e ainda ativo (janela de três minutos). Desconectar revoga essa permissão antes de esperar consultas em andamento; uma consulta atrasada não reconecta a sessão. A resposta inclui `pairing` para encerrar o polling no navegador quando a janela termina.
- `POST /api/company/whatsapp/session`: cria ou reconecta a instância da empresa e retorna QR/status normalizados.
- `DELETE /api/company/whatsapp/session`: desconecta, remove o QR em cache e interrompe o polling de pareamento no painel.
- `POST /api/company/whatsapp/test`: teste explícito para o destinatário salvo, limitado a três solicitações por minuto.
- `GET /api/company/whatsapp/notifications`: últimos dez registros da própria empresa, sem credenciais nem payloads brutos.
- `POST /api/webhooks/whatsapp/evolution`: eventos autenticados por `X-Syntax-Webhook-Token`, derivado por HMAC do segredo e do identificador da instância.

As rotas do prestador mantêm autenticação, habilidade `provider` e assinatura ativa do grupo atual. Webhooks não usam o token do prestador: conferem seu segredo e encontram a empresa exclusivamente pelo identificador persistido. São processados apenas eventos de conexão, QR e atualização de mensagem; mensagens recebidas não geram agendamentos nesta entrega.

## Contratos verificados

Foi usado o código da versão fixada, porque exemplos genéricos da documentação podem representar contratos diferentes:

- [Release 2.3.7](https://github.com/evolution-foundation/evolution-api/releases/tag/2.3.7)
- [Criação, conexão e estado](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/controllers/instance.controller.ts)
- [Mensagem de texto: `number` e `text` no corpo](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/dto/sendMessage.dto.ts)
- [Webhook: headers e payload por instância](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/event/webhook/webhook.controller.ts)

Os testes automatizados usam `Http::fake()` e não enviam WhatsApp real. O teste da instalação local criou uma instância temporária, gerou QR de verdade, consultou o estado e removeu a instância. Recebimento real pelo prestador exige pareamento no celular e o teste explícito pela tela.

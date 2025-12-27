# Documentacao da API - SyntaxAtendimento (barber-api)

## 1. Visao geral
Esta API REST (Laravel + Sanctum) suporta o sistema SyntaxAtendimento, com rotas publicas e privadas para:
- Prestadores (provider) e administradores (admin).
- Clientes (client).
- Publico (consulta de dados e feedback via token).

Base URL: definida por `APP_URL` no backend e consumida pelo frontend via `VITE_API_URL`.

## 2. Autenticacao e autorizacao
Autenticacao via Laravel Sanctum (token bearer).

### Roles e abilities
- provider: token com ability `provider`.
- admin: token com abilities `admin` e `provider`.
- client: token com ability `client`.

### Middleware relevantes
- `auth:sanctum`: exige token valido.
- `abilities:client` e `ability:provider,admin`: controle de acesso por role.
- `subscription.active`: bloqueia prestador quando `subscription_status != ativo`.
- `cors`: liberacao de CORS para rotas especificas.

### Respostas comuns
- 401: token invalido ou ausente.
- 402: assinatura inativa (prestador).
- 403: acesso proibido (role errado ou recurso de outra empresa).
- 422: validacao de dados.

## 3. Entidades principais (modelo de dados)
### Company (Empresa)
Campos principais:
- nome, slug, descricao, agendamento_url, qr_code_svg
- notificacoes: notify_email, notify_telegram, notify_whatsapp, notify_via_* (boolean)
- temas: dashboard_theme, client_theme (cores hex)
- whatsapp: session_id, status, phone, api_token, etc
- assinatura: subscription_plan, subscription_status, subscription_price, subscription_renews_at

### User
- name, email, telefone, objetivo, avatar_path
- role: provider | admin | client
- company_id (nullable)
- observacoes (clientes cadastrados manualmente)

### Service (Servico)
- nome, preco, duracao_minutos, company_id

### Appointment (Agendamento)
- cliente, telefone, data, horario, service_id, preco, status, observacoes
- user_id (cliente ou prestador), company_id

### AppointmentFeedback (Feedback)
- service_rating, professional_rating, scheduling_rating (1..5)
- comment, allow_public_testimonial, submitted_at
- appointment_id (unico)

### Settings
- horario_inicio, horario_fim, intervalo_minutos
- weekly_schedule (por dia)

### BlockedDay
- data, company_id (unique por empresa)

### SubscriptionOrder
- company_id, plan_key, price, status, checkout_url, mp_preference_id, mp_payment_id

### ActivityLog
- action, details, ip_address, user_agent, user_id, company_id

### NotificationLog (envio de notificacoes)
- company_id, channel, recipient, message, status, meta, error

## 4. Regras de negocio (principais)
### 4.1 Agendamentos
- Status validos: confirmado | concluido | cancelado.
- Slot unico por empresa: `data + horario + company_id`.
- Horarios disponiveis consideram:
  - dias bloqueados,
  - horario padrao,
  - agenda semanal (por dia),
  - intervalo de almoco,
  - duracao do servico,
  - agendamentos ativos (nao cancelados).
- Criacao de agendamento:
  - exige data >= hoje.
  - valida horario com AvailabilityService.
  - preco sempre reflete o preco do servico.
  - se existir agendamento cancelado no mesmo slot, ele e reativado.
- Atualizacao de agendamento:
  - valida disponibilidade quando muda slot.
  - garante que o agendamento pertence a empresa.
- Conclusao de agendamento:
  - ao mudar status para concluido, dispara convite de feedback.

### 4.2 Clientes (portal)
- Cliente so pode alterar/cancelar agendamento se:
  - status = confirmado
  - faltam pelo menos 60 minutos para o horario.
- Feedback de cliente:
  - permitido apenas se status = concluido.
  - somente um feedback por agendamento.
  - expira 30 dias apos a data do atendimento.

### 4.3 Assinatura
- provider com assinatura inativa recebe HTTP 402 nas rotas protegidas.
- webhook do Mercado Pago muda status para `ativo` quando pagamento aprovado.

### 4.4 Notificacoes
- Disparo automatico para prestadores e clientes em:
  - novo agendamento
  - atualizacao
  - cancelamento
- Canais: email, Telegram, WhatsApp (se habilitados).

## 5. Endpoints

### 5.1 Publicos (sem autenticao)
GET `/api/services`
- Lista servicos da empresa.
- Query: `company=slug` (opcional).
- Resposta: `[{ id, nome, preco, duracao }]`.

GET `/api/availability`
- Query obrigatoria: `date=YYYY-MM-DD`.
- Query opcional: `company=slug`, `service_id`, `appointment_id`.
- Resposta: `{ horarios: ["09:00", "09:30", ...] }`.

GET `/api/companies/{slug}`
- Dados publicos da empresa (inclui temas, contatos, icone e galeria).

GET `/api/companies/{slug}/feedback-summary`
- Resumo de feedback (media, total e comentarios publicos).

GET `/api/feedback/form/{token}`
- Carrega dados do agendamento e feedback associado.

POST `/api/feedback/form/{token}`
- Envia feedback publico (sem login).
- Body:
  - `service_rating` (1..5)
  - `professional_rating` (1..5)
  - `scheduling_rating` (1..5)
  - `comment` (opcional)
  - `allow_public_testimonial` (opcional)

POST `/api/mercadopago/webhook`
- Recebe notificacoes do Mercado Pago e ativa assinatura quando aprovado.

### 5.2 Autenticacao (prestador/admin)
POST `/api/login`
- Body: `email`, `password`.
- Resposta: `{ token, user }` com `company`.

POST `/api/register`
- Body: `name`, `email`, `password`, `password_confirmation`, `telefone`, `objetivo`, `empresa`.
- Cria empresa e provider, plano padrao pendente.
- Resposta: `{ token, user }`.

POST `/api/logout`
- Requer token provider/admin.

GET `/api/me`
- Retorna usuario logado e empresa.

### 5.3 Autenticacao (cliente)
POST `/api/clients/register`
- Body: `name`, `email`, `telefone`, `password`, `password_confirmation`, `company_slug`.
- Resposta: `{ token, user }`.

POST `/api/clients/login`
- Body: `email`, `password`, `company_slug`.
- Resposta: `{ token, user }`.

POST `/api/clients/login/google`
- Body: `credential`, `company_slug`.
- Resposta: `{ token, user }`.

POST `/api/clients/logout`
- Requer token client.

GET `/api/clients/me`
- Requer token client.

### 5.4 Agendamentos (provider/admin)
GET `/api/appointments`
- Query: `date`, `from`, `to` (opcional).
- Retorna lista com service e feedback.

POST `/api/appointments`
- Requer token provider ou client.
- Body:
  - `cliente`, `telefone` (opcional se usuario tiver dados)
  - `data`, `horario`, `service_id`
  - `observacoes` (opcional)
  - `company_slug` (quando client agenda)
- Resposta: objeto de agendamento.

PUT `/api/appointments/{id}`
- Body igual ao POST.
- Valida disponibilidade e empresa.

POST `/api/appointments/{id}/status`
- Body: `status` em `confirmado|concluido|cancelado`.

DELETE `/api/appointments/{id}`
- Remove agendamento (empresa deve ser dona).

### 5.5 Agendamentos (cliente)
GET `/api/clients/appointments`
- Lista agendamentos do cliente.

PUT `/api/clients/appointments/{id}`
- Atualiza se status confirmado e horario valido.
- Body: `service_id`, `data`, `horario`, `observacoes`, `company_slug`.

POST `/api/clients/appointments/{id}/cancel`
- Cancela se faltam >= 60 min.

POST `/api/clients/appointments/{id}/feedback`
- Envia feedback do cliente (status concluido).

### 5.6 Servicos
GET `/api/services`
- Publico, com filtro por empresa.

POST `/api/services`
- Body: `nome`, `preco`, `duracao_minutos`.

PUT `/api/services/{id}`
- Body: `nome`, `preco`, `duracao_minutos`.

DELETE `/api/services/{id}`

### 5.7 Configuracoes da empresa
GET `/api/company`
- Dados completos da empresa (inclui tema e galeria).

POST `/api/company`
- FormData:
  - `nome`, `descricao`, `icone`
  - `notify_email`, `notify_whatsapp`
  - `notify_via_email`, `notify_via_telegram`, `notify_via_whatsapp`
  - `dashboard_theme[*]`, `client_theme[*]`
  - `gallery_photos[]`, `gallery_remove[]`

GET `/api/settings`
- Retorna horarios padrao, intervalo, dias bloqueados e agenda semanal.

PUT `/api/settings`
- Body:
  - `horario_inicio`, `horario_fim`, `intervalo_minutos`
  - `dias_bloqueados[]`
  - `weekly_schedule` (por dia)

### 5.8 Notificacoes
GET `/api/notifications`
- Lista ultimas 25 notificacoes.

POST `/api/notifications/read-all`
- Marca todas como lidas.

POST `/api/notifications/{id}/read`
- Marca uma notificacao como lida.

### 5.9 Perfil
POST `/api/profile`
- Atualiza perfil de provider.

POST `/api/clients/profile`
- Atualiza perfil de client.

### 5.10 Assinatura
GET `/api/subscription`
- Retorna plano atual, planos disponiveis e ultimo pedido.

POST `/api/subscription/checkout`
- Body: `plan` (mensal, trimestral, anual).
- Retorna `checkout_url`.

### 5.11 Admin (superadmin)
GET `/api/admin/providers`
- Filtros: `plan`, `status`.

GET `/api/admin/plans`
- Lista planos e status disponiveis.

POST `/api/admin/providers/{company}/subscription`
- Body: `plan`, `status`, `price`, `renews_at` (opcional).

GET `/api/admin/mercado-pago/subscriptions`
- Consulta assinaturas no Mercado Pago.

POST `/api/admin/mercado-pago/plans/sync`
- Sincroniza planos configurados.

GET `/api/admin/logs`
- Filtros: `company_id`, `user_id`, `action`, `per_page`.

GET `/api/admin/report` ou `/api/admin/system/report`
- Relatorio consolidado do sistema.

## 6. Formatos de resposta (recursos)
### AppointmentResource
```
{
  "id": 1,
  "cliente": "Joao",
  "telefone": "(11) 99999-0000",
  "data": "2025-12-06",
  "horario": "09:00",
  "servico": "Corte",
  "service_id": 2,
  "preco": 35.0,
  "status": "confirmado",
  "observacoes": "...",
  "company": { "id": 1, "nome": "Barbearia", "slug": "barbearia" },
  "feedback": {
    "service_rating": 5,
    "professional_rating": 5,
    "scheduling_rating": 5,
    "comment": "Otimo!",
    "allow_public_testimonial": true,
    "submitted_at": "2025-12-06T20:00:00Z",
    "average_rating": 5
  }
}
```

### ServiceResource
```
{ "id": 1, "nome": "Corte", "preco": 35.0, "duracao": 30 }
```

### ClientResource
```
{ "id": 10, "nome": "Maria", "email": "maria@email.com", "telefone": "...", "observacoes": "..."}
```

### NotificationResource
```
{ "id": "...", "data": { ... }, "read_at": null, "created_at": "..." }
```

## 7. Integracoes externas
### Telegram
- Gera link via `/api/company/telegram/link`.
- Verifica mensagens via `/api/company/telegram/link/verify`.
- Usa `TELEGRAM_BOT_TOKEN` e `TELEGRAM_BOT_USERNAME`.

### WhatsApp (WPPConnect)
- Status, QR Code e logout via `/api/company/whatsapp/session`.
- Requer `WPPCONNECT_URL`, `WPPCONNECT_USER`, `WPPCONNECT_PASSWORD` e `WPPCONNECT_SECRET`.

### Mercado Pago
- Checkout de assinatura via `SubscriptionController`.
- Webhook para confirmar pagamento.
- Admin pode listar e sincronizar planos.

### Google (login cliente)
- Verificacao do token via Google Token Info.
- Requer `GOOGLE_CLIENT_ID`.

### Email
- Envio de alertas e convites de feedback.
- Usa configuracoes padrao de email do Laravel.

## 8. Filas e jobs
Jobs em background (ShouldQueue):
- `SendAppointmentAlertJob`: notifica cliente/prestador por email/Telegram/WhatsApp.
- `SendFeedbackInvitationJob`: envia convite de feedback apos conclusao.

Configure fila e workers no ambiente para garantir disparo.

## 9. Logs e auditoria
Eventos registrados via `ActivityLogger`:
- service.created|updated|deleted
- appointment.created|reactivated|updated|status_updated|deleted
- client.appointment.updated|cancelled|feedback_submitted

Endpoint admin: `/api/admin/logs` com paginacao.

## 10. Variaveis de ambiente (principais)
- `APP_URL`, `FRONTEND_URL`
- `DB_*`
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`
- `MERCADO_PAGO_ACCESS_TOKEN`, `MERCADO_PAGO_BASE_URI`
- `WPPCONNECT_URL`, `WPPCONNECT_USER`, `WPPCONNECT_PASSWORD`, `WPPCONNECT_SECRET`, `WPPCONNECT_WEBHOOK`
- `GOOGLE_CLIENT_ID`
- Configuracoes de email (MAIL_*)

## 11. Colecoes e utilitarios
- Postman: `Barber API.postman_collection.json`


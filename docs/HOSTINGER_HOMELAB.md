# Hostinger compartilhada + Evolution no homelab

Este roteiro prepara uma implantação separada: frontend estático e API Laravel na Hostinger; Evolution 2.3.7, PostgreSQL e Redis na máquina do homelab. Os arquivos locais existentes continuam independentes. Nada neste roteiro exige expor o MySQL, PostgreSQL ou Redis na internet.

```text
Navegador -> HTTPS frontend/API na Hostinger
API Laravel -> HTTPS público da Evolution -> WhatsApp
Evolution -> HTTPS público da API Laravel (webhooks)
```

## 1. Endereços e requisitos

Escolha três endereços distintos, por exemplo:

- Frontend: `https://atendimento.seudominio.com.br`
- Laravel: `https://api-atendimento.seudominio.com.br`
- Evolution: `https://evolution.seudominio.com.br`

Hostinger: acesso SSH/Composer, PHP 8.3 tanto no site quanto no terminal, MySQL, HTTPS, cron e requisições HTTPS de saída. Confira `composer check-platform-reqs --no-dev` no servidor. O pacote Horizon foi retirado das dependências obrigatórias; este perfil usa `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=file` e `SESSION_DRIVER=file`, sem worker persistente nem Redis na Hostinger. Confira também o limite de duração das requisições PHP do plano: a configuração sugerida limita cada envio à Evolution a 25 segundos, além da consulta de estado e dos outros canais de aviso. Não configure timeouts maiores que o permitido pelo host.

Homelab: VM Linux com Docker e Compose v2. Uma estimativa inicial para poucas sessões é 2 vCPU, 2–4 GB RAM e 20–30 GB disco. Docker em LXC depende da configuração de nesting e permissões; uma VM evita esse acoplamento. Mantenha os volumes persistentes e faça backup.

## 2. Gerar configurações sem imprimir segredos

Na raiz de `barber-api`:

```bash
python3 scripts/prepare-remote-evolution.py \
  --evolution-url https://evolution.seudominio.com.br \
  --api-url https://api-atendimento.seudominio.com.br
```

São gerados, com permissão 0600, em `deploy/homelab/secrets/`:

- `homelab.env`: configura o Compose da Evolution.
- `hostinger-evolution.env`: trecho a incorporar ao `.env` do Laravel; NÃO substitui o `.env` completo.

O script recusa sobrescrever arquivos existentes. Esses arquivos são ignorados pelo Git e pelo build Docker. A chave da Evolution é compartilhada somente entre o backend Laravel e a Evolution; o segredo de webhook permanece no Laravel, que deriva um token por instância. O navegador não recebe essas chaves.

Para migrar uma instalação existente, preserve as chaves necessárias à restauração dos volumes. O gerador é voltado a uma instalação nova; ele não importa nem altera os segredos da instalação atual.

## 3. Instalar a Evolution no homelab

Copie `deploy/homelab/compose.yaml`, `Caddyfile` e `secrets/homelab.env` para uma pasta exclusiva na VM. Na pasta de destino, salve o arquivo de configuração como `.env` e restrinja suas permissões:

```bash
chmod 600 .env
docker compose config --quiet
```

Escolha APENAS uma das opções de acesso abaixo. O Compose publica a porta de diagnóstico 8085 apenas em `127.0.0.1`. PostgreSQL e Redis não publicam portas.

### Opção A — Cloudflare Tunnel, inclusive com CGNAT

Crie um túnel gerenciado na Cloudflare e coloque seu token em `CLOUDFLARE_TUNNEL_TOKEN` no `.env`. Configure o hostname público `evolution.seudominio.com.br` para o serviço **HTTP `http://evolution:8080`**, acessível na rede interna do Compose.

```bash
docker compose --profile tunnel up -d
docker compose logs --tail 40 tunnel
```

O túnel abre conexões de saída, sem redirecionar portas no roteador. O domínio do hostname precisa estar configurado na Cloudflare. Não ative desafio de navegador ou login interativo nesse hostname: a chamada é servidor a servidor e usa o header `apikey`. Um serviço Cloudflare Access exigiria suporte adicional a seus headers; não está incluído neste perfil. Escolha uma versão/digest testada de `CLOUDFLARED_IMAGE` antes de padronizar a implantação; o exemplo usa o canal `latest` do conector, enquanto a Evolution permanece fixada em 2.3.7.

### Opção B — HTTPS direto com Caddy

Exige IP público acessível, DNS apontando para ele e encaminhamento das portas TCP 80/443 para a VM. Não funciona só com redirecionamento de portas se o provedor usa CGNAT. Configure `EVOLUTION_DOMAIN` no `.env`.

```bash
docker compose --profile https up -d
docker compose logs --tail 40 https
```

O Caddy termina o HTTPS e encaminha internamente para a Evolution. Não encaminhe a porta 8085 pelo roteador. Se já houver um proxy reverso, pode iniciar apenas `docker compose up -d` e usar seu proxy; configure a origem de acordo com a rede dele.

## 4. Preparar o Laravel na Hostinger

1. Crie o subdomínio da API, habilite HTTPS e prepare banco/usuário MySQL próprios.
2. Envie a API para uma pasta fora da raiz pública. O document root deve servir APENAS `barber-api/public`. Nunca publique `.env`, `vendor`, `storage/logs` ou o projeto inteiro. Se o painel não permitir alterar o document root, mantenha a aplicação fora de `public_html`, copie somente o conteúdo de `public/` para lá e ajuste os caminhos de `vendor/autoload.php` e `bootstrap/app.php` no `index.php`. Nesse layout, configure também o link público de `storage` para o destino real.
3. Use `deploy/hostinger/.env.example` como referência para o `.env` completo. Preencha APP_URL, FRONTEND_URL, CORS, banco, SMTP, Google e Mercado Pago conforme seus serviços atuais. Incorpore as variáveis de `hostinger-evolution.env` sem duplicar chaves.
4. Migração com dados existentes: preserve a APP_KEY original, os segredos das integrações, o banco e os arquivos de `storage/app`. Não rode `key:generate` sobre uma aplicação migrada. Para instalação vazia, gere uma chave nova uma única vez.
5. Na pasta real da aplicação, com o PHP correto:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan view:cache
```

Faça backup do banco antes de `migrate`. Em migração, restaure o dump antes e aplique somente as migrações pendentes. `storage` e `bootstrap/cache` precisam ser graváveis pelo PHP; não use permissões 777. Ajuste o SMTP antes de validar os avisos. Não envie o `vendor` antigo que ainda tenha o Horizon; instale a partir do lock atualizado.

Configure no hPanel um cron a cada minuto, substituindo pelo caminho e executável PHP reais:

```text
/usr/bin/php /home/USUARIO/apps/barber-api/artisan schedule:run
```

Confirme que o cron usa o mesmo PHP 8.3 do site. Não execute `horizon` ou `schedule:work` na hospedagem compartilhada.

## 5. Publicar o frontend

Na raiz de `barber-dashboard`, em uma máquina de build com Node 22 e npm:

```bash
npm ci
VITE_API_URL=https://api-atendimento.seudominio.com.br npm run build -- --outDir dist-hostinger
```

Se usa login Google, configure também `VITE_GOOGLE_CLIENT_ID` no ambiente de build e autorize o novo domínio no provedor. Copie o CONTEÚDO de `dist-hostinger/`, incluindo `.htaccess`, para a raiz pública do domínio do frontend. Esse build separado evita substituir o `dist` usado localmente. O `.htaccess` inclui fallback de rotas da SPA e mantém assets ausentes como 404; configurações de cache/CSP adicionais do hPanel devem permitir sua API e o login Google.

Nunca inclua EVOLUTION_API_KEY ou EVOLUTION_WEBHOOK_SECRET em variáveis VITE_. Nenhum endereço do homelab precisa entrar no frontend.

## 6. Conectar e validar os dois lados

Pelo SSH da Hostinger, na aplicação Laravel, use o ID da empresa:

```bash
php artisan whatsapp:diagnose --company=2
```

O comando consulta estado e configuração do webhook, exige URLs HTTPS e não envia mensagens, reinicia ou gera QR. Em uma instalação nova, crie a sessão pelo painel e leia o QR no celular antes. Um retorno `open` não garante que o socket esteja operacional para enviar; o histórico precisa confirmar a entrega.

Para substituir um webhook antigo como `http://app:8000` pelo endereço público, preservando o pareamento:

```bash
php artisan whatsapp:diagnose --company=2 --sync-webhook
```

Esse comando atualiza o webhook e seu token por instância. O destino final será `https://api-atendimento.seudominio.com.br/api/webhooks/whatsapp/evolution`. Não inclua `/api` em EVOLUTION_WEBHOOK_BASE_URL. Se alterar a URL ou o segredo, limpe/recrie o cache de configuração e sincronize TODAS as instâncias existentes. O diagnóstico não valida a recepção de um evento real: faça um único teste explícito pelo painel e confira `Entregue` no histórico para validar envio e webhook juntos.

## 7. Migrar a sessão atual e fazer a troca

- Preserve a versão 2.3.7 durante a migração; atualizações devem ser feitas separadamente.
- Faça backup lógico do PostgreSQL e dos volumes de instâncias/Redis da Evolution. O Compose novo usa nomes de volumes diferentes; mapear os nomes ou restaurar os dados é necessário. Não use `docker compose down -v`.
- Pare a Evolution antiga antes de iniciar a mesma sessão na nova máquina. Evite duas instâncias com as mesmas credenciais de WhatsApp.
- Preserve a chave administrativa ao restaurar os dados; confira o identificador de sessão salvo na empresa.
- Depois da restauração, configure o Laravel com a nova URL HTTPS, sincronize o webhook público e valide. Se a sessão não puder ser restaurada, será necessário novo QR Code pelo celular.
- Faça a troca do DNS da aplicação apenas após os testes e mantenha um backup consistente para retorno.

## 8. Limites operacionais

Se o homelab ficar sem energia/internet, os agendamentos continuam no servidor da Hostinger, mas os avisos de WhatsApp falham. O sistema atual registra a falha e não possui fila de recuperação garantida nem reenvio automático. O modo síncrono também pode deixar a resposta de um agendamento esperando pelos canais externos. Uma fila confiável com deduplicação é uma evolução separada; não habilite retentativas cegas após timeout.

Faça backup separado do MySQL e de `storage/app` na Hostinger, e do PostgreSQL/volumes no homelab. Teste a restauração. Não exponha chaves em capturas de tela, respostas HTTP ou repositórios.

Referências: [Laravel na Hostinger](https://support.hostinger.com/en/articles/1583301-which-laravel-versions-are-supported-at-hostinger), [Cloudflare Tunnel](https://developers.cloudflare.com/tunnel/concepts/), [Evolution 2.3.7](https://github.com/evolution-foundation/evolution-api/tree/2.3.7).

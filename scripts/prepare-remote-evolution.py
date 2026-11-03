#!/usr/bin/env python3
"""Generate paired homelab/Hostinger configuration; never overwrite secrets."""
import argparse
import os
import re
from pathlib import Path
import secrets
from urllib.parse import urlsplit


def https_origin(value):
    try:
        url = urlsplit(value)
        port = url.port
    except ValueError as error:
        raise argparse.ArgumentTypeError('Endereço HTTPS inválido.') from error
    if (url.scheme != 'https' or not url.hostname or not re.fullmatch(r'[A-Za-z0-9.-]+', url.hostname) or url.username or url.password
            or url.path not in ('', '/') or url.query or url.fragment
            or port == 0 or any(char.isspace() for char in value)):
        raise argparse.ArgumentTypeError('Use uma origem HTTPS, sem caminho, usuário ou parâmetros.')
    return value.rstrip('/')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--evolution-url', required=True, type=https_origin)
    parser.add_argument('--api-url', required=True, type=https_origin)
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1] / 'deploy/homelab/secrets')
    args = parser.parse_args()
    if args.evolution_url == args.api_url:
        parser.error('A Evolution e a API Laravel precisam de endereços distintos.')
    args.output.mkdir(parents=True, exist_ok=True, mode=0o700)
    targets = [args.output / 'homelab.env', args.output / 'hostinger-evolution.env']
    if any(path.exists() for path in targets):
        parser.error('Já há arquivos de configuração nesse destino. Nenhum segredo foi substituído.')
    key, password, webhook = secrets.token_hex(32), secrets.token_hex(24), secrets.token_hex(32)
    domain = urlsplit(args.evolution_url).netloc
    contents = [
        f'COMPOSE_PROJECT_NAME=syntax-evolution\nEVOLUTION_PUBLIC_URL={args.evolution_url}\n'
        f'EVOLUTION_DOMAIN={domain}\nEVOLUTION_API_KEY={key}\nEVOLUTION_DB_PASSWORD={password}\n'
        'EVOLUTION_BIND_IP=127.0.0.1\nEVOLUTION_LOCAL_PORT=8085\nCLOUDFLARE_TUNNEL_TOKEN=\n',
        '# Merge into the Laravel .env; this is NOT a complete application .env.\n'
        f'WHATSAPP_PROVIDER=evolution\nEVOLUTION_API_URL={args.evolution_url}\nEVOLUTION_API_KEY={key}\n'
        f'EVOLUTION_WEBHOOK_SECRET={webhook}\nEVOLUTION_WEBHOOK_BASE_URL={args.api_url}\nEVOLUTION_SEND_TIMEOUT=25\n',
    ]
    for path, content in zip(targets, contents):
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as file:
            file.write(content)
    print('Configurações geradas com permissões restritas. Nenhum segredo foi exibido.')
    print('Arquivos: homelab.env e hostinger-evolution.env no diretório escolhido.')
    print('Para migrar sessões existentes, preserve a chave anterior e restaure os volumes antes de iniciar.')


if __name__ == '__main__':
    main()

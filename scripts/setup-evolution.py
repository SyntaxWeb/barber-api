#!/usr/bin/env python3
"""Prepare local Evolution secrets without printing them or replacing existing secrets."""
from pathlib import Path
import secrets

root = Path(__file__).resolve().parents[1]
path = root / '.env'
text = path.read_text() if path.exists() else ''
values = {}
for line in text.splitlines():
    if '=' in line and not line.lstrip().startswith('#'):
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip().strip('"\'')
updates = {
    'WHATSAPP_PROVIDER': 'evolution',
    'EVOLUTION_API_URL': values.get('EVOLUTION_API_URL') or 'http://evolution:8080',
    'EVOLUTION_WEBHOOK_BASE_URL': values.get('EVOLUTION_WEBHOOK_BASE_URL') or 'http://app:8000',
    'EVOLUTION_API_KEY': values.get('EVOLUTION_API_KEY') or secrets.token_hex(32),
    'EVOLUTION_WEBHOOK_SECRET': values.get('EVOLUTION_WEBHOOK_SECRET') or secrets.token_hex(32),
    'EVOLUTION_DB_PASSWORD': values.get('EVOLUTION_DB_PASSWORD') or secrets.token_hex(24),
}
lines = []
for line in text.splitlines():
    key = line.split('=', 1)[0].strip() if '=' in line and not line.lstrip().startswith('#') else ''
    if key not in updates: lines.append(line)
lines += ['', '# Local Evolution integration (generated secrets; do not commit)']
lines += [f'{key}={value}' for key, value in updates.items()]
path.write_text('\n'.join(lines) + '\n')
path.chmod(0o600)
print('Configuração local da Evolution preparada em .env. Nenhuma chave foi exibida.')

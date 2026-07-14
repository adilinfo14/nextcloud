#!/bin/bash
# Export de la documentation technique ConfIA (docs/endpoints/schema DB) et push
# vers le connecteur Recherche+ sur le home lab (Nextcloud), via le meme lien
# Tailscale/cle SSH deja utilise par confia-backup.sh (voir /etc/cron.d/confia-backup).
# Variables LAB_HOST/LAB_USER/LAB_PORT/SSH_KEY depuis /etc/cron.d/confia-doc-export.
# Log : /var/log/confia-doc-export.log

set -euo pipefail

LOG_FILE="/var/log/confia-doc-export.log"
TS=$(date +%Y%m%d_%H%M%S)

LAB_HOST="${LAB_HOST:-}"
LAB_USER="${LAB_USER:-}"
LAB_PORT="${LAB_PORT:-22}"
LAB_DIR="${LAB_DIR:-/home/adil/confia-doc-export}"
SSH_KEY="${SSH_KEY:-/home/ubuntu/.ssh/id_ed25519_backup}"

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG_FILE"; }
fail() { log "ERREUR: $*"; exit 1; }

log "=== Export doc technique ConfIA demarre ($TS) ==="

# Invocation en mode module (-m core.confia_doc_export) et PAS par chemin direct :
# "core/email.py" (module maison, envoi de mails) masque sinon le module standard
# "email" des lors que /app/core est ajoute en tete de sys.path (comportement
# automatique de Python quand un script est lance par CHEMIN) - casse l'import de
# chromadb (qui depend de email.message en interne). Le mode module ajoute le
# repertoire de travail (/app), pas celui du script, evitant ce conflit.
docker exec confia sh -c 'cd /app && python3 -m core.confia_doc_export' 2>&1 | tee -a "$LOG_FILE" || fail "export Python a echoue"

docker cp confia:/app/data/rag_export/app_doc_export.json /tmp/app_doc_export.json || fail "docker cp a echoue"
log "Export local : $(du -h /tmp/app_doc_export.json | cut -f1)"

if [ -z "$LAB_HOST" ] || [ -z "$LAB_USER" ]; then
    log "info: LAB_HOST/LAB_USER non configures, push skippe"
    exit 0
fi

SSH_OPT="-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -p $LAB_PORT -i $SSH_KEY"
SCP_OPT="-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -P $LAB_PORT -i $SSH_KEY"
ssh $SSH_OPT "$LAB_USER@$LAB_HOST" "mkdir -p '$LAB_DIR'" 2>>"$LOG_FILE" || log "warn: mkdir cote lab a echoue"

if scp $SCP_OPT /tmp/app_doc_export.json "$LAB_USER@$LAB_HOST:$LAB_DIR/app_doc_export.json" 2>>"$LOG_FILE"; then
    log "Push vers home lab OK"
else
    fail "push vers home lab a echoue"
fi

rm -f /tmp/app_doc_export.json

log "=== Export termine ($TS) ==="

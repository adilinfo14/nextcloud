#!/bin/bash
# Reindexe le connecteur Recherche+ "confia_doc" (doc technique ConfIA/Lesensia)
# depuis l'export JSON pousse par le VPS ConfIA (cron 03:45, voir
# confia-doc-export-push.sh cote VPS) - copie l'export dans le conteneur puis lance
# l'indexation ES (chunking + embeddings). Log : /home/adil/search-hub-confia-doc.log
set -euo pipefail

SRC=/home/adil/confia-doc-export/app_doc_export.json
LOG=$HOME/search-hub-confia-doc.log
TS=$(date '+%F %T')

if [ ! -f "$SRC" ]; then
    echo "[$TS] export introuvable ($SRC) - le push VPS a-t-il eu lieu ? skip" >> "$LOG"
    exit 0
fi

docker cp "$SRC" nextcloud:/var/www/html/custom_apps/search_hub/confia_doc_export.json
docker exec nextcloud php /var/www/html/custom_apps/search_hub/confia_doc_index.php >> "$LOG" 2>&1
echo "[$TS] confia_doc reindexe" >> "$LOG"

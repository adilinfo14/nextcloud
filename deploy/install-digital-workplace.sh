#!/bin/bash
#
# Déploiement Nextcloud "digital workplace" clé en main — 100% Docker.
# Installe, configure et brande une instance Nextcloud prête pour un client :
# base applicative digital workplace + charte graphique sobre + sécurité de base.
#
# Usage : ./install-digital-workplace.sh
# (à lancer sur le serveur cible, avec Docker + Docker Compose v2 installés)

set -euo pipefail

# ----------------------------------------------------------------------------
# Helpers
# ----------------------------------------------------------------------------

C_BLUE='\033[1;34m'; C_GREEN='\033[1;32m'; C_YELLOW='\033[1;33m'; C_RED='\033[1;31m'; C_RESET='\033[0m'

info()  { echo -e "${C_BLUE}[*]${C_RESET} $1"; }
ok()    { echo -e "${C_GREEN}[OK]${C_RESET} $1"; }
warn()  { echo -e "${C_YELLOW}[!]${C_RESET} $1"; }
err()   { echo -e "${C_RED}[X]${C_RESET} $1"; }

ask() {
    # ask "question" "valeur_par_defaut" -> écrit la réponse dans REPLY_VAL
    local question="$1" default="${2:-}"
    local answer
    if [ -n "$default" ]; then
        read -r -p "$question [$default] : " answer
        REPLY_VAL="${answer:-$default}"
    else
        read -r -p "$question : " answer
        REPLY_VAL="$answer"
    fi
}

ask_secret() {
    # ask_secret "question" -> écrit la réponse dans REPLY_VAL (saisie masquée)
    local question="$1" answer
    read -r -s -p "$question : " answer
    echo
    REPLY_VAL="$answer"
}

gen_secret() {
    openssl rand -hex 24
}

occ() {
    docker exec -u www-data nextcloud php occ "$@"
}

install_app() {
    local appid="$1"
    if docker exec -u www-data nextcloud php occ app:list 2>/dev/null | grep -q "  - $appid:"; then
        occ app:enable "$appid" >/dev/null 2>&1 && ok "App '$appid' déjà présente, activée" || warn "App '$appid' : activation échouée (ignorée)"
        return
    fi
    if occ app:install "$appid" >/dev/null 2>&1; then
        ok "App '$appid' installée et activée"
    else
        warn "App '$appid' : installation échouée (ignorée, non bloquant)"
    fi
}

# ----------------------------------------------------------------------------
# Bannière
# ----------------------------------------------------------------------------

echo -e "${C_BLUE}"
echo "========================================================================"
echo "  Déploiement Nextcloud - Digital Workplace clé en main"
echo "========================================================================"
echo -e "${C_RESET}"

# ----------------------------------------------------------------------------
# Questions
# ----------------------------------------------------------------------------

ask "Nom de l'organisation (affiché dans l'interface)" "Mon Organisation"
ORG_NAME="$REPLY_VAL"

ask "Nom de domaine complet pour accéder à Nextcloud (ex: cloud.exemple.com)" ""
while [ -z "$REPLY_VAL" ]; do
    warn "Le domaine est obligatoire."
    ask "Nom de domaine complet pour accéder à Nextcloud (ex: cloud.exemple.com)" ""
done
DOMAIN="$REPLY_VAL"

ask "Nom d'utilisateur administrateur" "admin"
ADMIN_USER="$REPLY_VAL"

ask_secret "Mot de passe administrateur (laisser vide pour en générer un fort automatiquement)"
ADMIN_PASSWORD="$REPLY_VAL"
if [ -z "$ADMIN_PASSWORD" ]; then
    ADMIN_PASSWORD=$(gen_secret)
    warn "Mot de passe admin généré automatiquement (affiché à la fin du script)."
fi

DB_PASSWORD=$(gen_secret)
DB_ROOT_PASSWORD=$(gen_secret)

ask "Couleur d'accent principale (hex, ex: #C9A84C pour un or sobre)" "#C9A84C"
PRIMARY_COLOR="$REPLY_VAL"

ask "Couleur de fond (hex, charte sobre = fond sombre uni recommandé)" "#0A0A0A"
BACKGROUND_COLOR="$REPLY_VAL"

ask "Chemin local vers un logo carré à uploader (laisser vide pour passer)" ""
LOGO_PATH="$REPLY_VAL"

ask "Forcer le thème sombre pour tous les comptes ? (o/n)" "o"
FORCE_DARK="$REPLY_VAL"

ask "Installer les apps 'lourdes' optionnelles (Recognize, Whiteboard) ? (o/n)" "n"
HEAVY_APPS="$REPLY_VAL"

WHITEBOARD_DOMAIN=""
WHITEBOARD_SECRET=""
if [ "$HEAVY_APPS" = "o" ] || [ "$HEAVY_APPS" = "O" ]; then
    ask "Nom de domaine public pour le service Whiteboard (ex: whiteboard.exemple.com)" ""
    WHITEBOARD_DOMAIN="$REPLY_VAL"
    if [ -n "$WHITEBOARD_DOMAIN" ]; then
        WHITEBOARD_SECRET=$(gen_secret)
    fi
fi

ask "Activer Client Push (notify_push, notifications temps réel mobile/desktop) ? (o/n)" "o"
ENABLE_PUSH="$REPLY_VAL"

PUSH_DOMAIN=""
if [ "$ENABLE_PUSH" = "o" ] || [ "$ENABLE_PUSH" = "O" ]; then
    ask "Nom de domaine public pour Client Push (ex: push.exemple.com)" ""
    PUSH_DOMAIN="$REPLY_VAL"
fi

ask "Activer la recherche plein texte avancee : Elasticsearch + OCR des PDF/images scannes + module 'Recherche+' (onglets, filtres, extraits surlignes) ? (o/n)" "n"
ENABLE_SEARCH="$REPLY_VAL"

OCR_LANG=""
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    warn "Necessite 'git' installe sur ce serveur (pour recuperer le code des modules) et au moins 2 Go de RAM supplementaires pour Elasticsearch."
    ask "Langue(s) OCR, separees par une virgule (ex: fra,eng)" "fra,eng"
    OCR_LANG="$REPLY_VAL"
fi

ask "Code pays par défaut pour les numéros de téléphone (ISO 3166-1, ex: FR, MA, US)" "FR"
PHONE_REGION="$REPLY_VAL"

ask "Chemin d'installation sur ce serveur" "$HOME/nextcloud-workplace"
INSTALL_DIR="$REPLY_VAL"

echo
info "Récapitulatif :"
echo "  Organisation      : $ORG_NAME"
echo "  Domaine           : $DOMAIN"
echo "  Admin             : $ADMIN_USER"
echo "  Couleur accent    : $PRIMARY_COLOR"
echo "  Couleur de fond   : $BACKGROUND_COLOR"
echo "  Thème sombre forcé: $FORCE_DARK"
echo "  Région téléphone  : $PHONE_REGION"
echo "  Client Push       : ${PUSH_DOMAIN:-non}"
echo "  Whiteboard        : ${WHITEBOARD_DOMAIN:-non}"
echo "  Recherche+ (ES)   : ${ENABLE_SEARCH}"
echo "  Répertoire        : $INSTALL_DIR"
echo
read -r -p "Continuer ? (o/n) : " CONFIRM
if [ "$CONFIRM" != "o" ] && [ "$CONFIRM" != "O" ]; then
    err "Annulé."
    exit 1
fi

# ----------------------------------------------------------------------------
# Génération des fichiers
# ----------------------------------------------------------------------------

mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

# L'image Nextcloud standard ne sait pas faire d'OCR (Tesseract absent). Si la
# recherche avancee est demandee, on construit une image maison qui ajoute
# Tesseract + Ghostscript (necessaire pour rasteriser les PDF avant OCR) par
# dessus l'image officielle, plutot que d'utiliser l'image telle quelle.
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    mkdir -p nextcloud-image
    cat > nextcloud-image/Dockerfile <<'EOF'
FROM nextcloud:latest

RUN apt-get update && \
    apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng ghostscript && \
    rm -rf /var/lib/apt/lists/*
EOF
    APP_IMAGE_LINES="    build: ./nextcloud-image
    image: nextcloud-tesseract:local"
    ok "Dockerfile OCR genere (nextcloud-image/Dockerfile)"
else
    APP_IMAGE_LINES="    image: nextcloud"
fi

info "Génération du docker-compose.yml..."
cat > docker-compose.yml <<EOF
services:
  db:
    image: mariadb:11
    restart: always
    command: --transaction-isolation=READ-COMMITTED --binlog-format=ROW
    environment:
      - MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
      - MYSQL_DATABASE=nextcloud
      - MYSQL_USER=nextcloud
      - MYSQL_PASSWORD=${DB_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql

  redis:
    image: redis:alpine
    restart: always

  app:
    container_name: nextcloud
${APP_IMAGE_LINES}
    restart: always
    ports:
      - "8080:80"
    environment:
      - MYSQL_HOST=db
      - MYSQL_DATABASE=nextcloud
      - MYSQL_USER=nextcloud
      - MYSQL_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - NEXTCLOUD_ADMIN_USER=${ADMIN_USER}
      - NEXTCLOUD_ADMIN_PASSWORD=${ADMIN_PASSWORD}
      - NEXTCLOUD_TRUSTED_DOMAINS=${DOMAIN}
      - OVERWRITEPROTOCOL=https
      - OVERWRITECLIURL=https://${DOMAIN}
      - PHP_MEMORY_LIMIT=1536M
    volumes:
      - nc-data:/var/www/html
    depends_on:
      - db
      - redis
EOF

if [ -n "$PUSH_DOMAIN" ]; then
    cat >> docker-compose.yml <<EOF

  notify_push:
    image: nextcloud
    container_name: notify_push
    restart: always
    entrypoint: ["/var/www/html/custom_apps/notify_push/bin/x86_64/notify_push"]
    command: ["--port", "7867", "--nextcloud-url", "https://${DOMAIN}", "--redis-url", "redis://redis:6379", "--database-url", "mysql://nextcloud:${DB_PASSWORD}@db/nextcloud"]
    volumes:
      - nc-data:/var/www/html:ro
    depends_on:
      - db
      - redis
      - app
EOF
    ok "Service notify_push ajouté au docker-compose.yml"
fi

if [ -n "$WHITEBOARD_DOMAIN" ]; then
    cat >> docker-compose.yml <<EOF

  whiteboard:
    image: ghcr.io/nextcloud-releases/whiteboard:latest
    container_name: whiteboard
    restart: always
    environment:
      - NEXTCLOUD_URL=https://${DOMAIN}
      - JWT_SECRET_KEY=${WHITEBOARD_SECRET}
    depends_on:
      - app
EOF
    ok "Service whiteboard ajouté au docker-compose.yml"
fi

if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    cat >> docker-compose.yml <<EOF

  elasticsearch:
    image: docker.elastic.co/elasticsearch/elasticsearch:8.15.0
    container_name: elasticsearch
    restart: always
    environment:
      - discovery.type=single-node
      - xpack.security.enabled=false
      - "ES_JAVA_OPTS=-Xms1g -Xmx1g"
    volumes:
      - es-data:/usr/share/elasticsearch/data
EOF
    ok "Service elasticsearch ajouté au docker-compose.yml"
fi

cat >> docker-compose.yml <<EOF

volumes:
  db-data:
  nc-data:
EOF
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    cat >> docker-compose.yml <<EOF
  es-data:
EOF
fi
ok "docker-compose.yml généré dans $INSTALL_DIR"
warn "NOTE : ce compose expose Nextcloud sur le port 8080 de l'hôte en HTTP. Mets un reverse proxy (nginx/Caddy/Traefik) devant avec un certificat HTTPS pour le domaine '$DOMAIN' avant d'exposer publiquement — ce script ne gère pas cette partie (dépend trop de l'infra existante du serveur cible)."
if [ -n "$PUSH_DOMAIN" ] || [ -n "$WHITEBOARD_DOMAIN" ]; then
    warn "Client Push et/ou Whiteboard ont aussi besoin d'un vhost HTTPS dédié sur le reverse proxy (support WebSocket obligatoire), à ajouter en même temps que celui de Nextcloud."
fi

# ----------------------------------------------------------------------------
# Démarrage
# ----------------------------------------------------------------------------

info "Démarrage des conteneurs..."
# notify_push n'est PAS démarré ici : son binaire vit dans l'app 'notify_push',
# pas encore installée à ce stade -> le conteneur planterait (binaire absent du
# volume nc-data) et ferait avorter tout le script (set -e sur docker compose up -d
# qui retourne un code d'erreur des qu'UN SEUL service echoue a demarrer, meme
# si les autres services demarrent correctement). Il sera démarré plus tard,
# une fois l'app installée. whiteboard, lui, est une image autonome (pas de
# dépendance sur le volume nc-data) et peut démarrer immédiatement.
INITIAL_SERVICES="db redis app"
if [ -n "$WHITEBOARD_DOMAIN" ]; then
    INITIAL_SERVICES="$INITIAL_SERVICES whiteboard"
fi
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    INITIAL_SERVICES="$INITIAL_SERVICES elasticsearch"
fi
docker compose up -d $INITIAL_SERVICES

info "Attente de l'installation automatique de Nextcloud (peut prendre 1-2 minutes)..."
TRIES=0
until docker exec -u www-data nextcloud php occ status 2>/dev/null | grep -q "installed: true"; do
    TRIES=$((TRIES+1))
    if [ "$TRIES" -gt 60 ]; then
        err "Nextcloud ne semble pas s'être installé après 5 minutes. Vérifie 'docker logs nextcloud'."
        exit 1
    fi
    sleep 5
done
ok "Nextcloud installé."

# ----------------------------------------------------------------------------
# Performance / fiabilité de base
# ----------------------------------------------------------------------------

info "Configuration cache Redis (locking + distribué)..."
occ config:system:set memcache.local --value='\OC\Memcache\APCu'
occ config:system:set memcache.locking --value='\OC\Memcache\Redis'
occ config:system:set memcache.distributed --value='\OC\Memcache\Redis'
occ config:system:set redis host --value='redis'
occ config:system:set redis port --value=6379 --type=integer

info "Activation du cron système (mode background jobs)..."
occ background:cron
ok "Pense à ajouter cette ligne à la crontab de l'hôte (pas fait automatiquement par ce script) :"
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    echo "    */1 * * * * docker exec -u www-data nextcloud php -f /var/www/html/cron.php >> $INSTALL_DIR/cron.log 2>&1"
    warn "Intervalle d'1 minute recommandé ici car la recherche plein texte + l'Assistant IA (si activé plus tard) sont traités par ce même cron — au dela de 5 minutes, l'attente perçue devient inconfortable pour les utilisateurs."
else
    echo "    */5 * * * * docker exec -u www-data nextcloud php -f /var/www/html/cron.php >> $INSTALL_DIR/cron.log 2>&1"
fi

info "Corrections de la page 'Avertissements de sécurité & configuration'..."
occ config:system:set maintenance_window_start --value=3 --type=integer
occ config:system:set default_phone_region --value="$PHONE_REGION"
occ maintenance:repair --include-expensive >/dev/null 2>&1 && ok "Migrations de types MIME appliquées" || warn "Migrations MIME : échec (non bloquant, relancer plus tard avec 'occ maintenance:repair --include-expensive')"
occ db:add-missing-indices >/dev/null 2>&1 && ok "Index de base de données ajoutés" || warn "Index DB : échec (non bloquant, relancer plus tard avec 'occ db:add-missing-indices')"
warn "trusted_proxies N'EST PAS configuré automatiquement (dépend de l'IP du reverse proxy qui sera mis en place) — une fois le reverse proxy choisi, exécuter : occ config:system:set trusted_proxies 0 --value='<IP-ou-sous-réseau-du-proxy>' (sinon le ralentisseur anti-force-brute pénalisera à tort tout le trafic)."

# ----------------------------------------------------------------------------
# Apps - socle digital workplace
# ----------------------------------------------------------------------------

info "Installation du socle applicatif digital workplace..."
CORE_APPS=(
    groupfolders    # dossiers d'équipe par groupe
    deck            # kanban / gestion de tâches
    collectives     # wiki collaboratif
    calendar        # agenda partagé
    contacts        # carnet d'adresses partagé
    spreed          # Talk : chat + visio
    circles         # Teams : équipes ad-hoc créées par les utilisateurs
    forms           # formulaires/sondages
    polls           # sondages de décision rapide (type Doodle)
    bookmarks       # liens partagés
    notes           # prise de notes
    twofactor_totp  # 2FA
    richdocuments       # édition Office (Collabora)
    richdocumentscode   # moteur Collabora intégré (lourd au premier démarrage)
)
for app in "${CORE_APPS[@]}"; do
    install_app "$app"
done

if [ "$HEAVY_APPS" = "o" ] || [ "$HEAVY_APPS" = "O" ]; then
    info "Installation des apps lourdes optionnelles..."
    install_app "recognize"
    install_app "whiteboard"
    if [ -n "$WHITEBOARD_DOMAIN" ]; then
        occ config:app:set whiteboard jwt_secret_key --value="$WHITEBOARD_SECRET"
        occ config:app:set whiteboard collabBackendUrl --value="https://$WHITEBOARD_DOMAIN"
        ok "Whiteboard configuré (domaine : $WHITEBOARD_DOMAIN) — nécessite que le reverse proxy route bien ce domaine vers le conteneur 'whiteboard' (port 3002, support WebSocket)."
    else
        warn "Whiteboard installé mais pas configuré (pas de domaine fourni) — l'app ne fonctionnera pas pleinement tant que collabBackendUrl/jwt_secret_key ne sont pas réglés."
    fi
fi

if [ -n "$PUSH_DOMAIN" ]; then
    info "Installation de Client Push (notify_push)..."
    install_app "notify_push"
    info "Démarrage du conteneur notify_push (le binaire est maintenant présent sur le volume, l'app venant d'être installée)..."
    docker compose up -d notify_push
    sleep 3
    info "Configuration de notify_push (peut échouer si le reverse proxy pour '$PUSH_DOMAIN' n'est pas encore en place)..."
    if occ notify_push:setup "https://$PUSH_DOMAIN" 2>&1; then
        ok "Client Push configuré et vérifié."
    else
        warn "Configuration de Client Push incomplète — relancer 'occ notify_push:setup https://$PUSH_DOMAIN' une fois le reverse proxy de ce domaine en place."
    fi
fi

if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    info "Attente qu'Elasticsearch soit pret (peut prendre 1 minute)..."
    TRIES=0
    until docker exec nextcloud curl -s -o /dev/null -w '%{http_code}' http://elasticsearch:9200 2>/dev/null | grep -q "200"; do
        TRIES=$((TRIES+1))
        if [ "$TRIES" -gt 30 ]; then
            warn "Elasticsearch ne repond pas apres 2,5 minutes — la suite de la configuration recherche va probablement echouer. Verifier 'docker logs elasticsearch'."
            break
        fi
        sleep 5
    done
    ok "Elasticsearch pret."

    info "Installation des apps de recherche plein texte..."
    install_app "fulltextsearch"
    install_app "fulltextsearch_elasticsearch"
    install_app "files_fulltextsearch"
    install_app "files_fulltextsearch_tesseract"

    info "Configuration d'Elasticsearch..."
    ES_INDEX_NAME="nextcloud_$(echo "$ORG_NAME" | tr '[:upper:] ' '[:lower:]_' | tr -cd 'a-z0-9_')"
    [ -z "$ES_INDEX_NAME" ] || [ "$ES_INDEX_NAME" = "nextcloud_" ] && ES_INDEX_NAME="nextcloud_workplace"
    occ config:app:set fulltextsearch_elasticsearch elastic_host --value='http://elasticsearch:9200'
    occ config:app:set fulltextsearch_elasticsearch elastic_index --value="$ES_INDEX_NAME"
    occ config:app:set fulltextsearch search_platform --value='OCA\FullTextSearch_Elasticsearch\Platform\ElasticSearchPlatform'
    occ config:app:set fulltextsearch app_navigation --value=true --type=boolean
    occ config:app:set files_fulltextsearch files_group_folders --value=1 --type=boolean
    ok "Elasticsearch configuré (index '$ES_INDEX_NAME')."

    info "Configuration de l'OCR (Tesseract)..."
    occ config:app:set files_fulltextsearch_tesseract tesseract_enabled --value=1
    occ config:app:set files_fulltextsearch_tesseract tesseract_pdf --value=1
    occ config:app:set files_fulltextsearch_tesseract tesseract_lang --value="$OCR_LANG"
    # Plafond de pages OCR par PDF : sans ca, un document tres volumineux (livre,
    # rapport de plusieurs centaines de pages) peut a lui seul depasser le budget
    # temps interne de Nextcloud et interrompre toute la reindexation en cours
    # (verifie en conditions reelles - un PDF de 545 pages a stoppe le processus).
    occ config:app:set files_fulltextsearch_tesseract tesseract_pdf_limit --value='20'

    info "Application du correctif connu sur l'app OCR (bug d'incompatibilite de version dans le package Nextcloud lui-meme)..."
    docker cp /var/www/html/custom_apps/files_fulltextsearch_tesseract/lib/Service/TesseractService.php /tmp/tesseract-service-backup.php 2>/dev/null || true
    docker exec nextcloud python3 - <<'PYEOF' 2>/dev/null || warn "Patch OCR : application echouee ou deja appliquee (non bloquant, voir la doc 'Patchs fragiles')"
path = '/var/www/html/custom_apps/files_fulltextsearch_tesseract/lib/Service/TesseractService.php'
with open(path) as f:
    content = f.read()

replacements = [
    ("$pages = $pdf->getNumberOfPages();", "$pages = $pdf->pageCount();"),
    ("$pdf->setPage($i);", "$pdf->selectPage($i);"),
]
for old, new in replacements:
    content = content.replace(old, new)

old_save = """				$this->logger->debug('saving the current page as image', ['tmpPath' => $tmpPath]);
				$pdf->saveImage($tmpPath);

				$content .= $this->ocrFileFromPath($tmpPath);
			} catch (PageDoesNotExist $e) {
			}"""
new_save = """				$this->logger->debug('saving the current page as image', ['tmpPath' => $tmpPath]);
				$savedPaths = $pdf->save($tmpPath);
				$actualPath = $savedPaths[0] ?? $tmpPath;

				$content .= $this->ocrFileFromPath($actualPath);

				if ($actualPath !== $tmpPath && file_exists($actualPath)) {
					@unlink($actualPath);
				}
			} catch (PageDoesNotExist $e) {
			}"""
if old_save in content:
    content = content.replace(old_save, new_save)
else:
    raise SystemExit(1)

with open(path, 'w') as f:
    f.write(content)
print('patched')
PYEOF
    ok "Correctif OCR appliqué (ou déjà à jour)."

    info "Récupération et déploiement des modules 'Recherche+' et connecteur Collectives..."
    TMP_REPO=$(mktemp -d)
    if git clone --depth 1 https://github.com/adilinfo14/nextcloud.git "$TMP_REPO" >/dev/null 2>&1; then
        docker cp "$TMP_REPO/homelab/custom_apps_search_hub" nextcloud:/var/www/html/custom_apps/search_hub
        docker cp "$TMP_REPO/homelab/custom_apps_fulltextsearch_collectives" nextcloud:/var/www/html/custom_apps/fulltextsearch_collectives
        docker exec nextcloud chown -R www-data:www-data /var/www/html/custom_apps/search_hub /var/www/html/custom_apps/fulltextsearch_collectives
        occ app:enable search_hub
        occ app:enable fulltextsearch_collectives
        ok "Modules 'Recherche+' et connecteur Collectives installés et activés."
    else
        warn "Echec de récupération des modules (git indisponible ou réseau) — Elasticsearch/OCR sont configurés mais l'interface 'Recherche+' n'est pas installée. Relancer manuellement : git clone https://github.com/adilinfo14/nextcloud puis copier homelab/custom_apps_search_hub et homelab/custom_apps_fulltextsearch_collectives dans custom_apps/."
    fi
    rm -rf "$TMP_REPO"

    info "Lancement de l'indexation initiale en arrière-plan (peut prendre plusieurs minutes selon le volume de données)..."
    docker exec -u www-data nextcloud sh -c "nohup php occ fulltextsearch:index --no-interaction > /tmp/fulltextsearch-initial-index.log 2>&1 &"
    ok "Indexation lancée (log dans le conteneur : /tmp/fulltextsearch-initial-index.log). Le cron reprendra ensuite les fichiers ajoutés au fil de l'eau."
fi

# ----------------------------------------------------------------------------
# Charte graphique
# ----------------------------------------------------------------------------

info "Application de la charte graphique..."
occ theming:config name "$ORG_NAME"
occ theming:config slogan "Espace de travail numérique"
occ theming:config color "$PRIMARY_COLOR"
occ theming:config background_color "$BACKGROUND_COLOR" || warn "background_color non supporté sur cette version de Nextcloud (ignoré)"

if [ "$FORCE_DARK" = "o" ] || [ "$FORCE_DARK" = "O" ]; then
    occ config:system:set enforce_theme --value=dark
    ok "Thème sombre forcé pour tous les comptes."
fi

if [ -n "$LOGO_PATH" ] && [ -f "$LOGO_PATH" ]; then
    info "Upload du logo..."
    docker cp "$LOGO_PATH" nextcloud:/tmp/logo-upload.png
    docker exec -u www-data nextcloud php -r "
        require '/var/www/html/lib/base.php';
        \OC_Util::setupFS('$ADMIN_USER');
        \$im = \OC::\$server->get(\OCA\Theming\ImageManager::class);
        \$path = '/tmp/logo-upload.png';
        foreach (['logo', 'logoheader', 'favicon'] as \$key) {
            \$im->updateImage(\$key, \$path);
        }
        echo \"Logo applique\n\";
    "
    docker exec nextcloud rm -f /tmp/logo-upload.png
    ok "Logo appliqué (favicon, header, logo)."
else
    warn "Pas de logo fourni, étape ignorée."
fi

# ----------------------------------------------------------------------------
# Sécurité de base
# ----------------------------------------------------------------------------

info "Activation des réglages de sécurité de base..."
occ app:enable admin_audit 2>/dev/null || true

# ----------------------------------------------------------------------------
# Récapitulatif final
# ----------------------------------------------------------------------------

echo
echo -e "${C_GREEN}========================================================================${C_RESET}"
echo -e "${C_GREEN}  Déploiement terminé${C_RESET}"
echo -e "${C_GREEN}========================================================================${C_RESET}"
echo
echo "  URL (via le port 8080, à mettre derrière un reverse proxy HTTPS) :"
echo "    http://<ip-serveur>:8080"
echo
echo "  Identifiants admin :"
echo "    Utilisateur : $ADMIN_USER"
echo "    Mot de passe : $ADMIN_PASSWORD"
echo
echo "  Mot de passe base de données (à conserver dans un gestionnaire de mots de passe) :"
echo "    MYSQL_PASSWORD      : $DB_PASSWORD"
echo "    MYSQL_ROOT_PASSWORD : $DB_ROOT_PASSWORD"
echo
warn "Étapes manuelles restantes (volontairement non automatisées) :"
echo "  1. Mettre un reverse proxy HTTPS (nginx/Caddy/Traefik) devant le port 8080 pour '$DOMAIN'"
if [ -n "$PUSH_DOMAIN" ]; then
    echo "     + un vhost HTTPS/WebSocket pour '$PUSH_DOMAIN' -> conteneur notify_push:7867"
fi
if [ -n "$WHITEBOARD_DOMAIN" ]; then
    echo "     + un vhost HTTPS/WebSocket pour '$WHITEBOARD_DOMAIN' -> conteneur whiteboard:3002"
fi
echo "  2. Ajouter la ligne cron indiquée plus haut à la crontab de l'hôte"
echo "  3. Une fois le reverse proxy en place : occ config:system:set trusted_proxies 0 --value='<IP-du-proxy>'"
if [ -n "$PUSH_DOMAIN" ]; then
    echo "  4. Si Client Push a échoué faute de reverse proxy encore absent : relancer 'occ notify_push:setup https://$PUSH_DOMAIN'"
fi
echo "  5. Configurer les groupes/équipes clients (voir doc fournie séparément)"
echo "  6. Pour Talk en visio multi-participants fiable : prévoir un serveur TURN + serveur de signalisation"
echo "     (non générique d'un client à l'autre, dépend de la topologie réseau — cf. README du dépôt)"
if [ "$ENABLE_SEARCH" = "o" ] || [ "$ENABLE_SEARCH" = "O" ]; then
    echo "  7. Recherche+ : vérifier l'indexation initiale (Administration > Recherche+, ou 'docker exec nextcloud cat /tmp/fulltextsearch-initial-index.log') — sur un volume important de données, ça peut encore tourner plusieurs minutes après la fin de ce script."
    echo "     Prévoir au moins 2 Go de RAM dédiés à Elasticsearch en production (ES_JAVA_OPTS déjà réglé à 1 Go, à ajuster dans docker-compose.yml si besoin)."
fi
echo
warn "Section recherche/Elasticsearch de ce script : construite le 2026-07-13, PAS ENCORE testée en conditions réelles sur une VM externe (contrairement au reste de ce script, validé sur une vraie installation). Tester d'abord sur un environnement non critique avant un déploiement client."
echo
ok "Script terminé."

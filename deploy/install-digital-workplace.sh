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
    image: nextcloud
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

volumes:
  db-data:
  nc-data:
EOF
ok "docker-compose.yml généré dans $INSTALL_DIR"
warn "NOTE : ce compose expose Nextcloud sur le port 8080 de l'hôte en HTTP. Mets un reverse proxy (nginx/Caddy/Traefik) devant avec un certificat HTTPS pour le domaine '$DOMAIN' avant d'exposer publiquement — ce script ne gère pas cette partie (dépend trop de l'infra existante du serveur cible)."

# ----------------------------------------------------------------------------
# Démarrage
# ----------------------------------------------------------------------------

info "Démarrage des conteneurs..."
docker compose up -d

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
echo "    */5 * * * * docker exec -u www-data nextcloud php -f /var/www/html/cron.php >> $INSTALL_DIR/cron.log 2>&1"

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
    warn "Whiteboard nécessite un service serveur externe (nextcloud-whiteboard-server) pour fonctionner pleinement — non déployé par ce script."
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
echo "  2. Ajouter la ligne cron indiquée plus haut à la crontab de l'hôte"
echo "  3. Configurer les groupes/équipes clients (voir doc fournie séparément)"
echo "  4. Pour Talk en visio multi-participants fiable : prévoir un serveur TURN + serveur de signalisation"
echo "     (non générique d'un client à l'autre, dépend de la topologie réseau — cf. README du dépôt)"
echo
ok "Script terminé."

# HANDOFF — Nextcloud TKonsulting & services du homelab

Date de référence : 2026-09-26. Rédigé à partir des fichiers mémoire, du journal de session (2026-07-06 à 2026-09) et d'une vérification en lecture seule du serveur (`ssh confia-vm`) le 2026-09-26.
Aucun secret dans ce document : tous les identifiants sont « dans Vaultwarden » (https://vaultwarden.noschoixpourvous.com) ou dans les fichiers de config du serveur (voir §4.5).

---

## 1. But du projet & contexte

Adil Tsouli Kamal (TKonsulting, cabinet de conseil, Lyon) possède un serveur « homelab » dans son garage (VM VMware `adil-VMware20-1`, alias SSH `confia-vm`, ~48 cœurs alloués / 256 Go RAM hôte, ~70 conteneurs Docker). Ce serveur héberge, en plus de ConfIA/Lesensia (autre projet, voir son propre HANDOFF), un ensemble de services « plateforme » :

- **Nextcloud « TKonsulting »** : espace client + digital workplace interne (fichiers, Talk, Collectives/wiki, Deck, Collabora, signature LibreSign, recherche d'entreprise maison « Recherche+ », assistant IA, connecteur MCP pour Claude/Perplexity). Sert aussi de vitrine commerciale (« Nextcloud comme alternative à Office 365 / SharePoint ») et de base de documentation (wikis pédagogiques IA, ISO 30401, modes opératoires).
- **Portail Dashy** (page d'accueil de toutes les applis), **Vaultwarden** (coffre de mots de passe, sur K3s via ArgoCD), **Listmonk** (newsletters), **Uptime Kuma**, **Squash TM** + **Grafana/k6** (tests), évaluations **Nuxeo / Alfresco** (arrêtées).
- **Exposition Internet** : Cloudflare Tunnel (pas de port ouvert sur la box) → `proxy-nginx` → conteneurs. Domaine `noschoixpourvous.com` (zone Cloudflare).
- **Sites hébergés sur le homelab** : sondage (Flask), talesens (Astro), tkonsulting (test) — voir §3.7.

Contrainte structurante : l'utilisateur veut agir vite sur SON serveur (autorisation large donnée le 2026-09-18), mais le serveur est dense et partagé : un changement mal ciblé coupe plusieurs sites (incidents vécus, §5).

---

### 2.1 En ligne (vérifié le 2026-09-26)
| Service | URL | Statut |
|---|---|---|
| Nextcloud 32.0.2.2 | https://nextcloud.noschoixpourvous.com | 200, `status.php` OK, sauvegarde du 2026-09-26 03:52 réussie |
| Portail Dashy | https://portail.noschoixpourvous.com (aussi https://dashy.noschoixpourvous.com) | 200 |
| Vaultwarden (K3s) | https://vaultwarden.noschoixpourvous.com | 200 |
| ArgoCD (K3s) | https://argocd.noschoixpourvous.com | 200 (certificat origine expiré, voir §5) |
| Uptime Kuma | https://kuma.noschoixpourvous.com | 302 (login) |
| Squash TM | https://squash.noschoixpourvous.com/squash/ | 303 (login) |
| Grafana (k6) | https://grafana.noschoixpourvous.com | 302 (login) |
| Signaling Talk (HPB) | https://signaling.noschoixpourvous.com | 404 sur `/` = normal (WebSocket) |
| Client Push | https://push.noschoixpourvous.com | 404 sur `/` = normal |
| MCP distant Nextcloud | https://mcp-nextcloud.noschoixpourvous.com/mcp | 401 = normal (OAuth2 requis) |
| sondage | https://sondage.noschoixpourvous.com | 302 (login) |
| talesens | https://talesens.noschoixpourvous.com | 200 |
| tkonsulting (site public) | https://www.tkonsulting.fr | 301 → hébergé sur le VPS IONOS [IP-VPS] (pas le homelab) |

### 2.2 Ce qui marche (Nextcloud)
- Volontairement désactivées : photos, activity (et contacts/notes réactivées le 2026-07-07).
- Thème noir/or TKonsulting (nom « TKonsulting », fond `#0A0A0A`, accent `#C9A84C`, `enforce_theme=dark`).
- Comptes : `[compte]` (admin, compte principal), `[compte]`, `[compte]`, `[compte]`, `[compte]`. Groupes : `rh|compta|si` × `-manager|-utilisateur`, `guest_app`, `admin`. Groupfolders : RH(1), Compta(2), SI(3). 2FA imposé aux 3 groupes `*-manager`.
- 11 collectives (wiki) : 1 Intelligence Artificielle ; 2 Solution Nextcloud (argumentaire commercial) ; 3 Mode opératoire Nextcloud (manuel d'exploitation, pages 01 à 26) ; 4 Nuxeo (test complémentarité) ; 5 Alfresco (test complémentarité) ; 6 Lexique Assurance IARD (réduit à une redirection vers la 9) ; 7 Formation – Outils du homelab ; 8 CDG Capital – Graphe ; 9 Assurance – Graphe ; 10 INJAZ Al-Maghrib ; 11 Annuaire des applications (URLs seulement, aucun mot de passe).
- Étiquettes système : Administratif, Client, Technique, Modèle, Formation, Migration Odoo, Cybersécurité, Tsouli, [client], Confidentiel (règle Files Access Control : écriture interdite sur les fichiers « Confidentiel » hors groupe `rh-manager`).
- Talk : signalisation externe HPB (`nextcloud-spreed-signaling` sur le homelab) + TURN/STUN `coturn` sur le VPS IONOS. Invitations Talk et calendrier par email OK (SMTP OVH `ssl0.ovh.net`, domaine mail `adil-tsoulikamal.com`, DMARC ajouté le 2026-07-07).
- Collabora (Word/Excel/PowerPoint en ligne) : fonctionnel depuis le 2026-07-15 (voir §5, 5 causes cumulées).
- « Recherche+ » (`/apps/search_hub/`) : recherche mot-clé (BM25 via Elasticsearch) + « recherche par sens » (embeddings `mxbai-embed-large` via Ollama + reranker LLM), facettes, console admin (`/admin/settings` section Recherche+), connecteurs iaeasy et « Lesensia » (doc technique), assistant à réponses ancrées respectant les droits.
- Connecteur MCP distant (Claude.ai web via OAuth2, aussi Perplexity) : livré et testé le 2026-07-16 (test positif + test négatif avec un compte sans droit).
- Cron Nextcloud toutes les minutes ; Redis (`nextcloud-redis`), Client Push, ClamAV en place.

### 2.3 Cassé, incomplet ou à surveiller
- **Nuxeo et Alfresco sont ARRÊTÉS** (conteneurs `Exited` depuis ~2026-07-21, non supprimés : `nuxeo-test`, `alfresco-test-*`) pour libérer le CPU (load average passé de 49 à 16 sur 48 cœurs). Dashy et les DNS/routes pointent toujours dessus : les URLs `nuxeo.` / `alfresco.` répondent 200 mais ce n'est probablement pas l'appli (à vérifier). Décision à prendre : supprimer définitivement ou garder à l'arrêt.
- Le cluster K3s (master `[IP-INTERNE]`, worker `[IP-INTERNE]`) n'a pas pu être réinspecté dans cette passe : état « à vérifier » (dernière vue 2026-07-20 : v1.33.6, deux nœuds Ready, 200+ jours d'uptime).
- Dépôt GitHub `adilinfo14/nextcloud` : dernier push 2026-07-15 ; le serveur porte `search_hub` 1.21.1 et le dépôt était à 1.21.0 → **dérive possible**, à resynchroniser (voir §4.6). Le serveur MCP distant (`/home/adil/mcp-nextcloud-search-remote/`) n'est pas garanti présent dans ce dépôt (à vérifier).
- Nextcloud est en 32.0.2 (des versions 32.0.x plus récentes existent) : mise à jour volontairement différée (voir §5, `tk_theme` et patchs).
- Uptime Kuma : la création du moniteur `/status.php` de Nextcloud a été laissée à l'utilisateur (pas d'API simple) : état à vérifier dans l'UI.

---


### 3.1 Accès au serveur
- `ssh confia-vm` (alias local `~/.ssh/config` : `[IP-TAILSCALE]` port `[PORT-SSH]` user `adil`, via **Tailscale**). Secours réseau local : `ssh confia-vm-local` (`[IP-INTERNE]:[PORT-SSH]`). Route Cloudflare Access SSH : `ssh.noschoixpourvous.com` → `ssh://127.0.0.1:[PORT-SSH]`.
- `adil` est dans le groupe `docker` mais **n'a pas de sudo sans mot de passe** : tout ce qui est root-only (`/etc/cloudflared`, `/home/proxy/...`) se fait via un conteneur Docker temporaire montant le dossier (`docker run --rm -v /home/proxy:/host-proxy alpine sh -c '...'`) ou `docker exec proxy-nginx`. Ne jamais demander/saisir le mot de passe sudo dans le chat.
- Le VPS IONOS de prod : `ssh root@[IP-VPS]` (ConfIA, tkonsulting.fr, coturn, cible des sauvegardes).

### 3.2 Nextcloud
- Image custom : `~/nextcloud-image/Dockerfile` (`FROM nextcloud:latest` + tesseract fra/eng, ghostscript, poppler-utils, locales `en_US.UTF-8`). Reconstruire : `cd ~/nextcloud-image && docker build -t nextcloud-tesseract:local .` (SANS `--pull`, puis `docker compose up -d app` : quelques secondes de coupure).
- `extra_hosts: nextcloud.noschoixpourvous.com:host-gateway` sur `app` et `notify_push` : le conteneur se rappelle lui-même via `proxy-nginx` sans sortir sur Internet (indispensable pour Collabora, §5).
- Elasticsearch dédié : conteneur `nextcloud-elasticsearch` (8.15.0, mono-nœud, sécurité X-Pack désactivée, réseau interne), index `nextcloud_tkonsulting` (~3 800 docs, statut `yellow` = normal en mono-nœud).
- Ollama (conteneur `ollama`, partagé) : `mxbai-embed-large` (embeddings), `llama3:8b` (reranker/« pourquoi ce résultat »/assistant), `qwen2.5:7b-instruct` (Assistant IA Nextcloud). Pas de GPU : les appels LLM prennent 15 à 130 s.
- Patchs de code tiers (fragiles, sauvegardés dans `~/nextcloud-patches/`) : `files_fulltextsearch_tesseract/lib/Service/TesseractService.php` (API `spatie/pdf-to-image` v3) et `richdocuments/lib/Service/RemoteService.php` (double `fclose`). Documentés dans la page wiki « 21 Patchs fragiles à surveiller après mise à jour ».
- Données SQL : MariaDB, base `nextcloud`, utilisateur `nextcloud` ; requêtes admin possibles avec `docker exec projet_nextcloud-db-1 sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" nextcloud -e "..."'` (le mot de passe reste dans l'environnement du conteneur).

### 3.3 Proxy, HTTPS et tunnel
- `proxy-nginx` (nginx:stable) et `proxy-certbot` (image `certbot/dns-cloudflare`, boucle `certbot renew` toutes les 12 h) : compose `/home/proxy/docker-compose.yml`, vhosts `/home/proxy/nginx/conf.d/*.conf` (dans le conteneur : `/etc/nginx/conf.d/`), certificats `/home/proxy-nginx/certbot/conf`. Ports 80/443 publiés sur l'hôte.
- `nextcloud.conf` contient aussi le document de découverte OAuth2 `/.well-known/oauth-authorization-server` (statique, sans `code_challenge_methods_supported` car l'app `oauth2` ne gère pas PKCE) et HSTS.
- **Cloudflare Tunnel** : process `cloudflared` (systemd `cloudflared.service`, actif et enabled au 2026-09-26 ; il n'y avait PAS d'unité systemd en juillet). Le tunnel est **piloté à distance** depuis le dashboard : les routes réelles (des dizaines d'hostnames) sont dans **Zero Trust → Networks → Tunnels → (tunnel du homelab) → Published application routes**. Le fichier local `/etc/cloudflared/config.yml` (root) est un leurre : le modifier n'a AUCUN effet sur le routage (vérifié 2026-07-16 puis 2026-09). Les routes existantes visent `https://127.0.0.1:443` (nginx, « No TLS Verify » pour les vhosts à certificat expiré) ou `http://127.0.0.1:<port>` pour les services sans vhost (Listmonk 9010, Squash 9095, Grafana 3001, Dashy 4000).
- DNS : zone Cloudflare `noschoixpourvous.com` ; la route DNS peut aussi être créée par `cloudflared tunnel route dns <tunnel> <host>` (crée le CNAME). Domaine mail `adil-tsoulikamal.com` : DNS chez OVH (pas Cloudflare).

### 3.4 Portail, Vaultwarden, K3s
- **Dashy** : conteneur `dashy` (`lissy93/dashy`), `~/dashy/docker-compose.yml` + `~/dashy/user-data/conf.yml`, écoute `127.0.0.1:4000`. Auth activée (invité autorisé ; section privée « Infra avancée (privé) » avec ArgoCD, Vaultwarden, Neo4j en `hideForGuests`). Compte admin Dashy : dans Vaultwarden (« Dashy (login admin) »). Icônes en `favicon-local` (l'API tierce `favicon` est peu fiable). Éditeur intégré : mode édition (crayon) → clic droit → masquer/modifier → « Save to Disk ». Homepage (essayé avant) a été retiré.
- **K3s** : 2 VM VMware du homelab : master `adil-k3s` (`[IP-INTERNE]`, SSH port [PORT-SSH]) et worker1 (`k3s-worker-vmware20-1`, `[IP-INTERNE]`, port [PORT-SSH]) ; la mémoire note aussi une IP Tailscale pour le master, mais c'est celle de confia-vm : à vérifier, user `adil`, `kubectl` nécessite `sudo` (mot de passe → l'utilisateur). Applis GitOps : nocodb, whoami (test), vaultwarden ; namespaces : argocd, cert-manager, erpnext, longhorn-system, nocodb, training.
- Les identifiants des services (Nuxeo, Alfresco, Listmonk, Grafana, InfluxDB, VM K3s, SMTP Nextcloud, Dashy…) ont été saisis dans Vaultwarden le 2026-07-20 via la CLI `bw` (`bw login --raw` puis `--session` explicite à CHAQUE commande ; script supprimé juste après).

### 3.5 Autres services de la plateforme
- **Listmonk** : `~/listmonk-test/docker-compose.yml`, conteneurs `listmonk_app` (9010→9000) et `listmonk_db` (postgres:17). SMTP : relais OVH, expéditeur `TKonsulting <[e-mail]>` (port 465 = mode TLS, pas STARTTLS ; le champ « Username » SMTP doit être l'adresse mail, pas le login Listmonk).
- **Squash TM** : `~/squash-tm/`, conteneurs `squash-tm` (127.0.0.1:9095) + `squash-tm-md` (MariaDB). Voir [[project_squash_tm_projets]] pour les projets de test (API REST/JWT, script d'automatisation).
- **Grafana + k6 + InfluxDB** : `~/k6-perf/`, `k6-grafana` (127.0.0.1:3001), `k6-influxdb` (8086) ; `k6-runner` arrêté (lancer un test : déposer un script dans `~/k6-perf/` puis le lancer via compose).
- **Uptime Kuma** : conteneur `uptime-kuma`, vhost `kuma.conf`.
- **Nuxeo / Alfresco** (évaluations, arrêtés) : `~/nuxeo-test/` (image publique `nuxeo:LTS-2019` faute de licence Hyland pour les versions récentes) et `~/alfresco-test/` (8 conteneurs). Données de test importées : 40 fichiers « Formation » sur chacun (2026-07-17), puis COEXYA (2 418 fichiers → Nuxeo), YOOCAN/BABILOU (1 737 → Alfresco), Alptis sélectif (288 → Nuxeo) le 2026-07-20. Conclusion de l'évaluation : Nuxeo = 1 conteneur, ~30 permissions à la carte, Elasticsearch embarqué ; Alfresco = 8 conteneurs, 5 rôles prédéfinis, Solr, workflow BPMN « Pooled Review » sans équivalent chez Nuxeo. Aucune décision d'adoption prise ; Nextcloud reste la plateforme du quotidien.
- **Collabora** : pas d'instance séparée : le serveur intégré `richdocumentscode` (dans Nextcloud) suffit (pas d'évaluation d'un Collabora standalone retrouvée dans le journal).
- **coturn (TURN/STUN)** : sur le VPS IONOS, `/home/coturn/` (`docker-compose.yml`, `turnserver.conf`), `network_mode: host`, ports TCP 3478, UDP 3478, UDP 49160-49200.
- **Signaling (HPB)** : conteneur `signaling` (`strukturag/nextcloud-spreed-signaling`), config `/home/adil/signaling/server.conf`. Clé `blockkey` = exactement 16/24/32 octets.
- **MCP distant** : `~/mcp-nextcloud-search-remote/` (Node.js, SDK MCP, transport Streamable HTTP stateless), conteneur `mcp-nextcloud-search` (3333), `GUIDE-CONNEXION-EXTERNE.md` (Claude.ai, Perplexity). Client OAuth2 créé côté Nextcloud par plateforme.

### 3.6 Sauvegardes et crons (crontab de `adil` sur confia-vm)
| Heure | Tâche |
|---|---|
| chaque minute | `docker exec -u www-data nextcloud php -f /var/www/html/cron.php` |
| 03:45 | `/home/adil/nextcloud-backup.sh` : dump MariaDB (local `~/nextcloud-backups/db`, rétention 14 j) + archive `tar` du dossier data envoyée en flux vers **IONOS** (`ncbackup@[IP-VPS]`, `/home/ncbackup/backups/nextcloud-tkonsulting/{db,data}`, rétention 7 j pour les données), clé dédiée `~/.ssh/id_ed25519_backup_ionos`. Log : `~/nextcloud-backups/backup.log` (dernier run OK le 2026-09-26). |
| 04:00 le 1er | export mensuel de `data/audit.log` vers `~/nextcloud-audit-exports/` (la revue reste un acte humain) |
| 04:15 | `search_hub/embed_backfill.php` (embeddings incrémental) → `~/search-hub-embeddings.log` |
| 04:30 | `search_hub/iaeasy_index.php` (resync complète du connecteur iaeasy) |
| 04:35 | `~/.local/bin/confia-doc-index.sh` (connecteur doc Lesensia) |
| 08:00 | `cert-expiry-alert.sh` (mail via le mailer Nextcloud si cert expiré ou < 14 j) |
Autres crons sur cette machine (hors périmètre) : ConfIA (failover, prune, auto-sync), movies, BasketVision 04:50. Ne pas confondre avec la sauvegarde ConfIA 03:30 (autre job, vers Tailscale).

### 3.7 Sites hébergés sur le homelab
- **Sondage Express** : dépôt git `/home/sondage/sondage/` (branche courante, HEAD `80311c4`), Flask + SQLite. Le code déployé est dans **`sondage_clone/`** (compose `sondage_clone/docker-compose.yml`, conteneur `sondage-clone`, `127.0.0.1:5050`, vhost `sondage.conf`). Ne jamais éditer les `app.py`/`templates`/`static` de la racine. Homelab uniquement (pas sur IONOS). Déployer : `scp` vers `confia-vm:/home/sondage/sondage/sondage_clone/` puis `cd .../sondage_clone && docker compose build --no-cache sondage && docker compose up -d`. Tester avec `flask_app.test_client()` avant. Design system dans `static/style.css` (variables `--primary` etc., `[data-theme="dark"]`).
- **TALESENS** (agence communication, site vitrine Astro 5 pages) : dépôt https://github.com/adilinfo14/TALESENS (branche `main`) ; clone `/home/adil/talesens/`, build → `dist/` monté en `:ro` dans `proxy-nginx` (`/talesens/dist`), vhost `talesens.conf`. Mise à jour : `ssh confia-vm 'cd /home/adil/talesens && git pull && npm run build'` (nginx sert directement le nouveau `dist`). Un vhost `00-talesens` existe aussi sur IONOS (miroir historique) : le DNS/route actif est celui du tunnel homelab (200 vérifié) ; **ne pas** avoir les deux en même temps. Note : la mémoire `project_talesens.md` parle d'OVH et d'Astro 6 : obsolète (OVH remplacé par IONOS le 2026-06-25 ; version Astro à vérifier dans `package.json`).
- **TKonsulting (site vitrine)** : Astro 5 + Tailwind, noir/or, sources locales `c:\Users\Utilisateur\OneDrive\Dev Tkonsulting\`, déployé sur **IONOS** (`/var/www/tkonsulting`, `rsync dist/`, `./deploy.sh`), domaine `tkonsulting.fr` (DNS → [IP-VPS]). Une copie de test tourne aussi sur le homelab (`/home/adil/tkonsulting`, vhost `tkonsulting.conf`, `tkonsulting.noschoixpourvous.com` : pas de route tunnel, injoignable de l'extérieur).

---

### 4.1 Santé
```bash
ssh confia-vm 'docker ps --format "{{.Names}}\t{{.Status}}" | sort'
curl -s https://nextcloud.noschoixpourvous.com/status.php
ssh confia-vm 'docker exec -u www-data nextcloud php occ status'
ssh confia-vm 'tail -5 ~/nextcloud-backups/backup.log; tail -3 ~/search-hub-embeddings.log; tail -3 ~/cert-expiry-alert.log'
ssh confia-vm 'journalctl -u cloudflared --no-pager | tail -5'   # les erreurs erpnext.* sont du bruit connu (route sans origine)
ssh confia-vm 'docker exec nextcloud-elasticsearch curl -s localhost:9200/_cluster/health'
```
Console admin Recherche+ : Nextcloud → Paramètres → Administration → Recherche+ (comptes d'index, base vectorielle, logs, bouton « Indexer un document précis »).

### 4.2 Commandes Nextcloud courantes
Toujours : `ssh confia-vm 'docker exec -u www-data nextcloud php occ <commande>'`.
- Ajouter un client : `group:add client-<slug>` → `user:add --group="client-<slug>" <user>` → `groupfolders:create "<Nom>"` (noter l'ID) → `groupfolders:group <ID> client-<slug> read write share delete` (**droits séparés par des ESPACES, pas des virgules**) → optionnel `groupfolders:quota <ID> <Go>`.
- Nouvelle équipe interne : 2 groupes `<equipe>-manager` (read write share delete) / `<equipe>-utilisateur` (read write) + 1 groupfolder. Ne pas passer par Circles (Groupfolders n'accepte que des groupes).
- Permissions avancées : `groupfolders:permissions -g <groupe> <id> <chemin> '+read' '+write'` — l'option `-g` AVANT les arguments positionnels.
- Recharger le CSS de `tk_theme` : `occ app:disable tk_theme && occ app:enable tk_theme` (**jamais `occ upgrade`**).
- Import Markdown dans une collective : `occ collectives:import:markdown <dossier> --collective-id=<ID> --user-id=<user> --parent-id=<file_id du Readme.md parent, PAS du dossier>` (récursif ; `--parent-id=0` = racine de la collective ciblée), puis `occ collectives:generate-slugs`.
- Tags : `occ tag:add "<nom>" public`, `occ tag:files:add "<chemin>" "<tag>" public`.
- Scripts PHP hors `occ` (apps tierces) : toujours poser la session avant de résoudre les services : `require '/var/www/html/lib/base.php'; $u=\OC::$server->get(\OCP\IUserManager::class)->get($uid); \OC::$server->get(\OCP\IUserSession::class)->setUser($u); \OC_Util::setupFS($uid);`.

1. Déployer le conteneur (réseau `proxy-net` si derrière nginx, sinon port `127.0.0.1:<port>`).
2. Certificat (si vhost nginx) : DNS-01 Cloudflare via `proxy-certbot` (identifiants Cloudflare dans `/etc/letsencrypt/cloudflare.ini` du conteneur, jamais dans git) — commande exacte à vérifier dans `docker exec proxy-certbot certbot --help`.
3. Vhost : écrire le fichier EN LOCAL puis `docker cp fichier.conf proxy-nginx:/etc/nginx/conf.d/` (les heredocs imbriqués ssh→docker→sh corrompent les `$` nginx), `docker exec proxy-nginx nginx -t && docker exec proxy-nginx nginx -s reload`.
4. **Route tunnel dans le dashboard Cloudflare** (Zero Trust → Networks → Tunnels → Published application routes) : Subdomain + Domain séparés (attention au doublon `x.noschoixpourvous.com.noschoixpourvous.com` ; vérifier le hostname dans les logs `Updated to new configuration`). Service HTTPS `127.0.0.1:443` (+ « No TLS Verify ») pour un vhost nginx, ou HTTP `127.0.0.1:<port>`.
5. Tester avec `curl -I`, ajouter l'appli à Dashy, saisir les identifiants dans Vaultwarden.

### 4.4 Sauvegarde / restauration Nextcloud
- Manuel : `ssh confia-vm ~/nextcloud-backup.sh`. Restauration : dump `~/nextcloud-backups/db/nextcloud-db-AAAAMMJJ.sql.gz` (ou sur IONOS `/home/ncbackup/backups/nextcloud-tkonsulting/db/`) + archive data `nextcloud-data-AAAAMMJJ.tar.gz` (IONOS uniquement). Procédure de restauration complète non rejouée : à tester sur une VM jetable avant d'en avoir besoin. Les apps custom et l'image Docker se reconstruisent depuis le dépôt GitHub + `~/nextcloud-image/Dockerfile` (le code des apps est aussi dans le volume `nextcloud-data`, donc dans l'archive).
- Pas de sauvegarde des conteneurs Vaultwarden/K3s vérifiée dans cette passe : **à vérifier** (Vaultwarden contient tous les secrets : priorité haute).

### 4.6 Mettre à jour le dépôt GitHub public
Copier les sources modifiées du serveur vers `homelab/...`, puis **avant tout commit** : `grep -rnE "<valeurs de secrets connues>|password|secret|token" .` et remplacer par `CHANGE_ME_*`. Identité git : `user.email = tsouli.kamal.adil@gmail.com`. Commits terminés par la ligne `Co-Authored-By: Claude Sonnet 5 <[e-mail]>` si Claude commit.

---

## 5. Décisions clés & pièges rencontrés

1. **Incident 2026-07-12 : `occ upgrade` lancé sans prévenir → ~13 min de 503.** Règle : jamais `occ upgrade`/`maintenance:repair`/migration DB sans accord explicite de l'utilisateur. Pour rafraîchir le CSS : disable/enable de l'app.
3. **Tunnel Cloudflare géré à distance** : éditer `/etc/cloudflared/config.yml` ne sert à rien ; `kill -HUP` sur cloudflared TUE le process (coupure de tous les sites ~1 min en juillet). Toute nouvelle route = dashboard. Timeout ~10 s côté tunnel pour certaines requêtes lentes → 502 (ConfIA : retry frontend ; Nextcloud : assistant/embeddings lents = passer en asynchrone).
4. **Pare-feu IONOS séparé de l'OS** (Cloud Panel → Stratégies de pare-feu) : le port TURN 3478 était OK localement mais fermé de l'extérieur tant que les règles (TCP/UDP 3478, UDP 49160-49200) n'étaient pas ajoutées à la main.
5. **Talk derrière NAT domestique** : pas de port-forwarding possible → split : TURN sur IONOS (IP publique), HPB signaling sur le homelab. Le HPB n'est pas « best-effort » : un 404 sur `/api/v1/room/` casse toute création de conversation (route tunnel manquante).
6. **Nextcloud 32 : config « lazy »** (`redis` etc. hors `config.php` texte) → les binaires tiers (`notify_push`) doivent recevoir `--redis-url`/`--database-url` explicitement. Ne jamais forcer `--type=integer/boolean` sur une clé d'app tierce sans lire son code (bug `integration_openai request_timeout` : réponses vides silencieuses).
7. **Services DI et session** : les scripts CLI sur Collectives/Tables doivent appeler `IUserSession::setUser()` avant de résoudre les services, sinon `userId` reste `null` (tables fantômes créées, `NotFoundException`). Après un échec, vérifier et purger les objets créés à moitié.
8. **Collectives** : la hiérarchie = dossiers physiques (`oc_collectives_pages` sans parent, pas de `collective_id` en base) ; déplacer/renommer par l'API Files ; créer par `collectives:import:markdown` ; un titre déjà pris (même mis à la corbeille) donne un suffixe `(2)` ; verrous orphelins possibles (`unlock`). Lien de page : `/apps/collectives/<slug>-<id>?fileId=<id>` (slug seul = « collectif introuvable »).
9. **Recherche par sens : sources de bruit à exclure de l'indexation** (leçon de 5 passes, 2026-07-14) : dossiers/fichiers vides, images/photos (l'OCR tourne dessus), contenu de démo Nextcloud, tableurs/CSV. Ollama plante si le batch > 512 : passer `options.num_batch=4096`. ES refuse de changer les `dims` d'un `dense_vector` : nouveau champ `embedding_vector_v2` (1024) ; l'ancien (768) est inutilisé. `embed_backfill.php` ne ré-indexe jamais un document déjà marqué `chunked_v2` même modifié (limite connue). kNN direct sur ES contourne l'ACL : elle est reconstruite à la main (clause `should` owner/users/groups/circles) — toute modif de la recherche doit préserver ça. Ne pas oublier d'ajouter un onglet dans `main.js` à chaque nouveau connecteur.
10. **Persistance** : tout ce qui est installé par `docker exec apt-get` disparaît au recreate → Dockerfile (`nextcloud-image`) ; fichiers hors bind-mount idem.
11. **DMARC** : le spam venait de l'absence de DMARC (DKIM déjà actif) ; enregistrement `_dmarc.adil-tsoulikamal.com` ajouté chez OVH. `[e-mail]` reste injoignable (adresse probablement invalide), problème isolé, pas de l'instance.
12. **Sécurité des connecteurs IA** : pas de mots de passe dans les wikis (indexés par Recherche+ et interrogeables par les assistants externes) → Vaultwarden pour les secrets, wiki « Annuaire des applications » = URLs seulement. Chaque utilisateur s'authentifie avec son propre compte Nextcloud (OAuth2/mot de passe d'application), aucune table de correspondance ; le filtrage par droits est fait DANS la requête.
13. **Nuxeo/Alfresco** : des piles JVM lourdes (Solr, ActiveMQ, LibreOffice bloqué à 100 % CPU pendant 29 h) ont saturé la VM et fait échouer les LLM locaux (timeouts Ollama 5 min). Les arrêter après une évaluation. Nuxeo récent est sous licence Hyland (image privée + CLID).
14. **Homepage vs Dashy** : Dashy retenu (édition intégrée, masquage/affichage) ; Homepage supprimé.
15. **Confusion de projet Compose** : deux dossiers nommés `wordpress` ont fait recréer par erreur les conteneurs d'un autre site (2026-09-18, restauré sans perte). Toujours fixer `-p <nom>`/`name:` dans les compose.
16. **Autorisation** : agir sans demander sur confia-vm (créer/modifier/redémarrer des conteneurs, éditer des configs) est autorisé ; le sudo avec mot de passe reste hors de portée et le mot de passe ne doit jamais être demandé/écrit dans le chat. Rester prudent sur ce qui touche tout le monde (tunnel, `proxy-nginx`, réseau `proxy-net`, Docker networks : la limite du nombre de réseaux a été atteinte le 2026-07-20, nettoyer avec `docker network prune`).
17. **Vérifier l'état réel avant d'écrire une doc/un test** (leçon des sauvegardes : la mémoire disait 03:30/Tailscale, la réalité 03:45/SSH IONOS).

---

## 6. Reste à faire / idées en suspens

Priorité haute
2. **Sauvegarde de Vaultwarden/K3s** (non vérifiée) et **test de restauration Nextcloud** sur VM jetable.
3. **Resynchroniser le dépôt `adilinfo14/nextcloud`** avec le serveur (`search_hub` 1.21.1, serveur MCP distant, Dockerfile) après contrôle anti-secrets.
4. Décider du sort de **Nuxeo/Alfresco** (supprimer conteneurs/volumes/routes Dashy/DNS ou documenter « en veille »).

Priorité moyenne
5. Mise à jour de Nextcloud 32.0.2 → dernière 32.x : d'abord vérifier `tk_theme` (`<max-version>` dans `info.xml`), réappliquer/valider les 2 patchs tiers, prévenir l'utilisateur, fenêtre de maintenance, sauvegarde préalable.
6. Nettoyer les certificats expirés (supprimer les lineages inutiles de `proxy-certbot` ou les passer en DNS-01) pour faire taire l'alerte quotidienne.
7. **Réorganisation de la collective « Intelligence Artificielle »** (fourre-tout à dossiers emoji) : reportée exprès pour être faite avec l'utilisateur. Fusion du lexique IARD dans la collective 9 déjà faite (2026-07-21).
8. Vérifier/poser le moniteur Uptime Kuma sur `https://nextcloud.noschoixpourvous.com/status.php`.
9. Affecter les vrais comptes aux groupes RH/Compta/SI ; désigner un « administrateur délégué » (fonction native prête, non appliquée).
10. Console Recherche+ : le formulaire de paramétrage n'a été testé qu'en backend, clic « Enregistrer » à confirmer dans un navigateur.

Idées (non faites)
11. Assistant à réponses ancrées : vérification temps réel des droits sur les citations, journal d'audit en SQL, icône de navigation, config dans la console. Chiffrement au repos et LDAP/SSO : non activés (décision client-dépendante).
12. Connecteurs Recherche+ supplémentaires ; extension de la recherche par sens (hash de contenu pour ré-indexer les documents modifiés).
13. Tests de bout en bout dans un vrai navigateur : appel Talk réel, envoi/signature LibreSign (vérifiés seulement par CLI le 2026-07-14).
14. Positionnement « alternative Office 365 » : socle prêt (Files+Collabora, Talk, Mail, Calendar, Deck, LibreSign) ; il manque des scénarios de démonstration client.

---

## 7. Reprendre avec Claude

Prompt d'amorçage à coller dans une nouvelle session :

```
Je reprends le projet « Nextcloud TKonsulting & services du homelab » (Adil Tsouli Kamal, TKonsulting).
Lis d'abord le HANDOFF nextcloud-homelab.md (dépôt privé de handoffs) puis vérifie l'état réel en lecture seule :
`ssh confia-vm 'docker ps'`, `curl https://nextcloud.noschoixpourvous.com/status.php`, `tail ~/nextcloud-backups/backup.log`.
Contexte : homelab confia-vm (Tailscale), Nextcloud 32 en conteneur `nextcloud`, nginx `proxy-nginx`, Cloudflare Tunnel PILOTÉ DEPUIS LE DASHBOARD (le config.yml local est ignoré), Dashy sur portail.noschoixpourvous.com, Vaultwarden sur K3s/ArgoCD.
Règles : réponds en français ; aucun secret dans le chat ni dans les fichiers (les mots de passe vivent dans Vaultwarden) ; jamais `occ upgrade` ni redémarrage du tunnel/proxy sans mon accord ; pas de sudo (mot de passe indisponible) ; agis sans demander sur confia-vm pour le reste ; ne teste pas sur les données réelles des utilisateurs.
Commence par me proposer un plan pour les points 1 à 4 de la section « Reste à faire » et attends mon feu vert.
```

---

## 8. Références

Fichiers mémoire (dossier `C:\Users\Utilisateur\.claude\projects\c--Users-Utilisateur-OneDrive-dev-ConfIA\memory\`) : `project_nextcloud_tkonsulting.md` (très détaillé, ~520 lignes, contient l'historique fin ; peut contenir des secrets : ne pas le versionner tel quel), `project_nextcloud_backups.md`, `project_homelab_portail_vaultwarden.md`, `project_confia_cloudflare_tunnel.md` (partiellement obsolète : décrit l'ancien VPS ; le homelab utilise bien cloudflared), `project_confia_cf_tunnel_timeout.md`, `feedback_homelab_autorisation_large.md`, `feedback_rituel_deploiement.md`, `project_sondage_express.md`, `project_talesens.md` (obsolète sur OVH/Astro), `project_tkonsulting.md`, `project_confia_hosting.md`, `project_squash_tm_projets.md`, `project_iaeasy.md`, `project_confia_workplace.md`.
Autres HANDOFF liés : ConfIA/Lesensia (prod IONOS, sauvegardes 03:30), iaeasy / IA Challenge, graphes Neo4j (assurance, CDG Capital, INJAZ — autres rédacteurs), BasketVision, WordPress (chaudronnerie, injaz, beborient).
Dépôts : https://github.com/adilinfo14/nextcloud (public), https://github.com/adilinfo14/argocd-training (GitOps K3s), https://github.com/adilinfo14/TALESENS.

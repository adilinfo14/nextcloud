# Nextcloud TKonsulting — infrastructure as code

Configuration serveur de l'instance Nextcloud auto-hébergée de TKonsulting (branding noir/or, Collabora Online, Talk avec HPB/TURN, collectives IA/ISO 30401, recherche plein texte maison).

**⚠️ Tous les secrets (mots de passe DB, clés TURN/signaling) ont été remplacés par des placeholders `CHANGE_ME_...` ou des variables d'environnement `${...}` avant ce commit — ce dépôt est public.** Les vraies valeurs vivent uniquement sur les serveurs et dans le gestionnaire de mots de passe de l'utilisateur.

## Structure

```
homelab/                    Config du serveur homelab (VM VMware) qui héberge Nextcloud
├── docker-compose.yml      Nextcloud (build local avec Tesseract OCR) + MariaDB + Redis + notify_push
├── nextcloud-image/
│   └── Dockerfile          Image nextcloud:latest + Tesseract OCR + Ghostscript (necessaire
│                            pour l'OCR des PDF/images scannes indexes par la recherche)
├── nginx/                  Vhosts du reverse proxy (proxy-nginx)
│   ├── nextcloud.conf      Vhost principal, support WebSocket (Collabora/Talk)
│   ├── signaling.conf      Vhost du serveur de signalisation Talk (HPB)
│   └── 00-websocket-map.conf
├── signaling/              Serveur de signalisation Talk (nextcloud-spreed-signaling)
│   ├── docker-compose.yml
│   └── server.conf         Secrets à régénérer (voir commentaires dans le fichier)
├── cloudflared/
│   └── config.yml          ⚠️ Référence historique seulement — ce tunnel utilise désormais
│                            une configuration DISTANTE gérée depuis le dashboard Cloudflare
│                            (Zero Trust > Networks > Connectors > homelab > Published
│                            application routes), ce fichier local n'est plus la source de vérité
├── custom_apps_tk_theme/                    App custom : thème noir/or (CSS additif)
├── custom_apps_search_hub/                   App custom : "Recherche+", page de recherche
│                                              maison (onglets par source, filtres, extraits
│                                              surlignes, score pondere configurable) + son
│                                              propre tableau de bord admin. REQUIERT que la
│                                              recherche plein texte (voir plus bas) soit deja
│                                              configuree et indexee — ce n'est qu'une interface,
│                                              pas un moteur de recherche autonome.
└── custom_apps_fulltextsearch_collectives/   App custom : connecteur ajoutant les pages du wiki
                                               Collectives a l'index de recherche plein texte
                                               (aucun connecteur officiel n'existe pour ça)

ionos-turn/                 Config du serveur TURN (coturn), hébergé sur le VPS IONOS
├── docker-compose.yml      network_mode: host (indispensable pour la plage de ports relais)
└── turnserver.conf         Secret à régénérer (voir commentaire dans le fichier)
```

## Recherche plein texte (Elasticsearch)

Ce dépôt ne contient **pas encore** l'automatisation complète du déploiement d'Elasticsearch
(chantier à part, pas encore fait — actuellement une installation manuelle sur le serveur).
Prérequis pour que les apps `custom_apps_search_hub` et `custom_apps_fulltextsearch_collectives`
fonctionnent :
1. Un conteneur Elasticsearch 8.x accessible depuis le réseau Docker de Nextcloud
2. Apps Nextcloud : `fulltextsearch`, `fulltextsearch_elasticsearch`, `files_fulltextsearch`
   (+ `files_fulltextsearch_tesseract` pour l'OCR des PDF/images scannés — nécessite l'image
   custom `nextcloud-image/Dockerfile` de ce dépôt)
3. `occ fulltextsearch:configure` + `occ config:app:set fulltextsearch app_navigation --value=true --type=boolean`
4. Un premier `occ fulltextsearch:index` pour indexer l'historique existant

## Architecture Talk (HPB + TURN)

- **coturn** (TURN/STUN) sur le VPS IONOS — IP publique directe, pas de NAT, contrairement au homelab
- **nextcloud-spreed-signaling** (HPB) sur le homelab, à côté de Nextcloud
- Routage : `signaling.noschoixpourvous.com` → Cloudflare Tunnel → nginx → conteneur `signaling:8080`
- Config Nextcloud : `occ talk:turn:add` + `occ talk:signaling:add`

## Régénérer les secrets

```bash
# hashkey / internalsecret / secret backend (64 caractères hex)
openssl rand -hex 32

# blockkey (DOIT faire exactement 32 caractères — 16 octets)
openssl rand -hex 16

# secret TURN partagé (coturn + occ talk:turn:add)
openssl rand -hex 32
```

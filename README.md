# Nextcloud TKonsulting — infrastructure as code

Configuration serveur de l'instance Nextcloud auto-hébergée de TKonsulting (branding noir/or, Collabora Online, Talk avec HPB/TURN, collectives IA/ISO 30401).

**⚠️ Tous les secrets (mots de passe DB, clés TURN/signaling) ont été remplacés par des placeholders `CHANGE_ME_...` ou des variables d'environnement `${...}` avant ce commit — ce dépôt est public.** Les vraies valeurs vivent uniquement sur les serveurs et dans le gestionnaire de mots de passe de l'utilisateur.

## Structure

```
homelab/                    Config du serveur homelab (VM VMware) qui héberge Nextcloud
├── docker-compose.yml      Nextcloud + MariaDB (mots de passe via variables d'env)
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
└── custom_apps_tk_theme/   App Nextcloud custom (thème noir/or, CSS additif)

ionos-turn/                 Config du serveur TURN (coturn), hébergé sur le VPS IONOS
├── docker-compose.yml      network_mode: host (indispensable pour la plage de ports relais)
└── turnserver.conf         Secret à régénérer (voir commentaire dans le fichier)
```

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

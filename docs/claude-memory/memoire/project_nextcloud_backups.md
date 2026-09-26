---
name: project_nextcloud_backups
description: "Sauvegarde quotidienne Nextcloud (DB + donnees) vers le VPS IONOS via SSH, distincte de la sauvegarde ConfIA"
metadata: 
  node_type: memory
  type: project
  originSessionId: 35304672-3991-434e-a73b-938e0019a4ae
---

Script `/home/adil/nextcloud-backup.sh` sur confia-vm, cron **03h45** (pas 03h30 — à ne pas confondre avec [[project_confia_backups]] qui est un job séparé pour l'app ConfIA).

- Dump DB (`mariadb-dump` sur `projet_nextcloud-db-1`) + archive des données utilisateurs (`docker exec -u www-data nextcloud tar`), compressés en local (`/home/adil/nextcloud-backups`).
- Envoyés via SSH/SCP (compte dédié `ncbackup`, clé `~/.ssh/id_ed25519_backup_ionos`) vers le **VPS IONOS** (`[IP-VPS]`, le même serveur que la prod ConfIA), chemin distant `/home/ncbackup/backups/nextcloud-tkonsulting/`.
- **Pas de Tailscale ici** — c'est du SSH classique vers IONOS, pas rsync vers un homelab.
- Rétention : **14 jours pour la DB, 7 jours pour les données** (purge locale ET distante par âge de fichier via `find -mtime`).

**Découvert le 2026-07-22** en calibrant des cas de test Squash TM sur la config réelle plutôt qu'une hypothèse — bon réflexe à généraliser : toujours vérifier l'état réel avant d'écrire un cas de test ou une doc, ne pas supposer.

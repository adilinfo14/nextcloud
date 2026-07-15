# MCP Nextcloud Search

Serveur MCP qui expose la recherche par sens filtrée par droits de **Recherche+**
(Nextcloud TKonsulting) comme un outil utilisable directement depuis **Claude
Desktop** ou **Claude Code**, sans passer par l'interface web de l'assistant.

Claude fait sa propre lecture/synthèse des passages retournés — ce serveur ne fait
que la partie non-négociable : la recherche **filtrée par les droits réels de
l'utilisateur**, jamais un document hors de son périmètre.

## Pourquoi un mot de passe d'application, pas votre mot de passe normal

Chaque utilisateur doit configurer **son propre** mot de passe d'application - jamais
un compte partagé. C'est ce qui garantit que les résultats retournés à Claude
respectent VOS droits, pas ceux d'un compte générique.

## Installation

1. **Créer un mot de passe d'application** (jamais votre mot de passe de connexion) :
   Nextcloud → cliquer sur votre avatar (en haut à droite) → **Paramètres** →
   **Sécurité** → section "Mots de passe d'application" → donnez-lui un nom (ex.
   `Claude Desktop`) → **Créer un nouveau mot de passe d'application** → copiez le
   mot de passe généré (il ne sera plus jamais affiché après).

2. **Installer Node.js** (si pas déjà fait) : [nodejs.org](https://nodejs.org),
   version 18 ou plus récente (nécessaire pour `fetch` natif).

3. **Installer les dépendances** :
   ```
   cd mcp-nextcloud-search
   npm install
   ```

4. **Configurer Claude Desktop** — éditez (ou créez) le fichier de config :
   - Windows : `%APPDATA%\Claude\claude_desktop_config.json`
   - macOS : `~/Library/Application Support/Claude/claude_desktop_config.json`

   Ajoutez (en adaptant le chemin vers ce dossier et vos identifiants) :
   ```json
   {
     "mcpServers": {
       "nextcloud-recherche-plus": {
         "command": "node",
         "args": ["C:/chemin/vers/mcp-nextcloud-search/index.js"],
         "env": {
           "NEXTCLOUD_URL": "https://nextcloud.noschoixpourvous.com",
           "NEXTCLOUD_USER": "votre-identifiant",
           "NEXTCLOUD_APP_PASSWORD": "le-mot-de-passe-genere-a-l-etape-1"
         }
       }
     }
   }
   ```

5. **Redémarrer Claude Desktop** entièrement (pas juste fermer la fenêtre). L'outil
   `search_documents` doit apparaître dans la liste des outils disponibles (icône
   🔨 dans la conversation).

## Vérifier que ça marche

Posez une question à Claude sur un sujet que vous savez présent dans vos documents
Nextcloud (ex. "cherche dans mes documents ce que dit le wiki sur X"). Claude doit
invoquer l'outil `search_documents` avant de répondre, et citer les documents
réellement retournés.

## Sécurité

- Le mot de passe d'application peut être révoqué à tout moment depuis Nextcloud
  (Paramètres → Sécurité → liste des sessions actives) sans toucher à votre mot de
  passe principal.
- Ce serveur tourne **en local sur votre machine** - il ne fait que relayer vos
  questions vers Nextcloud avec votre identité, rien n'est envoyé ailleurs.

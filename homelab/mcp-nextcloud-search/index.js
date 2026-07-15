#!/usr/bin/env node
/**
 * Serveur MCP - expose la recherche par sens filtree par droits de Recherche+
 * (Nextcloud TKonsulting) comme un outil que Claude Desktop / Claude Code peut
 * appeler directement dans une conversation normale.
 *
 * Ne fait AUCUNE generation de reponse lui-meme - Claude, cote client, lit les
 * passages retournes et redige sa propre reponse. Ce serveur ne fait que la partie
 * non-negociable : la recherche filtree par droits (jamais un document hors des
 * droits de l'utilisateur configure ici).
 *
 * Authentification : mot de passe d'application Nextcloud (PAS le mot de passe de
 * connexion normal) - chaque utilisateur doit configurer LE SIEN, jamais un compte
 * partage, pour que le filtrage par droits s'applique a lui personnellement.
 *
 * Configuration (variables d'environnement, voir claude_desktop_config.json) :
 *   NEXTCLOUD_URL           ex: https://nextcloud.noschoixpourvous.com
 *   NEXTCLOUD_USER           votre identifiant Nextcloud
 *   NEXTCLOUD_APP_PASSWORD   mot de passe d'application (Parametres > Securite)
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL;
const NEXTCLOUD_USER = process.env.NEXTCLOUD_USER;
const NEXTCLOUD_APP_PASSWORD = process.env.NEXTCLOUD_APP_PASSWORD;

if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_APP_PASSWORD) {
	console.error(
		'Variables d\'environnement manquantes : NEXTCLOUD_URL, NEXTCLOUD_USER, NEXTCLOUD_APP_PASSWORD requises.'
	);
	process.exit(1);
}

const server = new McpServer({
	name: 'nextcloud-recherche-plus',
	version: '1.0.0',
});

server.tool(
	'search_documents',
	'Cherche dans les documents Nextcloud de l\'utilisateur (Fichiers, Wiki, Deck) ' +
		'par sens (pas juste mot-cle exact) - ne retourne QUE les documents auxquels ' +
		'l\'utilisateur authentifie a reellement acces. IMPORTANT : reponds UNIQUEMENT ' +
		'a partir des passages retournes par cet outil - ne complete jamais avec tes ' +
		'connaissances generales. Si aucun passage pertinent n\'est retourne, dis ' +
		'explicitement que tu n\'as rien trouve dans les documents accessibles plutot ' +
		'que d\'inventer une reponse. Cite le titre du document source pour chaque ' +
		'affirmation.',
	{
		question: z.string().max(500).describe('La question ou le sujet a rechercher dans les documents'),
	},
	async ({ question }) => {
		const url = NEXTCLOUD_URL.replace(/\/$/, '') + '/apps/search_hub/api/assistant/mcp-search';
		const auth = Buffer.from(`${NEXTCLOUD_USER}:${NEXTCLOUD_APP_PASSWORD}`).toString('base64');

		let response;
		try {
			response = await fetch(url, {
				method: 'POST',
				headers: {
					'Authorization': `Basic ${auth}`,
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({ question }).toString(),
			});
		} catch (err) {
			return {
				content: [{ type: 'text', text: `Erreur de connexion a Nextcloud : ${err.message}` }],
				isError: true,
			};
		}

		if (!response.ok) {
			return {
				content: [{ type: 'text', text: `Nextcloud a repondu avec une erreur (HTTP ${response.status}) - verifiez le mot de passe d'application.` }],
				isError: true,
			};
		}

		const data = await response.json();

		if (!data.found || !data.passages || data.passages.length === 0) {
			return {
				content: [{ type: 'text', text: 'AUCUN document pertinent trouve dans les documents accessibles a cet utilisateur. Ne pas inventer de reponse - dire explicitement qu\'aucune information n\'a ete trouvee.' }],
			};
		}

		const formatted = data.passages
			.map((p, i) => `[Document ${i + 1}: ${p.title}]\nLien : ${p.link}\n${p.text.slice(0, 1500)}`)
			.join('\n\n---\n\n');

		return {
			content: [{ type: 'text', text: formatted }],
		};
	}
);

const transport = new StdioServerTransport();
await server.connect(transport);

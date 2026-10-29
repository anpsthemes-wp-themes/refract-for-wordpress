#!/usr/bin/env node
/**
 * Rewrites the networks list in readme.txt, between `<!-- networks -->` and `<!-- /networks -->`, from Refract's
 * public availability map (`GET /networks.json`: `[{network, label, availability}]`, availability `available`,
 * `beta` or `soon`). Runs in the release job before the deploy, so the directory listing names the networks as they
 * are on the day of the release. WordPress itself is left out: the readme is about the networks a blog post goes to.
 *
 * Usage: `node bin/readme-networks.mjs [readme path]` (default: the readme.txt next to bin/). The base URL is
 * `REFRACT_URL` (default https://refract.social), e.g. a local Refract or a fixture server.
 * Exits non-zero, and leaves the readme alone, when the list can't be fetched or doesn't look right.
 */
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const START = '<!-- networks -->';
const END = '<!-- /networks -->';
const STATES = ['available', 'beta', 'soon'];

const readmePath = process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '..', 'readme.txt');

/**
 * Refract's availability map.
 *
 * @return {Promise<unknown>}
 */
async function load() {
	const base = (process.env.REFRACT_URL || 'https://refract.social').replace(/\/+$/, '');
	const url = `${base}/networks.json`;
	const response = await fetch(url, {
		headers: { Accept: 'application/json' },
		signal: AbortSignal.timeout(15_000),
	});

	if (!response.ok) {
		throw new Error(`${url} answered ${response.status} ${response.statusText}.`);
	}

	return response.json();
}

/**
 * "A", "A and B", "A, B and C".
 *
 * @param {string[]} labels
 * @return {string}
 */
function joinLabels(labels) {
	return labels.length < 2 ? labels.join('') : `${labels.slice(0, -1).join(', ')} and ${labels.at(-1)}`;
}

/**
 * The lines between the markers: the networks you can connect, then Beta and Coming soon when there are any.
 *
 * @param {unknown} networks
 * @return {string}
 */
function render(networks) {
	if (!Array.isArray(networks) || networks.length === 0) {
		throw new Error('The networks list is empty or not a list.');
	}

	const groups = { available: [], beta: [], soon: [] };

	for (const item of networks) {
		if (!item || typeof item.network !== 'string' || typeof item.label !== 'string' || !STATES.includes(item.availability)) {
			throw new Error(`Unexpected entry in the networks list: ${JSON.stringify(item)}`);
		}

		if (item.network !== 'wordpress') {
			groups[item.availability].push(item.label);
		}
	}

	if (groups.available.length === 0) {
		throw new Error('No network is available; refusing to write that into the readme.');
	}

	const lines = [`Networks you can connect: ${joinLabels(groups.available)}.`];

	if (groups.beta.length > 0) {
		const note =
			groups.beta.length === 1
				? "You can connect it now, but some accounts can't connect yet while the network reviews Refract."
				: "You can connect them now, but some accounts can't connect yet while the networks review Refract.";

		lines.push('', `In Beta: ${joinLabels(groups.beta)}. ${note}`);
	}

	if (groups.soon.length > 0) {
		lines.push('', `Coming soon: ${joinLabels(groups.soon)}.`);
	}

	return lines.join('\n');
}

try {
	const readme = await readFile(readmePath, 'utf8');
	const start = readme.indexOf(START);
	const end = readme.indexOf(END);

	if (start === -1 || end === -1 || end < start) {
		throw new Error(`${readmePath} has no ${START} … ${END} block.`);
	}

	const block = render(await load());
	const updated = `${readme.slice(0, start + START.length)}\n${block}\n${readme.slice(end)}`;

	await writeFile(readmePath, updated);
	console.log(block);
} catch (error) {
	const cause = error?.cause instanceof Error ? ` (${error.cause.message})` : '';
	console.error(`readme-networks: ${error instanceof Error ? error.message : error}${cause}`);
	process.exit(1);
}

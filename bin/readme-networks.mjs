#!/usr/bin/env node
/**
 * Rewrites the networks list in readme.txt, between `<!-- networks -->` and `<!-- /networks -->`, from Refract's
 * public availability map (`GET /networks.json`: `[{network, label, availability}]`, availability `available`,
 * `beta` or `soon`). Runs in the release job before the deploy, so the directory listing names the networks as they
 * are on the day of the release. WordPress itself is left out: the readme is about the networks a blog post goes to.
 *
 * Usage: `node bin/readme-networks.mjs [readme path]` (default: the readme.txt next to bin/). The base URL is
 * `REFRACT_URL` (default https://refract.social), e.g. a local Refract or a fixture server.
 * Exits non-zero, and leaves the readme alone, when the list can't be fetched or doesn't look right, or when the rest
 * of the readme (the short description, an example, the FAQ) names a network that's Coming soon: only the "Coming
 * soon:" line may. The changelog is history and isn't checked. Tested by bin/readme-networks.test.mjs.
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
 * The lines between the markers (the networks you can connect, then Beta and Coming soon when there are any) and the
 * labels of the networks coming soon.
 *
 * @param {unknown} networks
 * @return {{block: string, soon: string[]}}
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

	return { block: lines.join('\n'), soon: groups.soon };
}

/**
 * The Coming soon networks the readme names outside the networks block, before its changelog. "X card" is the preview
 * tag's name, not the network.
 *
 * @param {string}   readme
 * @param {string[]} soon
 * @return {string[]}
 */
function soonMentions(readme, soon) {
	const text = (readme.slice(0, readme.indexOf(START)) + readme.slice(readme.indexOf(END) + END.length))
		.split('== Changelog ==')[0]
		.replaceAll('X card', '');

	return soon.filter((label) => new RegExp(`\\b${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`).test(text));
}

try {
	const readme = await readFile(readmePath, 'utf8');
	const start = readme.indexOf(START);
	const end = readme.indexOf(END);

	if (start === -1 || end === -1 || end < start) {
		throw new Error(`${readmePath} has no ${START} … ${END} block.`);
	}

	const { block, soon } = render(await load());
	const updated = `${readme.slice(0, start + START.length)}\n${block}\n${readme.slice(end)}`;
	const mentioned = soonMentions(updated, soon);

	if (mentioned.length > 0) {
		throw new Error(`The readme names ${joinLabels(mentioned)} outside the networks list, but ${mentioned.length === 1 ? "it's" : "they're"} Coming soon.`);
	}

	await writeFile(readmePath, updated);
	console.log(block);
} catch (error) {
	const cause = error?.cause instanceof Error ? ` (${error.cause.message})` : '';
	console.error(`readme-networks: ${error instanceof Error ? error.message : error}${cause}`);
	process.exit(1);
}

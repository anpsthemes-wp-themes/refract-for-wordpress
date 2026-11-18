/**
 * `node --test bin/readme-networks.test.mjs`: readme-networks.mjs against a fixture /networks.json, on a copy of the
 * real readme.txt. The list says only live (and Beta) networks are ones you can connect, puts Coming soon ones on their
 * own line, leaves WordPress out, and the release stops when the readme names a Coming soon network anywhere else.
 */
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { copyFile, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
// Node's own runner: the release job has no Vitest, and this needs nothing else.
// eslint-disable-next-line vitest/no-import-node-test
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const BIN = dirname( fileURLToPath( import.meta.url ) );
const NETWORKS = [
	{ network: 'instagram', label: 'Instagram', availability: 'soon' },
	{ network: 'bluesky', label: 'Bluesky', availability: 'available' },
	{ network: 'mastodon', label: 'Mastodon', availability: 'available' },
	{ network: 'pinterest', label: 'Pinterest', availability: 'available' },
	{ network: 'telegram', label: 'Telegram', availability: 'available' },
	{ network: 'threads', label: 'Threads', availability: 'beta' },
	{ network: 'wordpress', label: 'WordPress', availability: 'available' },
	{
		network: 'google_business',
		label: 'Google Business Profile',
		availability: 'soon',
	},
];

let server;
let url;

before( async () => {
	server = createServer( ( request, response ) => {
		response.writeHead( 200, { 'Content-Type': 'application/json' } );
		response.end( JSON.stringify( NETWORKS ) );
	} );
	await new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', resolve )
	);
	url = `http://127.0.0.1:${ server.address().port }`;
} );

after( () => server.close() );

/**
 * Runs the script on a copy of readme.txt, optionally changed first.
 *
 * @param {(readme: string) => string} [change]
 * @return {Promise<{code: number, readme: string, original: string, stderr: string}>} The exit code, the readme after
 *         the run, the readme before it and the script's error output.
 */
async function run( change = ( readme ) => readme ) {
	const path = join(
		await mkdtemp( join( tmpdir(), 'readme-networks-' ) ),
		'readme.txt'
	);
	await copyFile( join( BIN, '..', 'readme.txt' ), path );
	const original = change( await readFile( path, 'utf8' ) );
	await writeFile( path, original );

	try {
		await promisify( execFile )(
			process.execPath,
			[ join( BIN, 'readme-networks.mjs' ), path ],
			{ env: { ...process.env, REFRACT_URL: url } }
		);

		return {
			code: 0,
			readme: await readFile( path, 'utf8' ),
			original,
			stderr: '',
		};
	} catch ( error ) {
		return {
			code: error.code,
			readme: await readFile( path, 'utf8' ),
			original,
			stderr: error.stderr,
		};
	}
}

test( 'the networks you can connect are only the live ones, without WordPress; Coming soon has its own line', async () => {
	const { code, readme } = await run();
	const block = readme.slice(
		readme.indexOf( '<!-- networks -->' ),
		readme.indexOf( '<!-- /networks -->' )
	);

	assert.equal( code, 0 );
	assert.match(
		block,
		/^Networks you can connect: Bluesky, Mastodon, Pinterest and Telegram\.$/m
	);
	assert.match( block, /^In Beta: Threads\. /m );
	assert.match(
		block,
		/^Coming soon: Instagram and Google Business Profile\.$/m
	);
	assert.doesNotMatch( block, /WordPress/ );
} );

test( 'a Coming soon network named outside the list stops the release and leaves the readme alone', async () => {
	const { code, readme, original, stderr } = await run( ( text ) =>
		text.replace( '"12 clicks from Bluesky"', '"12 clicks from Instagram"' )
	);

	assert.equal( code, 1 );
	assert.match(
		stderr,
		/names Instagram outside the networks list, but it's Coming soon/
	);
	assert.equal( readme, original );
} );

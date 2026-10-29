/**
 * Helpers for the specs: the mock Refract's test routes, delivering its
 * signed requests to the plugin, running cron, and directory screenshots.
 */
const { execFileSync } = require( 'child_process' );
const { expect } = require( '@wordpress/e2e-test-utils-playwright' );
const path = require( 'path' );

const PACKAGE = path.resolve( __dirname, '../..' );
const FIXTURES = path.join( __dirname, 'fixtures' );

/**
 * The accounts the mock workspace has, by id.
 */
const ACCOUNTS = {
	instagram: 'acc-ig-kolo',
	roastery: 'acc-ig-roastery',
	facebook: 'acc-fb-kolo',
	linkedin: 'acc-li-kolo',
	bluesky: 'acc-bsky-kolo',
	threads: 'acc-threads-kolo',
};

/**
 * Calls one of the mock's test routes (`refract-mock/v1/…`).
 *
 * @param {Object} requestUtils RequestUtils.
 * @param {string} route        Route, e.g. `reset`.
 * @param {Object} [data]       JSON body; GET when omitted for `log`/`inspect`.
 * @return {Promise<Object>} The response.
 */
function mock( requestUtils, route, data ) {
	const isGet = route === 'log' || route.startsWith( 'inspect/' );

	return requestUtils.rest( {
		method: isGet ? 'GET' : 'POST',
		path: `/refract-mock/v1/${ route }`,
		...( isGet ? {} : { data: data || {} } ),
	} );
}

/**
 * Forgets everything (plugin data, mock state, posts and media).
 *
 * @param {Object} requestUtils RequestUtils.
 */
async function reset( requestUtils ) {
	await mock( requestUtils, 'reset' );
	await requestUtils.deleteAllPosts();
	await requestUtils.deleteAllMedia();
}

/**
 * Connects through the token method and saves settings, for specs that start
 * connected.
 *
 * @param {Object} requestUtils RequestUtils.
 * @param {Object} [settings]   Settings to save.
 */
function connect( requestUtils, settings = {} ) {
	return mock( requestUtils, 'connect', { settings } );
}

/**
 * Delivers what the mock queued (webhook events, posts) to the plugin over
 * HTTP, signed with the site's signing secret, without the admin's cookies.
 *
 * @param {Object} requestUtils RequestUtils.
 * @param {Object} playwright   The `playwright` fixture.
 * @return {Promise<Array<{status: number, body: Object}>>} The plugin's answers.
 */
async function deliver( requestUtils, playwright ) {
	const outbox = await mock( requestUtils, 'outbox' );
	const context = await playwright.request.newContext();
	const results = [];

	for ( const item of outbox ) {
		const response = await context.post( item.url, {
			data: item.body,
			headers: item.headers,
		} );

		results.push( {
			status: response.status(),
			body: await response.json(),
			item,
		} );
	}

	await context.dispose();

	return results;
}

/**
 * Runs the plugin's scheduled sends now, through WP-CLI in the tests
 * environment.
 */
function runCron() {
	execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'tests-cli',
			'wp',
			'cron',
			'event',
			'run',
			'refract_send_post',
		],
		{ cwd: PACKAGE, stdio: 'pipe' }
	);
}

/**
 * Uploads a test image with alt text.
 *
 * @param {Object} requestUtils RequestUtils.
 * @param {string} name         File in tests/e2e/fixtures.
 * @param {string} alt          Alt text.
 * @return {Promise<Object>} The attachment.
 */
async function uploadImage( requestUtils, name, alt ) {
	const media = await requestUtils.uploadMedia( path.join( FIXTURES, name ) );

	return requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/media/${ media.id }`,
		data: { alt_text: alt },
	} );
}

/**
 * Opens the Refract sidebar in the block editor.
 *
 * @param {Object} page Page.
 * @return {Object} The sidebar.
 */
async function openSidebar( page ) {
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core', 'refract/refract-sidebar' )
	);

	const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
	await expect(
		sidebar.getByRole( 'heading', { name: 'Refract', exact: true } )
	).toBeVisible();

	return sidebar;
}

/**
 * Writes a directory screenshot to .wordpress-org when REFRACT_SCREENSHOTS
 * is set (npm run screenshots).
 *
 * @param {Object} page   Page.
 * @param {number} number Screenshot number.
 */
async function directoryScreenshot( page, number ) {
	if ( ! process.env.REFRACT_SCREENSHOTS ) {
		return;
	}

	await page.screenshot( {
		path: path.join( PACKAGE, `.wordpress-org/screenshot-${ number }.png` ),
		animations: 'disabled',
		caret: 'hide',
	} );
}

module.exports = {
	ACCOUNTS,
	FIXTURES,
	connect,
	deliver,
	directoryScreenshot,
	mock,
	openSidebar,
	reset,
	runCron,
	uploadImage,
};

/**
 * Connecting to (mock) Refract: OAuth in three clicks, the first-run setup,
 * and the token method.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	ACCOUNTS,
	connect,
	directoryScreenshot,
	mock,
	reset,
} = require( '../utils' );

test.describe( 'Connecting to Refract', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
	} );

	test( 'connects with OAuth in three clicks', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=refract' );
		await expect(
			page.getByRole( 'heading', {
				name: 'Connect your site to Refract',
			} )
		).toBeVisible();

		// 1. Connect to Refract → Refract's consent screen.
		await page
			.getByRole( 'button', { name: 'Connect to Refract' } )
			.click();
		await expect(
			page.getByRole( 'heading', {
				name: 'Connect Kolo Coffee to Refract',
			} )
		).toBeVisible();
		expect( new URL( page.url() ).pathname ).toBe(
			'/refract-mock/oauth/authorize'
		);
		await expect( page.getByLabel( 'Kolo Coffee' ) ).toBeChecked();

		// 2. Allow → back on the site, connected, on the setup.
		await page.getByRole( 'button', { name: 'Allow' } ).click();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText( 'Your site is connected to Refract.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Set up Refract' } )
		).toBeVisible();
		await expect( page.getByLabel( /Kolo Roastery/ ) ).toBeChecked();

		// 3. Save and finish → the settings.
		await page.getByRole( 'button', { name: 'Save and finish' } ).click();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText( 'Connected to Kolo Coffee as ana@kolo.coffee' )
		).toBeVisible();

		const log = await mock( requestUtils, 'log' );
		const paths = log.map(
			( entry ) => `${ entry.method } ${ entry.path }`
		);
		expect( paths ).toEqual(
			expect.arrayContaining( [
				'POST /oauth/register',
				'POST /oauth/token',
				'POST /api/v1/workspaces/ws-kolo/accounts/wordpress',
			] )
		);
		const site = log.find( ( entry ) =>
			entry.path.endsWith( '/accounts/wordpress' )
		);
		expect( site.body.site_name ).toBe( 'Kolo Coffee' );
		expect( site.body.signing_secret ).toMatch( /^[A-Za-z0-9+/]{43}=$/ );
		expect( site.body.events_url ).toBe(
			'http://localhost:8889/wp-json/refract/v1/events'
		);
	} );

	test( 'first-run setup groups accounts and saves the choices', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await connect( requestUtils, { setup_done: false, accounts: [] } );
		await admin.visitAdminPage( 'admin.php', 'page=refract' );

		const setup = page.locator( '.refract-setup' );
		await expect(
			setup.getByRole( 'heading', { name: 'Set up Refract' } )
		).toBeVisible();

		// Grouped by network, the main channel's network first with a star.
		const networks = setup.locator( '.refract-accounts__network' );
		await expect( networks.first() ).toHaveText( 'Instagram' );
		await expect( networks ).toHaveText( [
			'Instagram',
			'Facebook',
			'LinkedIn',
			'Bluesky',
			/^ThreadsBeta$/,
			/^Google Business Profile/,
		] );
		const instagram = setup
			.locator( '.refract-accounts__group' )
			.filter( { hasText: 'Instagram' } );
		await expect(
			instagram.locator( '.components-checkbox-control' ).first()
		).toContainText( 'Kolo Coffee @kolocoffee ★' );
		await expect(
			setup
				.locator( '.refract-accounts__group' )
				.filter( { hasText: 'Threads' } )
				.locator( '.refract-tag' )
		).toHaveText( 'Beta' );

		// Everything that can get a version is chosen; "soon" can't be.
		await expect(
			page.getByLabel( /Kolo Coffee Ljubljana/ )
		).toBeDisabled();
		await expect( page.getByLabel( /Kolo Coffee Roasters/ ) ).toBeChecked();
		await expect(
			page.getByLabel( 'When a post is published' )
		).toBeChecked();

		await directoryScreenshot( page, 1 );

		await page.getByLabel( /Kolo Roastery/ ).uncheck();
		await page.getByLabel( 'Only when I choose, from the editor' ).check();
		await page.getByRole( 'button', { name: 'Save and finish' } ).click();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText( 'Connected to Kolo Coffee as ana@kolo.coffee' )
		).toBeVisible();

		const state = await requestUtils.rest( {
			path: '/refract-admin/v1/state',
		} );
		expect( state.settings.setup_done ).toBe( true );
		expect( state.settings.send_mode ).toBe( 'manual' );
		expect( [ ...state.settings.accounts ].sort() ).toEqual(
			[
				ACCOUNTS.instagram,
				ACCOUNTS.facebook,
				ACCOUNTS.linkedin,
				ACCOUNTS.bluesky,
				ACCOUNTS.threads,
			].sort()
		);

		// The saved choices survive a reload.
		await page.reload();
		await expect(
			page.getByLabel( 'Only when I choose, from the editor' )
		).toBeChecked();
		await expect(
			page.locator( '.refract-admin__page' )
		).not.toContainText( 'Set up Refract' );

		await directoryScreenshot( page, 2 );
	} );

	test( 'connects with a pasted API token', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=refract' );
		await page
			.getByRole( 'button', { name: 'Connect with a token instead' } )
			.click();

		const token = page.getByLabel( 'API token' );
		const submit = page.getByRole( 'button', {
			name: 'Connect with this token',
		} );

		await token.fill( 'rf_test_not_a_refract_token' );
		await submit.click();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText(
					"Refract didn't accept that token. Check that you copied all of it and that it hasn't expired."
				)
		).toBeVisible();

		await token.fill( 'rf_live_kolo_pasted_token' );
		await submit.click();
		await expect(
			page.getByRole( 'heading', { name: 'Set up Refract' } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Save and finish' } ).click();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText( 'Connected to Kolo Coffee as ana@kolo.coffee' )
		).toBeVisible();
	} );
} );

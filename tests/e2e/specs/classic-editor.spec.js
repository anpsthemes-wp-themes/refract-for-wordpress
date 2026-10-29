/**
 * The Refract meta box in the Classic Editor: the switch and the accounts,
 * then the versions panel once the post is sent.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { ACCOUNTS, connect, mock, reset, runCron } = require( '../utils' );

test.describe( 'Classic Editor meta box', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'classic-editor' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'classic-editor' );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
		await connect( requestUtils );
	} );

	test( 'chooses accounts, sends on publish and shows the versions', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'post-new.php' );

		const box = page.locator( '#refract' );
		await expect( box.locator( 'h2' ) ).toHaveText( 'Refract' );
		await expect( box.getByLabel( 'Send to Refract' ) ).toBeChecked();
		await expect(
			box.getByLabel( 'Instagram · @kolocoffee' )
		).toBeChecked();
		await expect( box.locator( '.description' ).first() ).toHaveText(
			'5 versions · 5 credits'
		);

		await box.getByLabel( 'Bluesky · @kolo.coffee' ).uncheck();
		await page
			.locator( '#title' )
			.fill( 'Cupping notes: three new Ethiopians' );
		await page.locator( '#content-html' ).click();
		await page
			.locator( '#content' )
			.fill(
				'We cupped three washed Ethiopians on Tuesday. The Guji was the table’s favourite: jasmine, peach and black tea.'
			);
		await page.locator( '#publish' ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Post published.'
		);

		const postId = Number(
			new URL( page.url() ).searchParams.get( 'post' )
		);
		expect(
			( await mock( requestUtils, `inspect/${ postId }` ) ).scheduled
		).toBe( true );

		runCron();

		const notes = ( await mock( requestUtils, 'log' ) ).filter(
			( entry ) => entry.path === '/api/v1/workspaces/ws-kolo/notes'
		);
		expect( notes ).toHaveLength( 1 );
		expect( notes[ 0 ].body.accounts ).not.toContain( ACCOUNTS.bluesky );
		expect( notes[ 0 ].body.accounts ).toHaveLength( 5 );

		await page.reload();
		await expect( box ).toContainText( 'Sent to Refract.' );
		const versions = box.locator( '#refract-classic .refract-version' );
		await expect( versions ).toHaveCount( 5 );
		await expect( versions.first() ).toContainText( 'Writing…' );

		await mock( requestUtils, 'advance', { to: 'written' } );
		await page.reload();
		await expect( versions.first() ).toContainText( 'Needs your OK' );
		await box
			.locator( '.refract-version' )
			.filter( { hasText: 'Kolo Coffee Roasters' } )
			.getByRole( 'button', { name: 'Approve', exact: true } )
			.click();
		await expect(
			box
				.locator( '.refract-version' )
				.filter( { hasText: 'Kolo Coffee Roasters' } )
		).toContainText( 'Approved' );
	} );
} );

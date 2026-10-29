/**
 * Link previews: Refract prints social preview tags, unless an SEO plugin
 * (here a fake Yoast SEO) already does.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { connect, reset, uploadImage } = require( '../utils' );

test.describe( 'Link previews', () => {
	let link;

	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
		await connect( requestUtils, { send_mode: 'manual' } );

		const latte = await uploadImage(
			requestUtils,
			'kolo-latte.jpg',
			'A latte on the bar'
		);
		const post = await requestUtils.createPost( {
			title: 'Oat milk is now free',
			content:
				'<!-- wp:paragraph --><p>From Monday, oat milk costs nothing extra in every Kolo shop.</p><!-- /wp:paragraph -->',
			status: 'publish',
			featured_media: latte.id,
		} );
		link = post.link;
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'refract-fake-seo' );
	} );

	test( 'prints the tags when there is no SEO plugin', async ( {
		admin,
		page,
	} ) => {
		await page.goto( link );
		await expect(
			page.locator( 'meta[property="og:title"]' )
		).toHaveAttribute( 'content', 'Oat milk is now free' );
		await expect(
			page.locator( 'meta[property="og:image"]' )
		).toHaveAttribute( 'content', /kolo-latte/ );
		await expect(
			page.locator( 'meta[name="twitter:card"]' )
		).toHaveAttribute( 'content', 'summary_large_image' );

		await admin.visitAdminPage( 'admin.php', 'page=refract' );
		const toggle = page.getByRole( 'checkbox', {
			name: 'Add social preview tags',
		} );
		await expect( toggle ).toBeChecked();
		await expect( toggle ).toBeEnabled();
	} );

	test( 'turns the tags off when an SEO plugin is active', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'refract-fake-seo' );

		await admin.visitAdminPage( 'admin.php', 'page=refract' );
		const toggle = page.getByRole( 'checkbox', {
			name: 'Add social preview tags',
		} );
		await expect( toggle ).not.toBeChecked();
		await expect( toggle ).toBeDisabled();
		await expect(
			page
				.locator( '#refract-settings' )
				.getByText( 'Off, because Yoast SEO already adds these tags.' )
		).toBeVisible();

		await page.goto( link );
		await expect( page.locator( 'h1, h2' ).first() ).toBeVisible();
		await expect( page.locator( 'meta[property^="og:"]' ) ).toHaveCount(
			0
		);
		await expect( page.locator( 'meta[name^="twitter:"]' ) ).toHaveCount(
			0
		);
	} );
} );

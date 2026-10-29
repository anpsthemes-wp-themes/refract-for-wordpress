/**
 * Publishing a post in the block editor sends it to (mock) Refract; the
 * sidebar shows the versions, Approve all queues them, and Posted shows the
 * links and clicks. The Activity page lists what happened.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	connect,
	deliver,
	directoryScreenshot,
	mock,
	openSidebar,
	reset,
	runCron,
	uploadImage,
} = require( '../utils' );

const TITLE = 'Our autumn Huila is here';

test.describe( 'Block editor', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
		await connect( requestUtils );
	} );

	test( 'publish, see the versions, approve all, see Posted', async ( {
		admin,
		editor,
		page,
		playwright,
		requestUtils,
	} ) => {
		const beans = await uploadImage(
			requestUtils,
			'kolo-beans.jpg',
			'Freshly roasted Huila beans'
		);
		const pourover = await uploadImage(
			requestUtils,
			'kolo-pourover.jpg',
			'A pour-over of the Huila'
		);

		const draft = await requestUtils.createPost( {
			title: TITLE,
			status: 'draft',
			featured_media: beans.id,
			excerpt:
				'The autumn Huila is in: red apple, panela and a soft orange finish.',
			content: [
				'<!-- wp:paragraph --><p>Every autumn we buy from the same three farms in Huila, Colombia. This year’s lot from the Gómez family is the sweetest we’ve had: red apple, panela and a soft orange finish.</p><!-- /wp:paragraph -->',
				`<!-- wp:image {"id":${ pourover.id },"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="${ pourover.source_url }" alt="A pour-over of the Huila" class="wp-image-${ pourover.id }"/></figure><!-- /wp:image -->`,
				'<!-- wp:paragraph --><p>We roasted it light, so it shines as a pour-over: 15 g of coffee to 250 g of water at 94 °C.</p><!-- /wp:paragraph -->',
			].join( '\n\n' ),
		} );
		await admin.editPost( draft.id );

		// Before publishing: the switch is on, every chosen account is a chip.
		const sidebar = await openSidebar( page );
		await expect(
			sidebar.getByRole( 'checkbox', { name: 'Send to Refract' } )
		).toBeChecked();
		await expect(
			sidebar.locator( '.refract-chip.is-pressed' )
		).toHaveCount( 6 );
		await expect( sidebar.locator( '.refract-cost' ) ).toHaveText(
			'5 versions · 5 credits'
		);

		const postId = await editor.publishPost();

		let post = await mock( requestUtils, `inspect/${ postId }` );
		expect( post.refract_status ).toBe( 'queued' );
		expect( post.scheduled ).toBe( true );

		runCron();

		post = await mock( requestUtils, `inspect/${ postId }` );
		expect( post.refract_status ).toBe( 'sent' );
		expect( post.refract_id ).toMatch( /^post-/ );

		const notes = ( await mock( requestUtils, 'log' ) ).filter(
			( entry ) => entry.path === '/api/v1/workspaces/ws-kolo/notes'
		);
		expect( notes ).toHaveLength( 1 );
		expect( notes[ 0 ].idempotency_key ).toMatch(
			new RegExp( `^wp:1:${ postId }:\\d+$` )
		);
		expect( notes[ 0 ].body.source ).toMatchObject( {
			type: 'wordpress',
			account_id: 'acc-wp-kolo',
			external_id: String( postId ),
			title: TITLE,
		} );
		expect( notes[ 0 ].body.accounts ).toHaveLength( 6 );
		expect( notes[ 0 ].body.media ).toEqual( [
			expect.objectContaining( {
				role: 'featured',
				alt: 'Freshly roasted Huila beans',
			} ),
			expect.objectContaining( {
				role: 'content',
				alt: 'A pour-over of the Huila',
			} ),
		] );

		// Writing → the editor polls until the versions are ready.
		await page.reload();
		const panel = await openSidebar( page );
		const versions = panel.locator( '.refract-version' );
		await expect( versions ).toHaveCount( 6 );
		await expect( versions.first() ).toContainText( 'Writing…' );

		await mock( requestUtils, 'advance', { to: 'written' } );
		const ready = await deliver( requestUtils, playwright );
		expect( ready.map( ( result ) => result.status ) ).toEqual( [ 200 ] );

		await expect(
			panel.locator( '.refract-version.is-needs_ok' )
		).toHaveCount( 6, { timeout: 20_000 } );
		await expect( versions.first() ).toContainText( 'Needs your OK' );
		await expect( versions.first() ).toContainText(
			'Our autumn Huila just landed'
		);
		await expect(
			panel.getByRole( 'button', {
				name: 'Approve all and add to queue',
			} )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'img', { name: 'Freshly roasted Huila beans' } )
				.first()
		).toBeVisible();

		await directoryScreenshot( page, 3 );

		await panel
			.getByRole( 'button', { name: 'Approve all and add to queue' } )
			.click();
		await expect(
			panel.locator( '.refract-version.is-scheduled' )
		).toHaveCount( 6 );
		await expect( versions.first() ).toContainText( 'Scheduled for' );

		const approved = await deliver( requestUtils, playwright );
		expect( approved.map( ( result ) => result.status ) ).toEqual( [
			200, 200,
		] );

		// Refract posts them; the sidebar shows the links and clicks.
		await mock( requestUtils, 'advance', { to: 'posted' } );
		const published = await deliver( requestUtils, playwright );
		expect( published ).toHaveLength( 6 );
		expect( published.every( ( result ) => result.status === 200 ) ).toBe(
			true
		);

		await page.reload();
		const done = await openSidebar( page );
		const instagram = done
			.locator( '.refract-version' )
			.filter( { hasText: '@kolocoffee' } )
			.first();
		await expect(
			done.locator( '.refract-version.is-posted' )
		).toHaveCount( 6 );
		await expect(
			instagram.getByRole( 'link', { name: /Posted/ } )
		).toHaveAttribute( 'href', /^https:\/\/www\.instagram\.com\/p\// );
		await expect( instagram ).toContainText( '48 clicks from Instagram' );
		await expect(
			done
				.locator( '.refract-version' )
				.filter( { hasText: 'Kolo Coffee Roasters' } )
		).toContainText( '23 clicks from LinkedIn' );

		// The Activity page lists the whole story.
		await admin.visitAdminPage( 'admin.php', 'page=refract-activity' );
		const activity = page.locator( '#refract-activity' );
		await expect( activity.getByRole( 'table' ) ).toBeVisible();
		for ( const text of [
			'Connected to Kolo Coffee.',
			'Sent to Refract. The versions are being written.',
			'The versions are ready for your OK.',
			'Ana Kovač approved a version.',
			'Posted.',
		] ) {
			await expect(
				activity.getByText( text, { exact: true } ).first()
			).toBeVisible();
		}
		await expect(
			activity.getByRole( 'link', { name: TITLE } ).first()
		).toBeVisible();

		await directoryScreenshot( page, 4 );
	} );
} );

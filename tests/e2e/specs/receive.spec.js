/**
 * Receiving a post from (mock) Refract: a signed POST /refract/v1/posts
 * creates the post with its images in the media library, and publishing it
 * here never sends it back to Refract.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	connect,
	deliver,
	mock,
	openSidebar,
	reset,
	runCron,
} = require( '../utils' );

const IMAGES = 'https://media.refract.test';

test.describe( 'Posts from Refract', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
		await connect( requestUtils, {
			refract_publish: true,
			publish_as: 1,
			refract_can: 'publish',
		} );
	} );

	test( 'creates the post with its images and never sends it back', async ( {
		admin,
		page,
		playwright,
		requestUtils,
	} ) => {
		await mock( requestUtils, 'send-post', {
			refract_post_id: 'post-from-refract-cupping',
			title: 'Cupping notes from our Tuesday session',
			content: `<!-- wp:paragraph --><p>Three washed Ethiopians on the table this week. The Guji won: jasmine, peach and black tea.</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="${ IMAGES }/kolo-cupping.jpg" alt="The cupping table"/></figure><!-- /wp:image -->`,
			excerpt: 'Three washed Ethiopians, and the Guji won.',
			status: 'draft',
			tags: [ 'Cupping', 'Ethiopia' ],
			featured_image: {
				url: `${ IMAGES }/kolo-latte.jpg`,
				alt: 'A latte on the bar',
			},
			images: [
				{
					url: `${ IMAGES }/kolo-cupping.jpg`,
					alt: 'The cupping table',
				},
			],
		} );

		const [ received ] = await deliver( requestUtils, playwright );
		expect( received.status ).toBe( 201 );
		expect( received.body.status ).toBe( 'draft' );
		expect( received.item.headers[ 'X-Refract-Signature' ] ).toMatch(
			/^[0-9a-f]{64}$/
		);

		const id = received.body.id;
		let post = await mock( requestUtils, `inspect/${ id }` );
		expect( post.source ).toBe( 'refract' );
		expect( post.thumbnail ).toMatch(
			/^http:\/\/localhost:8889\/wp-content\/uploads\/.+kolo-latte.*\.jpg$/
		);
		expect( post.thumbnail_from ).toBe( `${ IMAGES }/kolo-latte.jpg` );
		expect( post.content ).toContain(
			'http://localhost:8889/wp-content/uploads/'
		);
		expect( post.content ).not.toContain( IMAGES );

		// The same signed request again is a replay.
		const context = await playwright.request.newContext();
		const replay = await context.post( received.item.url, {
			data: received.item.body,
			headers: received.item.headers,
		} );
		expect( replay.status() ).toBe( 401 );
		expect( ( await replay.json() ).code ).toBe(
			'refract_replayed_signature'
		);
		await context.dispose();

		// Publishing it here doesn't send it back.
		await admin.editPost( id );
		const sidebar = await openSidebar( page );
		await expect( sidebar ).toContainText(
			'This post came from Refract, so it isn’t sent back.'
		);
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ id }`,
			data: { status: 'publish' },
		} );

		post = await mock( requestUtils, `inspect/${ id }` );
		expect( post.status ).toBe( 'publish' );
		expect( post.scheduled ).toBe( false );
		expect( post.refract_status ).toBe( '' );
		expect( () => runCron() ).toThrow();

		const log = await mock( requestUtils, 'log' );
		expect(
			log.filter( ( entry ) => entry.path.endsWith( '/notes' ) )
		).toHaveLength( 0 );

		await admin.visitAdminPage( 'admin.php', 'page=refract-activity' );
		await expect(
			page
				.locator( '#refract-activity' )
				.getByText( 'Got a draft from Refract.' )
		).toBeVisible();
	} );
} );

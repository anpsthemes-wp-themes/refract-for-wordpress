/**
 * Signs in (the `@wordpress/scripts` global setup), then makes sure the tests
 * site runs Refract against the mock, with the optional test plugins off.
 */
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const wpScriptsSetup = require( '@wordpress/scripts/config/playwright/global-setup' );

module.exports = async function globalSetup( config ) {
	await wpScriptsSetup( config );

	const { storageState, baseURL } = config.projects[ 0 ].use;
	const context = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( context, {
		storageStatePath: storageState,
	} );

	await requestUtils.setupRest();
	await requestUtils.activatePlugin( 'refract' );

	for ( const slug of [ 'classic-editor', 'refract-fake-seo' ] ) {
		await requestUtils.deactivatePlugin( slug );
	}

	await requestUtils.rest( {
		method: 'POST',
		path: '/refract-mock/v1/reset',
	} );
	await context.dispose();
};

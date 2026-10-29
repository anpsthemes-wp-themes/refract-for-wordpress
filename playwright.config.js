/**
 * Playwright for `npm run test:e2e`: the `@wordpress/scripts` defaults, against
 * wp-env's tests site (http://localhost:8889), with the specs in tests/e2e and
 * Refract replaced by the mock mu-plugin that .wp-env.json maps into the tests
 * environment only.
 *
 * REFRACT_SCREENSHOTS=1 also writes the directory screenshots to
 * .wordpress-org (npm run screenshots).
 */
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = {
	...baseConfig,
	testDir: path.join( __dirname, 'tests/e2e/specs' ),
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	use: {
		...baseConfig.use,
		viewport: { width: 1280, height: 800 },
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...baseConfig.projects[ 0 ].use,
				viewport: { width: 1280, height: 800 },
			},
		},
	],
};

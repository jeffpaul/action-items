/**
 * End-to-end tests. They run against any WordPress site that has the plugin
 * active and an administrator account:
 *
 *   WP_BASE_URL=http://localhost:8888 WP_USERNAME=admin WP_PASSWORD=password npm run test:e2e
 */
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	outputDir: '../../artifacts/e2e',
	timeout: 90_000,
	workers: 1,
	reporter: 'list',
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'off',
		screenshot: 'only-on-failure',
		launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH
			? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH }
			: {},
	},
} );

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		// Frontend script shared by both blocks, registered as `action-items-view`.
		view: './src/view/index.js',
	},
};

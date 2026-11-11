import { createBlock, registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	// Rendered on the server, where the current status is known.
	save: () => null,
	transforms: {
		from: [
			{
				type: 'block',
				blocks: [ 'core/paragraph' ],
				transform: ( { content } ) =>
					createBlock( metadata.name, {
						title: String( content ?? '' ),
					} ),
			},
		],
		to: [
			{
				type: 'block',
				blocks: [ 'core/paragraph' ],
				transform: ( { title } ) =>
					createBlock( 'core/paragraph', { content: title } ),
			},
		],
	},
} );

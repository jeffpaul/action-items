import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { defaultView, perPage, showFilters } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'action-items' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Default view for logged-in users',
							'action-items'
						) }
						help={ __(
							'Logged-out visitors always see all tasks.',
							'action-items'
						) }
						value={ defaultView }
						options={ [
							{
								value: 'mine',
								label: __( 'My Tasks', 'action-items' ),
							},
							{
								value: 'all',
								label: __( 'All Tasks', 'action-items' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { defaultView: value } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Tasks per page', 'action-items' ) }
						value={ perPage }
						min={ 5 }
						max={ 100 }
						step={ 5 }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 20 } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show contributor and status filters',
							'action-items'
						) }
						checked={ showFilters }
						onChange={ ( value ) =>
							setAttributes( { showFilters: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
						skipBlockSupportAttributes
					/>
				</Disabled>
			</div>
		</>
	);
}

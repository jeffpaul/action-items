import { __ } from '@wordpress/i18n';

export const BLOCK_NAME = 'action-items/task';

export const STATUS_NOT_STARTED = 'not-started';
export const STATUS_IN_PROGRESS = 'in-progress';
export const STATUS_COMPLETED = 'completed';

export const STATUS_OPTIONS = [
	{ value: STATUS_NOT_STARTED, label: __( 'Not started', 'action-items' ) },
	{ value: STATUS_IN_PROGRESS, label: __( 'In progress', 'action-items' ) },
	{ value: STATUS_COMPLETED, label: __( 'Completed', 'action-items' ) },
];

export const ALL_STATUSES = STATUS_OPTIONS.map( ( { value } ) => value );

export const TITLE_FORMATS = [
	'core/bold',
	'core/italic',
	'core/link',
	'core/code',
	'core/strikethrough',
];

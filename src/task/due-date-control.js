import { Button, DatePicker, Dropdown } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { __, _x, sprintf } from '@wordpress/i18n';

import { toDueDate } from './utils';

/**
 * Formats a due date for display in the editor.
 *
 * @param {string} dueDate Date as YYYY-MM-DD.
 * @return {string} Label such as "Due Nov 13".
 */
export function formatDueDate( dueDate ) {
	const isSameYear =
		dueDate.slice( 0, 4 ) === String( new Date().getFullYear() );
	const format = isSameYear
		? /* translators: Due date format without the year, see https://www.php.net/manual/datetime.format.php */
			_x( 'M j', 'due date format', 'action-items' )
		: /* translators: Due date format with the year, see https://www.php.net/manual/datetime.format.php */
			_x( 'M j, Y', 'due date format', 'action-items' );

	return sprintf(
		/* translators: %s: Due date. */
		__( 'Due %s', 'action-items' ),
		// The explicit time and the UTC timezone keep the day from shifting.
		dateI18n( format, `${ dueDate }T12:00:00Z`, 'UTC' )
	);
}

/**
 * Button opening a date picker for the optional due date of a task.
 *
 * @param {Object}                  props
 * @param {string=}                 props.value       Due date as YYYY-MM-DD.
 * @param {(date?: string) => void} props.onChange    Called with the new due date, or undefined to clear it.
 * @param {Object=}                 props.toggleProps Props of the toggle button.
 */
export default function DueDateControl( { value, onChange, toggleProps } ) {
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					{ ...toggleProps }
					onClick={ onToggle }
					aria-expanded={ isOpen }
				>
					{ value
						? formatDueDate( value )
						: __( 'Set due date', 'action-items' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="action-items-due-date-picker">
					<DatePicker
						currentDate={ value ? `${ value }T12:00:00` : null }
						onChange={ ( date ) => {
							onChange( toDueDate( date ) );
							onClose();
						} }
					/>
					{ value && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								onChange( undefined );
								onClose();
							} }
						>
							{ __( 'Remove due date', 'action-items' ) }
						</Button>
					) }
				</div>
			) }
		/>
	);
}

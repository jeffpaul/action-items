import {
	BlockControls,
	InspectorControls,
	RichText,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import {
	BaseControl,
	Button,
	CheckboxControl,
	Dropdown,
	PanelBody,
	SelectControl,
	ToolbarDropdownMenu,
	ToolbarGroup,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import AssigneeControl from './assignee-control';
import {
	BLOCK_NAME,
	STATUS_COMPLETED,
	STATUS_IN_PROGRESS,
	STATUS_NOT_STARTED,
	STATUS_OPTIONS,
	TITLE_FORMATS,
} from './constants';
import DueDateControl from './due-date-control';
import { startTaskSync, store } from './store';
import { generateTaskId, needsNewTaskId } from './utils';

export default function Edit( {
	attributes,
	setAttributes,
	clientId,
	isSelected,
} ) {
	const { taskId, title, assignees, dueDate } = attributes;
	const titleRef = useRef();

	const { liveTask, users, mustRenewId } = useSelect(
		( select ) => {
			const { getBlocksByName, getBlockAttributes } =
				select( blockEditorStore );
			const taskBlocks = getBlocksByName( BLOCK_NAME ).map( ( id ) => ( {
				clientId: id,
				taskId: getBlockAttributes( id )?.taskId,
			} ) );

			return {
				liveTask: taskId
					? select( store ).getTask( taskId )
					: undefined,
				users: select( store ).getUsers(),
				mustRenewId: needsNewTaskId( clientId, taskId, taskBlocks ),
			};
		},
		[ clientId, taskId ]
	);

	const {
		__unstableMarkNextChangeAsNotPersistent: markNextChangeAsNotPersistent,
		insertBlock,
		removeBlock,
		replaceBlock,
	} = useDispatch( blockEditorStore );
	const { getBlockIndex, getBlockRootClientId } =
		useSelect( blockEditorStore );
	const { loadUsers, updateTaskStatus } = useDispatch( store );
	const { isFresh } = useSelect( store );

	// The status of a saved task is stored outside of post content.
	const status = liveTask?.status ?? attributes.status;

	useEffect( () => {
		startTaskSync();
	}, [] );

	// Every task needs its own identifier. A duplicated or pasted block gets
	// a new one and starts from the current status of the task it copies.
	useEffect( () => {
		if ( mustRenewId ) {
			markNextChangeAsNotPersistent();
			setAttributes( { taskId: generateTaskId(), status } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ mustRenewId ] );

	useEffect( () => {
		if ( assignees.length ) {
			loadUsers( assignees );
		}
	}, [ assignees, loadUsers ] );

	const setStatus = ( nextStatus ) => {
		// The attribute is the initial status of a task that is not saved
		// yet. For a saved task the change is sent to the server right away.
		// While the saved tasks are not known (editor loading, post being
		// saved), the request is sent anyway and ignored if there is no task.
		setAttributes( { status: nextStatus } );
		if ( liveTask || ! isFresh() ) {
			updateTaskStatus( taskId, nextStatus );
		}
	};

	// Enter adds the next task, or leaves the list when the task is empty.
	const onKeyDownCapture = ( event ) => {
		if (
			event.key !== 'Enter' ||
			event.shiftKey ||
			event.nativeEvent.isComposing ||
			! titleRef.current?.contains( event.target ) ||
			event.target.getAttribute( 'aria-activedescendant' )
		) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if ( ! title ) {
			replaceBlock( clientId, createBlock( 'core/paragraph' ) );
			return;
		}

		insertBlock(
			createBlock( BLOCK_NAME ),
			getBlockIndex( clientId ) + 1,
			getBlockRootClientId( clientId )
		);
	};

	const assigneeNames = assignees.map(
		( id ) =>
			users[ id ]?.name ??
			sprintf(
				/* translators: %d: User ID. */
				__( 'User #%d', 'action-items' ),
				id
			)
	);
	const statusLabel = STATUS_OPTIONS.find(
		( option ) => option.value === status
	)?.label;
	const setAssignees = ( next ) => setAttributes( { assignees: next } );
	const setDueDate = ( next ) => setAttributes( { dueDate: next } );

	const blockProps = useBlockProps( {
		className: `action-items-task is-status-${ status }`,
		onKeyDownCapture,
	} );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ null }
						text={ statusLabel }
						label={ __( 'Change status', 'action-items' ) }
						controls={ STATUS_OPTIONS.map( ( option ) => ( {
							title: option.label,
							isActive: option.value === status,
							onClick: () => setStatus( option.value ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>
			<InspectorControls>
				<PanelBody
					title={ __( 'Task', 'action-items' ) }
					className="action-items-inspector"
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'action-items' ) }
						value={ status }
						options={ STATUS_OPTIONS }
						onChange={ setStatus }
						help={
							liveTask
								? __(
										'Status changes are saved immediately.',
										'action-items'
									)
								: undefined
						}
					/>
					<AssigneeControl
						value={ assignees }
						onChange={ setAssignees }
					/>
					<BaseControl
						__nextHasNoMarginBottom
						id={ `action-items-due-${ clientId }` }
						label={ __( 'Due date', 'action-items' ) }
					>
						<div>
							<DueDateControl
								value={ dueDate }
								onChange={ setDueDate }
								toggleProps={ {
									id: `action-items-due-${ clientId }`,
									variant: 'secondary',
									__next40pxDefaultSize: true,
								} }
							/>
						</div>
					</BaseControl>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ /*
				 * The title comes first in the DOM so that it receives focus
				 * when the block is inserted; CSS places the checkbox before it.
				 */ }
				<RichText
					ref={ titleRef }
					identifier="title"
					tagName="div"
					className="action-items-task__title"
					value={ title }
					onChange={ ( value ) => setAttributes( { title: value } ) }
					allowedFormats={ TITLE_FORMATS }
					disableLineBreaks
					onRemove={ () => removeBlock( clientId ) }
					placeholder={ __( 'Add a task…', 'action-items' ) }
					aria-label={ __( 'Task', 'action-items' ) }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					className="action-items-task__checkbox"
					checked={ status === STATUS_COMPLETED }
					indeterminate={ status === STATUS_IN_PROGRESS }
					onChange={ ( isChecked ) =>
						setStatus(
							isChecked ? STATUS_COMPLETED : STATUS_NOT_STARTED
						)
					}
					aria-label={ __( 'Mark as completed', 'action-items' ) }
				/>
				<div className="action-items-task__meta">
					{ status !== STATUS_NOT_STARTED && (
						<span className="action-items-task__status">
							{ statusLabel }
						</span>
					) }
					{ ( isSelected || assignees.length > 0 ) && (
						<Dropdown
							popoverProps={ { placement: 'bottom-start' } }
							renderToggle={ ( { isOpen, onToggle } ) => (
								<Button
									size="small"
									variant="tertiary"
									className="action-items-task__meta-button"
									onClick={ onToggle }
									aria-expanded={ isOpen }
								>
									{ assignees.length
										? sprintf(
												/* translators: %s: List of user names. */
												__(
													'Assigned to: %s',
													'action-items'
												),
												assigneeNames.join( ', ' )
											)
										: __( 'Assign', 'action-items' ) }
								</Button>
							) }
							renderContent={ () => (
								<div className="action-items-assignee-picker">
									<AssigneeControl
										value={ assignees }
										onChange={ setAssignees }
									/>
								</div>
							) }
						/>
					) }
					{ ( isSelected || dueDate ) && (
						<DueDateControl
							value={ dueDate }
							onChange={ setDueDate }
							toggleProps={ {
								size: 'small',
								variant: 'tertiary',
								className: 'action-items-task__meta-button',
							} }
						/>
					) }
				</div>
			</div>
		</>
	);
}

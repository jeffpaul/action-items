/**
 * Frontend behavior shared by the Task and Task List blocks.
 *
 * The server-rendered markup is the same for every visitor, so that pages can
 * be cached. This script loads the state that depends on the visitor (their
 * own tasks, the tasks they may update) through the REST API and sends status
 * changes back.
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { chunk, getSharedPostId, statusFromCheckbox } from './utils';

const TASK_SELECTOR = '.action-items-task[data-task-id]';
const STATUSES = [ 'not-started', 'in-progress', 'completed' ];

/**
 * Finds every element showing a task. A task can be on the page twice, in its
 * post and in a task list.
 *
 * @param {string} id Task identifier.
 * @return {Element[]} Task elements.
 */
function getTaskElements( id ) {
	return Array.from( document.querySelectorAll( TASK_SELECTOR ) ).filter(
		( element ) => element.dataset.taskId === id
	);
}

function getStatus( element ) {
	return element.querySelector( '.action-items-task__status-select' ).value;
}

function applyStatus( element, status ) {
	const checkbox = element.querySelector( '.action-items-task__checkbox' );
	const select = element.querySelector( '.action-items-task__status-select' );
	const badge = element.querySelector( '.action-items-task__status' );

	STATUSES.forEach( ( name ) =>
		element.classList.toggle( `is-status-${ name }`, name === status )
	);
	if ( status === 'completed' ) {
		element.classList.remove( 'is-overdue' );
	}

	select.value = status;
	checkbox.checked = status === 'completed';
	checkbox.indeterminate = status === 'in-progress';
	badge.textContent = select.selectedOptions[ 0 ]?.textContent ?? '';
}

function setInteractive( element, isInteractive ) {
	const select = element.querySelector( '.action-items-task__status-select' );

	element.querySelector( '.action-items-task__checkbox' ).disabled =
		! isInteractive;
	select.disabled = ! isInteractive;
	select.hidden = ! isInteractive;
	element.querySelector( '.action-items-task__status' ).hidden =
		isInteractive;
}

function showError( element, message ) {
	clearError( element );

	const error = document.createElement( 'span' );
	error.className = 'action-items-task__error';
	error.textContent = message;
	element.querySelector( '.action-items-task__meta' ).append( error );
}

function clearError( element ) {
	element.querySelector( '.action-items-task__error' )?.remove();
}

async function updateStatus( source, status, previousStatus ) {
	const id = source.dataset.taskId;
	const elements = getTaskElements( id );

	elements.forEach( ( element ) => {
		applyStatus( element, status );
		clearError( element );
		element.setAttribute( 'aria-busy', 'true' );
	} );

	try {
		const task = await apiFetch( {
			path: `/action-items/v1/tasks/${ id }`,
			method: 'POST',
			data: { status },
		} );

		// The elements may have been replaced while the request was running.
		getTaskElements( id ).forEach( ( element ) =>
			applyStatus( element, task.status )
		);
		speak(
			sprintf(
				/* translators: %s: Task status, for instance "Completed". */
				__( 'Task status changed to: %s', 'action-items' ),
				source.querySelector( '.action-items-task__status' ).textContent
			)
		);
	} catch ( error ) {
		const message =
			error?.message ||
			__( 'The task could not be updated.', 'action-items' );

		getTaskElements( id ).forEach( ( element ) =>
			applyStatus( element, previousStatus )
		);
		showError( source, message );
		speak( message, 'assertive' );
	} finally {
		getTaskElements( id ).forEach( ( element ) =>
			element.removeAttribute( 'aria-busy' )
		);
	}
}

/**
 * Loads the current state of the Task blocks of the page, which may come from
 * a cache, and enables the controls of the tasks the visitor may update.
 */
async function hydrateTaskBlocks() {
	const elements = Array.from(
		document.querySelectorAll( TASK_SELECTOR )
	).filter( ( element ) => ! element.closest( '.action-items-list' ) );

	if ( ! elements.length ) {
		return;
	}

	const postId = getSharedPostId(
		elements.map( ( element ) => element.dataset.postId )
	);
	const ids = [
		...new Set( elements.map( ( element ) => element.dataset.taskId ) ),
	];

	for ( const include of chunk( ids, 100 ) ) {
		try {
			const tasks = await apiFetch( {
				path: addQueryArgs( '/action-items/v1/tasks', {
					include: include.join( ',' ),
					status: STATUSES.join( ',' ),
					per_page: 100,
					...( postId ? { post: postId } : {} ),
				} ),
			} );

			tasks.forEach( ( task ) => {
				elements
					.filter( ( element ) => element.dataset.taskId === task.id )
					.forEach( ( element ) => {
						applyStatus( element, task.status );
						setInteractive( element, task.can_update );
					} );
			} );
		} catch {
			// The server-rendered, read-only state stays in place.
		}
	}
}

function initTaskList( list ) {
	const results = list.querySelector( '.action-items-list__results' );
	const views = list.querySelector( '.action-items-list__views' );
	const assigneeFilter = list.querySelector( '[data-filter="assignee"]' );
	const statusFilter = list.querySelector( '[data-filter="status"]' );
	const state = {
		view: 'default',
		assignee: '',
		status: 'incomplete',
		page: 1,
	};
	let requestCount = 0;

	const load = async ( { announce = true, focus = false } = {} ) => {
		const requestId = ++requestCount;
		results.setAttribute( 'aria-busy', 'true' );

		try {
			const response = await apiFetch( {
				path: addQueryArgs( '/action-items/v1/task-list', {
					view: state.view,
					default: list.dataset.defaultView,
					assignee: state.assignee || undefined,
					status: state.status,
					page: state.page,
					per_page: list.dataset.perPage,
				} ),
			} );

			// Ignore responses that were overtaken by a newer request.
			if ( requestId !== requestCount ) {
				return;
			}

			// Markup rendered and escaped by the plugin on the server.
			results.innerHTML = response.html;
			results
				.querySelectorAll( '.is-status-in-progress' )
				.forEach( ( element ) =>
					applyStatus( element, 'in-progress' )
				);

			state.view = response.view;
			state.page = response.page;
			views.hidden = ! response.viewer;
			views
				.querySelectorAll( '[data-view]' )
				.forEach( ( button ) =>
					button.setAttribute(
						'aria-pressed',
						String( button.dataset.view === response.view )
					)
				);

			if ( focus ) {
				results.focus();
			}
			if ( announce ) {
				speak(
					sprintf(
						/* translators: %d: Number of tasks. */
						_n(
							'%d task found.',
							'%d tasks found.',
							response.total,
							'action-items'
						),
						response.total
					)
				);
			}
		} catch ( error ) {
			if ( requestId === requestCount && announce ) {
				speak(
					error?.message ||
						__( 'The tasks could not be loaded.', 'action-items' ),
					'assertive'
				);
			}
		} finally {
			if ( requestId === requestCount ) {
				results.removeAttribute( 'aria-busy' );
			}
		}
	};

	views.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-view]' );
		if ( ! button ) {
			return;
		}

		state.view = button.dataset.view;
		state.assignee = '';
		state.page = 1;
		if ( assigneeFilter ) {
			assigneeFilter.value = '';
		}
		load();
	} );

	assigneeFilter?.addEventListener( 'change', () => {
		state.assignee = assigneeFilter.value;
		state.page = 1;
		// Looking at somebody else's tasks leaves the "My Tasks" view.
		if ( state.assignee ) {
			state.view = 'all';
		}
		load();
	} );

	statusFilter?.addEventListener( 'change', () => {
		state.status = statusFilter.value;
		state.page = 1;
		load();
	} );

	results.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.action-items-list__page' );
		if ( ! button || button.disabled ) {
			return;
		}

		state.page = parseInt( button.dataset.page, 10 ) || 1;
		load( { focus: true } );
	} );

	results.tabIndex = -1;
	list.classList.add( 'is-interactive' );
	views.hidden = true;

	load( { announce: false } );
}

document.addEventListener( 'change', ( event ) => {
	const element = event.target.closest( TASK_SELECTOR );
	if ( ! element ) {
		return;
	}

	if ( event.target.matches( '.action-items-task__checkbox' ) ) {
		const previous = getStatus( element );
		updateStatus(
			element,
			statusFromCheckbox( event.target.checked ),
			previous
		);
	} else if ( event.target.matches( '.action-items-task__status-select' ) ) {
		// The select already shows the new value; the badge still has the old one.
		const previous =
			STATUSES.find( ( status ) =>
				element.classList.contains( `is-status-${ status }` )
			) ?? 'not-started';
		updateStatus( element, event.target.value, previous );
	}
} );

domReady( () => {
	document
		.querySelectorAll( `${ TASK_SELECTOR }.is-status-in-progress` )
		.forEach( ( element ) => applyStatus( element, 'in-progress' ) );

	hydrateTaskBlocks();
	document.querySelectorAll( '.action-items-list' ).forEach( initTaskList );
} );

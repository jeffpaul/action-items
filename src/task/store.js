/**
 * Editor store holding the live, server-side state of the tasks of the post
 * being edited, and the users known to the editor.
 *
 * The status of a saved task lives in the tasks table, not in post content.
 * The editor therefore reads it from the REST API and writes changes back
 * immediately, instead of going through the post save.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	createReduxStore,
	dispatch,
	register,
	select,
	subscribe,
} from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';

import { ALL_STATUSES } from './constants';

export const STORE_NAME = 'action-items/editor';

const DEFAULT_STATE = { tasks: {}, users: {}, isFresh: false };

export const store = createReduxStore( STORE_NAME, {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'RECEIVE_TASKS':
				return { ...state, tasks: action.tasks, isFresh: true };
			case 'SET_FRESH':
				return { ...state, isFresh: action.isFresh };
			case 'SET_TASK_STATUS':
				if ( ! state.tasks[ action.id ] ) {
					return state;
				}
				return {
					...state,
					tasks: {
						...state.tasks,
						[ action.id ]: {
							...state.tasks[ action.id ],
							status: action.status,
						},
					},
				};
			case 'RECEIVE_USERS':
				return {
					...state,
					users: {
						...state.users,
						...Object.fromEntries(
							action.users.map( ( user ) => [ user.id, user ] )
						),
					},
				};
		}
		return state;
	},
	selectors: {
		getTask: ( state, id ) => state.tasks[ id ],
		getUsers: ( state ) => state.users,
		// Whether the task state reflects the last save of the post.
		isFresh: ( state ) => state.isFresh,
	},
	actions: {
		receiveUsers: ( users ) => ( { type: 'RECEIVE_USERS', users } ),
		setFresh: ( isFresh ) => ( { type: 'SET_FRESH', isFresh } ),
		refreshTasks:
			( postId ) =>
			async ( { dispatch: storeDispatch } ) => {
				try {
					const tasks = await apiFetch( {
						path: addQueryArgs( '/action-items/v1/tasks', {
							post: postId,
							status: ALL_STATUSES.join( ',' ),
							per_page: 100,
						} ),
					} );
					storeDispatch( {
						type: 'RECEIVE_TASKS',
						tasks: Object.fromEntries(
							tasks.map( ( task ) => [ task.id, task ] )
						),
					} );
				} catch {
					// Without live state the blocks fall back to their attributes.
					storeDispatch( { type: 'SET_FRESH', isFresh: true } );
				}
			},
		updateTaskStatus:
			( id, status ) =>
			async ( {
				dispatch: storeDispatch,
				select: storeSelect,
				registry,
			} ) => {
				const previous = storeSelect.getTask( id )?.status;
				storeDispatch( { type: 'SET_TASK_STATUS', id, status } );
				try {
					await apiFetch( {
						path: `/action-items/v1/tasks/${ id }`,
						method: 'POST',
						data: { status },
					} );
				} catch ( error ) {
					// The task does not exist yet: its block has not been
					// saved, and will be created with the status attribute.
					if ( error?.code === 'action_items_not_found' ) {
						return;
					}
					storeDispatch( {
						type: 'SET_TASK_STATUS',
						id,
						status: previous,
					} );
					registry
						.dispatch( noticesStore )
						.createErrorNotice(
							error?.message ||
								__(
									'The task status could not be updated.',
									'action-items'
								),
							{ type: 'snackbar' }
						);
				}
			},
		loadUsers:
			( ids ) =>
			async ( { dispatch: storeDispatch, select: storeSelect } ) => {
				const known = storeSelect.getUsers();
				const missing = ids.filter( ( id ) => ! known[ id ] );
				if ( ! missing.length ) {
					return;
				}
				try {
					storeDispatch.receiveUsers(
						await apiFetch( {
							path: addQueryArgs( '/action-items/v1/users', {
								include: missing.join( ',' ),
								per_page: 100,
							} ),
						} )
					);
				} catch {
					// Unknown users are shown by ID.
				}
			},
	},
} );

register( store );

let isSyncing = false;

/**
 * Starts keeping the live task state in sync with the post being edited.
 *
 * The state is loaded for the current post and reloaded after every save,
 * since saving is what creates, updates and deletes tasks on the server.
 * Called when the first Task block mounts, so that posts without tasks cost
 * no request.
 */
export function startTaskSync() {
	if ( isSyncing ) {
		return;
	}
	isSyncing = true;

	let loadedPostId;
	let wasSaving = false;

	const check = () => {
		const editor = select( 'core/editor' );
		const postId = editor?.getCurrentPostId?.();

		// Templates and other non-post entities have no tasks.
		if ( typeof postId !== 'number' ) {
			return;
		}

		const isSaving = editor.isSavingPost();
		const hasStartedSaving = isSaving && ! wasSaving;
		const mustRefresh =
			postId !== loadedPostId || ( wasSaving && ! isSaving );

		// Update the bookkeeping first: dispatching notifies this very listener.
		wasSaving = isSaving;
		loadedPostId = postId;

		if ( hasStartedSaving ) {
			// Saving creates and deletes tasks: what we know is out of date.
			dispatch( store ).setFresh( false );
		}
		if ( mustRefresh ) {
			dispatch( store ).refreshTasks( postId );
		}
	};

	subscribe( check );
	check();
}

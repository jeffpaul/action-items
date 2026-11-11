import apiFetch from '@wordpress/api-fetch';
import { FormTokenField } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { store } from './store';
import { getUserLabel } from './utils';

/**
 * Field to pick the assignees of a task, with user search.
 *
 * The search goes through the plugin's own endpoint, which only requires the
 * capability to edit posts and only returns public profile data.
 *
 * @param {Object}                  props
 * @param {number[]}                props.value    Assignee user IDs.
 * @param {(ids: number[]) => void} props.onChange Called with the new list of user IDs.
 */
export default function AssigneeControl( { value, onChange } ) {
	const users = useSelect( ( select ) => select( store ).getUsers(), [] );
	const { receiveUsers } = useDispatch( store );
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const debouncedSetSearch = useDebounce( setSearch, 250 );

	useEffect( () => {
		let isCurrent = true;

		apiFetch( {
			path: addQueryArgs( '/action-items/v1/users', {
				search,
				per_page: 20,
			} ),
		} )
			.then( ( found ) => {
				if ( isCurrent ) {
					setResults( found );
					receiveUsers( found );
				}
			} )
			.catch( () => {} );

		return () => {
			isCurrent = false;
		};
	}, [ search, receiveUsers ] );

	const getTokenLabel = ( id ) =>
		users[ id ]
			? getUserLabel( users[ id ] )
			: sprintf(
					/* translators: %d: User ID. */
					__( 'User #%d', 'action-items' ),
					id
				);

	const idsByLabel = useMemo( () => {
		const map = new Map();
		Object.values( users ).forEach( ( user ) =>
			map.set( getUserLabel( user ), user.id )
		);
		value.forEach( ( id ) => {
			if ( ! users[ id ] ) {
				map.set(
					sprintf(
						/* translators: %d: User ID. */
						__( 'User #%d', 'action-items' ),
						id
					),
					id
				);
			}
		} );
		return map;
	}, [ users, value ] );

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			__experimentalExpandOnFocus
			__experimentalValidateInput={ ( token ) => idsByLabel.has( token ) }
			label={ __( 'Assignees', 'action-items' ) }
			placeholder={ __( 'Search users…', 'action-items' ) }
			value={ value.map( getTokenLabel ) }
			suggestions={ results.map( getUserLabel ) }
			onInputChange={ debouncedSetSearch }
			onChange={ ( tokens ) => {
				const ids = tokens
					.map( ( token ) =>
						idsByLabel.get(
							typeof token === 'string' ? token : token.value
						)
					)
					.filter( Boolean );
				onChange( [ ...new Set( ids ) ] );
			} }
		/>
	);
}

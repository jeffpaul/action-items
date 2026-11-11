import { describe, expect, it } from 'vitest';

import {
	generateTaskId,
	getUserLabel,
	needsNewTaskId,
	toDueDate,
} from '../../src/task/utils';
import {
	chunk,
	getSharedPostId,
	statusFromCheckbox,
} from '../../src/view/utils';

describe( 'generateTaskId', () => {
	it( 'returns lowercase version 4 UUIDs accepted by the server', () => {
		for ( let i = 0; i < 200; i++ ) {
			expect( generateTaskId() ).toMatch(
				/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
			);
		}
	} );

	it( 'returns a different identifier every time', () => {
		const ids = new Set(
			Array.from( { length: 500 }, () => generateTaskId() )
		);
		expect( ids.size ).toBe( 500 );
	} );
} );

describe( 'needsNewTaskId', () => {
	const blocks = [
		{ clientId: 'a', taskId: 'one' },
		{ clientId: 'b', taskId: 'two' },
		{ clientId: 'c', taskId: 'one' },
	];

	it( 'requires an identifier for new blocks', () => {
		expect( needsNewTaskId( 'x', undefined, blocks ) ).toBe( true );
		expect( needsNewTaskId( 'x', '', blocks ) ).toBe( true );
	} );

	it( 'keeps the identifier of the first block using it', () => {
		expect( needsNewTaskId( 'a', 'one', blocks ) ).toBe( false );
		expect( needsNewTaskId( 'b', 'two', blocks ) ).toBe( false );
	} );

	it( 'renews the identifier of a duplicated block', () => {
		expect( needsNewTaskId( 'c', 'one', blocks ) ).toBe( true );
	} );

	it( 'keeps the identifier of a block that is not listed yet', () => {
		expect( needsNewTaskId( 'x', 'three', blocks ) ).toBe( false );
	} );
} );

describe( 'getUserLabel', () => {
	it( 'adds the nicename to tell users apart', () => {
		expect( getUserLabel( { name: 'Jane Doe', slug: 'janed' } ) ).toBe(
			'Jane Doe (janed)'
		);
	} );

	it( 'does not repeat an identical nicename', () => {
		expect( getUserLabel( { name: 'jane', slug: 'jane' } ) ).toBe( 'jane' );
		expect( getUserLabel( { name: 'Jane' } ) ).toBe( 'Jane' );
	} );
} );

describe( 'toDueDate', () => {
	it( 'keeps the date part of a date picker value', () => {
		expect( toDueDate( '2030-11-13T00:00:00' ) ).toBe( '2030-11-13' );
		expect( toDueDate( '2030-11-13' ) ).toBe( '2030-11-13' );
	} );

	it( 'returns undefined for empty or invalid values', () => {
		expect( toDueDate( null ) ).toBeUndefined();
		expect( toDueDate( '' ) ).toBeUndefined();
		expect( toDueDate( 'tomorrow' ) ).toBeUndefined();
	} );
} );

describe( 'chunk', () => {
	it( 'splits arrays into chunks of the given size', () => {
		expect( chunk( [ 1, 2, 3, 4, 5 ], 2 ) ).toEqual( [
			[ 1, 2 ],
			[ 3, 4 ],
			[ 5 ],
		] );
		expect( chunk( [], 2 ) ).toEqual( [] );
	} );
} );

describe( 'getSharedPostId', () => {
	it( 'returns the post ID when every task belongs to the same post', () => {
		expect( getSharedPostId( [ '12', '12', '12' ] ) ).toBe( '12' );
	} );

	it( 'returns undefined on pages mixing posts or without a valid ID', () => {
		expect( getSharedPostId( [ '12', '13' ] ) ).toBeUndefined();
		expect( getSharedPostId( [ '0' ] ) ).toBeUndefined();
		expect( getSharedPostId( [ undefined ] ) ).toBeUndefined();
		expect( getSharedPostId( [] ) ).toBeUndefined();
	} );
} );

describe( 'statusFromCheckbox', () => {
	it( 'maps the checkbox to completed or not started', () => {
		expect( statusFromCheckbox( true ) ).toBe( 'completed' );
		expect( statusFromCheckbox( false ) ).toBe( 'not-started' );
	} );
} );

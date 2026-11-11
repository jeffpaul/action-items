/**
 * Generates a random (version 4) UUID.
 *
 * `crypto.randomUUID()` is only available in secure contexts, so the UUID is
 * built from `crypto.getRandomValues()`, which works everywhere.
 *
 * @return {string} UUID.
 */
export function generateTaskId() {
	const bytes = new Uint8Array( 16 );
	globalThis.crypto.getRandomValues( bytes );

	// Per RFC 4122: set the version (4) and the variant (10xx).
	bytes[ 6 ] = ( bytes[ 6 ] % 16 ) + 64;
	bytes[ 8 ] = ( bytes[ 8 ] % 64 ) + 128;

	const hex = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );

	return [
		hex.slice( 0, 8 ),
		hex.slice( 8, 12 ),
		hex.slice( 12, 16 ),
		hex.slice( 16, 20 ),
		hex.slice( 20 ),
	].join( '-' );
}

/**
 * Whether a block must be given a new task identifier.
 *
 * That is the case when it has none, or when an earlier Task block of the
 * same document already uses its identifier, as happens after duplicating or
 * copying a block.
 *
 * @param {string}                               clientId   Client ID of the block.
 * @param {string}                               taskId     Task identifier of the block.
 * @param {{clientId: string, taskId: string}[]} taskBlocks Task blocks in document order.
 * @return {boolean} Whether a new identifier is needed.
 */
export function needsNewTaskId( clientId, taskId, taskBlocks ) {
	if ( ! taskId ) {
		return true;
	}

	const first = taskBlocks.find( ( block ) => block.taskId === taskId );

	return !! first && first.clientId !== clientId;
}

/**
 * Builds the label of a user in the assignee field.
 *
 * The nicename is included so that users who share a display name can be
 * told apart.
 *
 * @param {Object} user      User.
 * @param {string} user.name Display name.
 * @param {string} user.slug Nicename.
 * @return {string} Label.
 */
export function getUserLabel( user ) {
	return user.slug && user.slug !== user.name
		? `${ user.name } (${ user.slug })`
		: user.name;
}

/**
 * Converts the value of a date picker to a due date.
 *
 * @param {?string} value Date as returned by the date picker.
 * @return {string|undefined} Date as YYYY-MM-DD, or undefined when empty or invalid.
 */
export function toDueDate( value ) {
	const match = /^(\d{4}-\d{2}-\d{2})/.exec( value || '' );

	return match ? match[ 1 ] : undefined;
}

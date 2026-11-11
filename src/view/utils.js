/**
 * Splits an array into chunks.
 *
 * @param {Array}  items Items.
 * @param {number} size  Maximum chunk size.
 * @return {Array[]} Chunks.
 */
export function chunk( items, size ) {
	const chunks = [];
	for ( let index = 0; index < items.length; index += size ) {
		chunks.push( items.slice( index, index + size ) );
	}
	return chunks;
}

/**
 * Returns the post ID shared by all tasks of the page, if there is one.
 *
 * On a single post, passing it to the REST API lets users who can edit the
 * post see the state of its tasks before it is published.
 *
 * @param {string[]} postIds Post IDs of the task elements.
 * @return {string|undefined} The shared post ID.
 */
export function getSharedPostId( postIds ) {
	const unique = [ ...new Set( postIds ) ];

	return unique.length === 1 && /^[1-9]\d*$/.test( unique[ 0 ] ?? '' )
		? unique[ 0 ]
		: undefined;
}

/**
 * Maps the state of the completion checkbox to a status.
 *
 * @param {boolean} isChecked Whether the checkbox is checked.
 * @return {string} Status.
 */
export function statusFromCheckbox( isChecked ) {
	return isChecked ? 'completed' : 'not-started';
}

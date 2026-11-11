/**
 * Walks through the workflow the plugin exists for: meeting notes with
 * assigned tasks, a centralized task list, and completion by an assignee who
 * cannot edit the notes.
 */
const { test, expect } = require( '@playwright/test' );

const ADMIN = {
	username: process.env.WP_USERNAME || 'admin',
	password: process.env.WP_PASSWORD || 'password',
};
const RUN = Date.now().toString( 36 );
const PASSWORD = `E2e-${ RUN }-password!`;
const USERS = [ 1, 2, 3 ].map( ( n ) => ( {
	username: `mt${ n }${ RUN }`,
	name: `Contributor${ n } ${ RUN }`,
	// A subscriber cannot edit any post, a contributor only their own drafts.
	role: n === 3 ? 'subscriber' : 'contributor',
} ) );
const TASKS = [
	`Review AI provider integration proposal ${ RUN }`,
	`Update MCP Adapter documentation ${ RUN }`,
	`Publish contributor onboarding checklist ${ RUN }`,
];
const NOTES_TITLE = `Meeting notes ${ RUN }`;

let notesUrl;
let tasksUrl;
let notesId;

async function logIn( page, { username, password = PASSWORD } ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
}

async function newUserPage( browser, user ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	if ( user ) {
		await logIn( page, user );
	}
	return page;
}

/**
 * Opens a block editor screen with the first-run modals turned off. They
 * would otherwise cover the editor on a site where the user is new.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          path Admin path of the editor.
 */
async function openEditor( page, path ) {
	const isReady = () => window.wp?.data?.select( 'core/editor' );

	await page.goto( path );
	await page.waitForFunction( isReady );
	await page.evaluate( () => {
		const { set } = window.wp.data.dispatch( 'core/preferences' );
		set( 'core/edit-post', 'welcomeGuide', false );
		set( 'core', 'enableChoosePatternModal', false );
	} );
	await page.reload();
	await page.waitForFunction( isReady );
}

function apiFetch( page, options ) {
	return page.evaluate( ( args ) => window.wp.apiFetch( args ), options );
}

function task( scope, title ) {
	return scope.locator( '.action-items-task', { hasText: title } );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Task workflow', () => {
	test( 'an editor writes meeting notes with three assigned tasks', async ( {
		page,
	} ) => {
		await logIn( page, ADMIN );

		for ( const user of USERS ) {
			user.id = (
				await apiFetch( page, {
					path: '/wp/v2/users',
					method: 'POST',
					data: {
						username: user.username,
						name: user.name,
						email: `${ user.username }@example.org`,
						password: PASSWORD,
						roles: [ user.role ],
					},
				} )
			).id;
		}

		await openEditor( page, '/wp-admin/post-new.php' );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas.getByRole( 'textbox', { name: 'Add title' } ).click();
		await page.keyboard.type( NOTES_TITLE );
		await page.keyboard.press( 'Enter' );

		// Insert the first Task block; Enter then adds the next ones.
		await page.keyboard.type( '/task' );
		await expect
			.poll(
				async () =>
					( await page
						.locator( '.components-autocomplete__result' )
						.count() ) +
					( await canvas
						.locator( '.components-autocomplete__result' )
						.count() )
			)
			.toBeGreaterThan( 0 );
		await page.keyboard.press( 'Enter' );
		for ( const [ index, title ] of TASKS.entries() ) {
			if ( index > 0 ) {
				await page.keyboard.press( 'Enter' );
			}
			await page.keyboard.type( title );
		}

		const blocks = canvas.locator( '.wp-block-action-items-task' );
		await expect( blocks ).toHaveCount( 3 );

		// Assign each task to a different user through the inline picker.
		for ( const [ index, user ] of USERS.entries() ) {
			const block = blocks.nth( index );
			await block.locator( '.action-items-task__title' ).click();
			await block.getByRole( 'button', { name: 'Assign' } ).click();
			await page
				.locator( '.action-items-assignee-picker input' )
				.fill( user.name );
			await page
				.getByRole( 'option', { name: new RegExp( user.name ) } )
				.click();
			await expect(
				block.getByRole( 'button', {
					name: `Assigned to: ${ user.name }`,
				} )
			).toBeVisible();
			await page.keyboard.press( 'Escape' );
		}

		// Give the first task a due date through the sidebar.
		await blocks.nth( 0 ).locator( '.action-items-task__title' ).click();
		await blocks
			.nth( 0 )
			.getByRole( 'button', { name: 'Set due date' } )
			.click();
		await page
			.locator( '.action-items-due-date-picker' )
			.getByRole( 'button', { name: /^(?!.*(previous|next)).*\b15\b/i } )
			.first()
			.click();
		await expect(
			blocks.nth( 0 ).getByRole( 'button', { name: /^Due / } )
		).toBeVisible();

		// Every block has its own identifier.
		const ids = await page.evaluate( () =>
			window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()
				.filter( ( block ) => block.name === 'action-items/task' )
				.map( ( block ) => block.attributes.taskId )
		);
		expect( new Set( ids ).size ).toBe( 3 );

		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await expect(
			page.getByTestId( 'snackbar' ).filter( { hasText: 'published' } )
		).toBeVisible();

		const post = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPost()
		);
		notesUrl = post.link;
		notesId = post.id;

		// Create the centralized Tasks page.
		const tasksPage = await apiFetch( page, {
			path: '/wp/v2/pages',
			method: 'POST',
			data: {
				title: `Tasks ${ RUN }`,
				status: 'publish',
				content: '<!-- wp:action-items/task-list {"perPage":100} /-->',
			},
		} );
		tasksUrl = tasksPage.link;
	} );

	test( 'anonymous visitors see all tasks, read-only', async ( {
		browser,
	} ) => {
		const page = await newUserPage( browser );
		await page.goto( tasksUrl );

		const list = page.locator( '.action-items-list' );
		await expect( list ).toHaveClass( /is-interactive/ );
		await expect(
			list.locator( '.action-items-list__results' )
		).not.toHaveAttribute( 'aria-busy', 'true' );

		for ( const [ index, title ] of TASKS.entries() ) {
			const item = task( list, title );
			await expect( item ).toBeVisible();
			await expect( item ).toContainText( USERS[ index ].name );
			await expect( item.getByRole( 'checkbox' ) ).toBeDisabled();
			await expect( item.getByRole( 'combobox' ) ).toBeHidden();
			await expect(
				item.getByRole( 'link', { name: NOTES_TITLE } )
			).toBeVisible();
		}
		await expect( task( list, TASKS[ 0 ] ) ).toContainText( /Due / );
		await expect(
			list.getByRole( 'button', { name: 'My Tasks' } )
		).toBeHidden();

		// The meeting notes are read-only too.
		await page.goto( notesUrl );
		await expect(
			page.locator( '.wp-block-action-items-task' )
		).toHaveCount( 3 );
		for ( const checkbox of await page
			.locator( '.wp-block-action-items-task input[type="checkbox"]' )
			.all() ) {
			await expect( checkbox ).toBeDisabled();
		}

		// Anonymous requests cannot change anything.
		const taskId = await page
			.locator( '.wp-block-action-items-task' )
			.first()
			.getAttribute( 'data-task-id' );
		const response = await page.request.post(
			`/?rest_route=/action-items/v1/tasks/${ taskId }`,
			{ data: { status: 'completed' } }
		);
		expect( response.status() ).toBe( 401 );

		await page.context().close();
	} );

	test( 'an assignee completes their task from the list without editing the post', async ( {
		browser,
	} ) => {
		const page = await newUserPage( browser, USERS[ 1 ] );
		await page.goto( tasksUrl );

		const list = page.locator( '.action-items-list' );

		// By default: only the viewer's own incomplete tasks.
		await expect(
			list.getByRole( 'button', { name: 'My Tasks' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( list.locator( '.action-items-task' ) ).toHaveCount( 1 );
		const mine = task( list, TASKS[ 1 ] );
		await expect( mine ).toBeVisible();

		// "All Tasks" shows the others, which this user cannot update.
		await list.getByRole( 'button', { name: 'All Tasks' } ).click();
		await expect( task( list, TASKS[ 0 ] ) ).toBeVisible();
		await expect(
			task( list, TASKS[ 0 ] ).getByRole( 'checkbox' )
		).toBeDisabled();
		await expect(
			task( list, TASKS[ 1 ] ).getByRole( 'checkbox' )
		).toBeEnabled();

		// The contributor filter narrows the list to one person.
		await list
			.locator( '[data-filter="assignee"]' )
			.selectOption( { label: USERS[ 2 ].name } );
		await expect( task( list, TASKS[ 2 ] ) ).toBeVisible();
		await expect( task( list, TASKS[ 1 ] ) ).toHaveCount( 0 );

		await list.getByRole( 'button', { name: 'My Tasks' } ).click();
		await expect( list.locator( '.action-items-task' ) ).toHaveCount( 1 );

		// This user cannot edit the meeting notes.
		const canEdit = await page.evaluate( async ( id ) => {
			const response = await fetch(
				`/?rest_route=/wp/v2/posts/${ id }&context=edit`,
				{ headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } }
			);
			return response.ok;
		}, notesId );
		expect( canEdit ).toBe( false );

		// Complete the task with the keyboard.
		await mine.getByRole( 'checkbox' ).focus();
		await page.keyboard.press( 'Space' );
		await expect( mine ).toHaveClass( /is-status-completed/ );
		await expect( mine ).not.toHaveAttribute( 'aria-busy', 'true' );

		await page.reload();
		await expect( list ).toContainText(
			'No incomplete tasks assigned to you.'
		);

		// The original meeting notes show the task as completed.
		await page.goto( notesUrl );
		const inNotes = task( page, TASKS[ 1 ] );
		await expect( inNotes ).toHaveClass( /is-status-completed/ );
		await expect( inNotes.getByRole( 'checkbox' ) ).toBeChecked();
		await expect( inNotes.getByRole( 'checkbox' ) ).toBeEnabled();
		await expect( task( page, TASKS[ 0 ] ) ).toHaveClass(
			/is-status-not-started/
		);
		await expect(
			task( page, TASKS[ 0 ] ).getByRole( 'checkbox' )
		).toBeDisabled();

		await page.context().close();
	} );

	test( 'an assignee updates their task from the meeting notes and the list follows', async ( {
		browser,
	} ) => {
		const page = await newUserPage( browser, USERS[ 2 ] );
		await page.goto( notesUrl );

		const inNotes = task( page, TASKS[ 2 ] );
		await expect( inNotes.getByRole( 'combobox' ) ).toBeEnabled();
		await inNotes.getByRole( 'combobox' ).selectOption( 'in-progress' );
		await expect( inNotes ).toHaveClass( /is-status-in-progress/ );
		await expect( inNotes ).not.toHaveAttribute( 'aria-busy', 'true' );

		await page.goto( tasksUrl );
		const inList = task( page.locator( '.action-items-list' ), TASKS[ 2 ] );
		await expect( inList ).toHaveClass( /is-status-in-progress/ );
		await expect( inList.getByRole( 'combobox' ) ).toHaveValue(
			'in-progress'
		);

		await page.context().close();
	} );

	test( 'editing the meeting notes keeps the status set by assignees', async ( {
		page,
	} ) => {
		await logIn( page, ADMIN );
		await openEditor(
			page,
			`/wp-admin/post.php?post=${ notesId }&action=edit`
		);

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const blocks = canvas.locator( '.wp-block-action-items-task' );
		await expect( blocks ).toHaveCount( 3 );

		// The editor shows the live status, although the saved content says "not started".
		await expect( blocks.nth( 1 ) ).toHaveClass( /is-status-completed/ );
		await expect( blocks.nth( 2 ) ).toHaveClass( /is-status-in-progress/ );
		const savedStatuses = await page.evaluate( () => {
			const { content } = window.wp.data
				.select( 'core/editor' )
				.getCurrentPost();
			return ( content.raw ?? content ).match( /"status":"[a-z-]+"/g );
		} );
		expect( savedStatuses ).toBeNull();

		// A status change in the editor is saved right away, without saving the post.
		const statusRequest = page.waitForResponse(
			( response ) =>
				response.url().includes( 'action-items' ) &&
				response.request().method() === 'POST'
		);
		await blocks.nth( 2 ).getByRole( 'checkbox' ).click();
		expect( ( await statusRequest ).status() ).toBe( 200 );
		await expect( blocks.nth( 2 ) ).toHaveClass( /is-status-completed/ );

		// Rename the first task and update the post.
		await blocks.nth( 0 ).locator( '.action-items-task__title' ).click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' (updated)' );
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save', exact: true } )
			.click();
		await expect(
			page.getByTestId( 'snackbar' ).filter( { hasText: 'updated' } )
		).toBeVisible();

		await page.goto( tasksUrl );
		const list = page.locator( '.action-items-list' );
		await list.getByRole( 'button', { name: 'All Tasks' } ).click();
		await list.locator( '[data-filter="status"]' ).selectOption( 'all' );

		// Other tasks may exist on the site; only look at the ones of this run.
		await expect( task( list, RUN ) ).toHaveCount( 3 );
		await expect( task( list, `${ TASKS[ 0 ] } (updated)` ) ).toHaveClass(
			/is-status-not-started/
		);
		await expect( task( list, TASKS[ 1 ] ) ).toHaveClass(
			/is-status-completed/
		);
		await expect( task( list, TASKS[ 2 ] ) ).toHaveClass(
			/is-status-completed/
		);

		// An editor can reopen a task from the list; the notes follow.
		await task( list, TASKS[ 1 ] ).getByRole( 'checkbox' ).uncheck();
		await expect( task( list, TASKS[ 1 ] ) ).toHaveClass(
			/is-status-not-started/
		);
		await expect( task( list, TASKS[ 1 ] ) ).not.toHaveAttribute(
			'aria-busy',
			'true'
		);
		await page.goto( notesUrl );
		await expect( task( page, TASKS[ 1 ] ) ).toHaveClass(
			/is-status-not-started/
		);
	} );
	test( 'a contributor can assign tasks in a draft, which stays out of the public list', async ( {
		browser,
	} ) => {
		const page = await newUserPage( browser, USERS[ 0 ] );
		const draftTask = `Draft only task ${ RUN }`;

		await openEditor( page, '/wp-admin/post-new.php' );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas.getByRole( 'textbox', { name: 'Add title' } ).click();
		await page.keyboard.type( `Draft notes ${ RUN }` );
		await page.keyboard.press( 'Enter' );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlock(
					window.wp.blocks.createBlock( 'action-items/task' )
				)
		);

		const block = canvas.locator( '.wp-block-action-items-task' );
		await block.locator( '.action-items-task__title' ).click();
		await page.keyboard.type( draftTask );

		// Contributors cannot list users in WordPress, but can search assignees.
		await block.getByRole( 'button', { name: 'Assign' } ).click();
		await page
			.locator( '.action-items-assignee-picker input' )
			.fill( USERS[ 1 ].name );
		await page
			.getByRole( 'option', { name: new RegExp( USERS[ 1 ].name ) } )
			.click();
		await expect(
			block.getByRole( 'button', {
				name: `Assigned to: ${ USERS[ 1 ].name }`,
			} )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );

		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save draft' } )
			.click();
		await expect(
			page.getByTestId( 'snackbar' ).filter( { hasText: 'Draft saved' } )
		).toBeVisible();

		// Neither the public list nor the assignee's own list shows the draft task.
		const anonymous = await newUserPage( browser );
		await anonymous.goto( tasksUrl );
		await expect(
			anonymous.locator( '.action-items-list.is-interactive' )
		).toBeVisible();
		await expect( task( anonymous, TASKS[ 0 ] ) ).toBeVisible();
		await expect( task( anonymous, draftTask ) ).toHaveCount( 0 );
		const response = await anonymous.request.get(
			'/?rest_route=/action-items/v1/tasks&per_page=100&status=not-started,in-progress,completed'
		);
		expect( JSON.stringify( await response.json() ) ).not.toContain(
			draftTask
		);

		await anonymous.context().close();
		await page.context().close();
	} );
} );

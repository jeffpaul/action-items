<?php
/**
 * Tests for the synchronization between post content and the task tables.
 *
 * @package ActionItems
 */

use ActionItems\Tasks;

/**
 * @covers \ActionItems\Sync
 * @covers \ActionItems\Tasks
 */
class Sync_Test extends Action_Items_Test_Case {

	public function test_publishing_a_post_creates_its_tasks() {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Review the <strong>proposal</strong>',
					'assignees' => array( $alice, $bob ),
					'dueDate'   => '2030-11-13',
				),
				array(
					'taskId' => self::ID_B,
					'title'  => 'Update the documentation',
					'status' => 'in-progress',
				),
			)
		);

		$tasks = Tasks::get_by_post( $post_id );

		$this->assertSame( array( self::ID_A, self::ID_B ), array_keys( $tasks ) );

		$first = $tasks[ self::ID_A ];
		$this->assertSame( $post_id, $first->post_id );
		$this->assertSame( 'Review the <strong>proposal</strong>', $first->title );
		$this->assertSame( 'not-started', $first->status );
		$this->assertSame( '2030-11-13', $first->due_date );
		$this->assertSame( array( $alice, $bob ), $first->assignees );
		$this->assertNull( $first->completed_at );

		$this->assertSame( 'in-progress', $tasks[ self::ID_B ]->status );
		$this->assertNull( $tasks[ self::ID_B ]->due_date );
		$this->assertSame( array(), $tasks[ self::ID_B ]->assignees );
	}

	public function test_updating_a_post_updates_details_without_duplicating_tasks() {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'First title',
					'assignees' => array( $alice ),
					'dueDate'   => '2030-01-01',
				),
			)
		);
		$created = Tasks::get( self::ID_A );

		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId'    => self::ID_A,
						'title'     => 'Second title',
						'assignees' => array( $bob ),
					)
				),
			)
		);

		$this->assertSame( 1, $this->count_tasks() );
		$this->assertSame( 1, $this->count_assignee_rows() );

		$task = Tasks::get( self::ID_A );
		$this->assertSame( $created->id, $task->id );
		$this->assertSame( 'Second title', $task->title );
		$this->assertNull( $task->due_date );
		$this->assertSame( array( $bob ), $task->assignees );
		$this->assertSame( $created->created_at, $task->created_at );
	}

	public function test_saving_a_post_with_a_stale_status_does_not_overwrite_the_task_status() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task',
					'status' => 'not-started',
				),
			)
		);

		$this->complete( self::ID_A );

		// The content still says "not-started", and an editor may even send another stale value.
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId' => self::ID_A,
						'title'  => 'Task, edited',
						'status' => 'in-progress',
					)
				),
			)
		);

		$task = Tasks::get( self::ID_A );
		$this->assertSame( 'completed', $task->status );
		$this->assertNotNull( $task->completed_at );
		$this->assertSame( 'Task, edited', $task->title );
	}

	public function test_removing_a_block_deletes_its_task_and_assignees() {
		$alice = self::factory()->user->create();

		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Keep',
					'assignees' => array( $alice ),
				),
				array(
					'taskId'    => self::ID_B,
					'title'     => 'Remove',
					'assignees' => array( $alice ),
				),
			)
		);

		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId'    => self::ID_A,
						'title'     => 'Keep',
						'assignees' => array( $alice ),
					)
				),
			)
		);

		$this->assertNotNull( Tasks::get( self::ID_A ) );
		$this->assertNull( Tasks::get( self::ID_B ) );
		$this->assertSame( 1, $this->count_assignee_rows() );
	}

	public function test_blocks_without_an_identifier_get_one() {
		$post_id = $this->create_post_with_tasks(
			array(
				array( 'title' => 'No identifier' ),
			)
		);

		$blocks = $this->get_content_tasks( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertTrue( Tasks::is_uuid( $blocks[0]['taskId'] ) );
		$this->assertSame( 'No identifier', $blocks[0]['title'] );
		$this->assertNotNull( Tasks::get( $blocks[0]['taskId'] ) );
	}

	public function test_block_without_any_attributes_gets_an_identifier() {
		$post_id = $this->create_post(
			array( 'post_content' => '<!-- wp:action-items/task /-->' )
		);

		$blocks = $this->get_content_tasks( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertTrue( Tasks::is_uuid( $blocks[0]['taskId'] ) );
	}

	public function test_invalid_identifiers_are_replaced() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => 'not-a-uuid" onclick="alert(1)',
					'title'  => 'Invalid identifier',
				),
				array(
					'taskId' => 42,
					'title'  => 'Numeric identifier',
				),
			)
		);

		$blocks = $this->get_content_tasks( $post_id );
		$this->assertCount( 2, $blocks );
		foreach ( $blocks as $block ) {
			$this->assertTrue( Tasks::is_uuid( $block['taskId'] ) );
		}
		$this->assertSame( 'Invalid identifier', $blocks[0]['title'] );
		$this->assertSame( 2, $this->count_tasks() );
	}

	public function test_duplicated_block_in_the_same_post_becomes_a_separate_task() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Original',
				),
				array(
					'taskId' => self::ID_A,
					'title'  => 'Copy',
				),
			)
		);

		$blocks = $this->get_content_tasks( $post_id );
		$this->assertSame( self::ID_A, $blocks[0]['taskId'] );
		$this->assertNotSame( self::ID_A, $blocks[1]['taskId'] );
		$this->assertTrue( Tasks::is_uuid( $blocks[1]['taskId'] ) );

		$tasks = Tasks::get_by_post( $post_id );
		$this->assertCount( 2, $tasks );
		$this->assertSame( 'Original', $tasks[ self::ID_A ]->title );
		$this->assertSame( 'Copy', $tasks[ $blocks[1]['taskId'] ]->title );
	}

	public function test_duplicating_a_block_of_a_saved_task_copies_its_current_status() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Original',
				),
			)
		);
		$this->complete( self::ID_A );

		$block = $this->task_block(
			array(
				'taskId' => self::ID_A,
				'title'  => 'Original',
			)
		);
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $block . "\n\n" . $block,
			)
		);

		$tasks = array_values( Tasks::get_by_post( $post_id ) );
		$this->assertCount( 2, $tasks );
		$this->assertSame( 'completed', $tasks[0]->status );
		$this->assertSame( 'completed', $tasks[1]->status );
	}

	public function test_block_copied_to_another_post_becomes_a_separate_task() {
		$alice = self::factory()->user->create();
		$task  = array(
			'taskId'    => self::ID_A,
			'title'     => 'Task',
			'assignees' => array( $alice ),
		);

		$original_post = $this->create_post_with_tasks( array( $task ) );
		$copied_post   = $this->create_post_with_tasks( array( $task ) );

		$this->assertSame( $original_post, Tasks::get( self::ID_A )->post_id );

		$copies = Tasks::get_by_post( $copied_post );
		$this->assertCount( 1, $copies );
		$copy = reset( $copies );
		$this->assertNotSame( self::ID_A, $copy->uuid );
		$this->assertSame( array( $alice ), $copy->assignees );
		$this->assertSame( $copy->uuid, $this->get_content_tasks( $copied_post )[0]['taskId'] );

		// Completing the copy leaves the original alone.
		$this->complete( $copy->uuid );
		$this->assertSame( 'not-started', Tasks::get( self::ID_A )->status );

		// Saving the original post again does not take the copy over, nor the other way around.
		$this->update_post( array( 'ID' => $original_post ) );
		$this->update_post( array( 'ID' => $copied_post ) );
		$this->assertSame( 2, $this->count_tasks() );
		$this->assertSame( $original_post, Tasks::get( self::ID_A )->post_id );
		$this->assertSame( 'completed', Tasks::get( $copy->uuid )->status );
	}

	public function test_identifier_rewrite_leaves_the_rest_of_the_content_untouched() {
		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Owner of the identifier',
				),
			)
		);

		$before = "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Notes &amp; “quotes” -- dashes</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">";
		$after  = "</div>\n<!-- /wp:group -->\n\n<!-- wp:action-items/task-list {\"perPage\":5} /-->\n\n<!-- wp:html -->\n<p>taskId: " . self::ID_A . "</p>\n<!-- /wp:html -->";
		$block  = '<!-- wp:action-items/task {"title":"Caf' . self::U . '00e9 ' . self::U . '003cem' . self::U . '003eau lait' . self::U . '003c/em' . self::U . '003e","taskId":"' . self::ID_A . '","assignees":[],"dueDate":"2030-05-01"} /-->';
		$this->act_as_unfiltered_user();
		$post_id = $this->create_post( array( 'post_content' => $before . $block . $after ) );

		$content = get_post( $post_id )->post_content;
		$blocks  = $this->get_content_tasks( $post_id );
		$new_id  = $blocks[0]['taskId'];

		$this->assertNotSame( self::ID_A, $new_id );
		$this->assertSame( $before . str_replace( '"taskId":"' . self::ID_A . '"', '"taskId":"' . $new_id . '"', $block ) . $after, $content );
	}

	public function test_content_without_conflicts_is_saved_unchanged() {
		$content = "<!-- wp:paragraph -->\n<p>It's a \\\"test\\\" with a backslash \\ and émojis 🎉</p>\n<!-- /wp:paragraph -->\n\n"
			. '<!-- wp:action-items/task {"taskId":"' . self::ID_A . '","title":"A ' . self::U . '0022quoted' . self::U . '0022 title ' . self::U . '0026 more"} /-->';

		$this->act_as_unfiltered_user();
		$post_id = $this->create_post( array( 'post_content' => $content ) );

		$this->assertSame( $content, get_post( $post_id )->post_content );
		$this->assertSame( 'A "quoted" title &amp; more', Tasks::get( self::ID_A )->title );
	}

	public function test_nested_task_blocks_are_found() {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. $this->task_block(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Nested',
				)
			)
			. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';

		$post_id = $this->create_post( array( 'post_content' => $content ) );

		$this->assertSame( $post_id, Tasks::get( self::ID_A )->post_id );
	}

	public function test_revisions_and_autosaves_do_not_touch_tasks() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Published title',
				),
			)
		);

		// An autosave of a published post is stored as a revision.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$autosave_id = wp_create_post_autosave(
			array(
				'post_ID'      => $post_id,
				'post_type'    => 'post',
				'post_title'   => 'Autosaved',
				'post_content' => $this->task_block(
					array(
						'taskId' => self::ID_B,
						'title'  => 'Only in the autosave',
					)
				),
				'post_excerpt' => '',
			)
		);

		$this->assertIsInt( $autosave_id );
		$this->assertSame( 'revision', get_post_type( $autosave_id ) );
		$this->assertSame( 1, $this->count_tasks() );
		$this->assertSame( 'Published title', Tasks::get( self::ID_A )->title );
		$this->assertNull( Tasks::get( self::ID_B ) );

		// The autosave keeps its content as it was submitted.
		$this->assertSame( self::ID_B, $this->get_content_tasks( $autosave_id )[0]['taskId'] );
	}

	public function test_restoring_a_revision_keeps_the_status_of_surviving_tasks() {
		$first_content = $this->task_block(
			array(
				'taskId' => self::ID_A,
				'title'  => 'A',
			)
		) . $this->task_block(
			array(
				'taskId' => self::ID_B,
				'title'  => 'B',
			)
		);

		$post_id = $this->create_post(
			array(
				'post_status'  => 'publish',
				'post_content' => $first_content,
			)
		);
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $first_content . ' ',
			)
		);
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId' => self::ID_A,
						'title'  => 'A, renamed',
					)
				),
			)
		);

		$this->complete( self::ID_A );
		$this->assertNull( Tasks::get( self::ID_B ) );

		$revisions = wp_get_post_revisions( $post_id, array( 'order' => 'ASC' ) );
		$this->assertGreaterThanOrEqual( 2, count( $revisions ) );
		wp_restore_post_revision( reset( $revisions )->ID );

		$tasks = Tasks::get_by_post( $post_id );
		$this->assertCount( 2, $tasks );
		$this->assertSame( 'A', $tasks[ self::ID_A ]->title );
		$this->assertSame( 'completed', $tasks[ self::ID_A ]->status );
		$this->assertSame( 'not-started', $tasks[ self::ID_B ]->status );
	}

	public function test_trashing_hides_tasks_and_restoring_brings_them_back_with_their_status() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task',
				),
			)
		);
		$this->complete( self::ID_A );
		$all = array( 'status' => array_keys( Tasks::get_statuses() ) );

		$this->assertSame( 1, Tasks::query( $all )['total'] );

		wp_trash_post( $post_id );
		$this->assertSame( 0, Tasks::query( $all )['total'] );
		$this->assertNotNull( Tasks::get( self::ID_A ), 'The task is kept while its post is in the trash.' );

		wp_untrash_post( $post_id );
		$this->assertSame( 0, Tasks::query( $all )['total'], 'Restored posts are drafts.' );

		wp_publish_post( $post_id );
		$result = Tasks::query( $all );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'completed', $result['items'][0]->status );
	}

	public function test_permanently_deleting_a_post_deletes_its_tasks() {
		$alice   = self::factory()->user->create();
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task',
					'assignees' => array( $alice ),
				),
			)
		);
		$other   = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_B,
					'title'  => 'Other',
				),
			)
		);

		wp_delete_post( $post_id, true );

		$this->assertNull( Tasks::get( self::ID_A ) );
		$this->assertSame( 0, $this->count_assignee_rows() );
		$this->assertSame( $other, Tasks::get( self::ID_B )->post_id );
	}

	public function test_tasks_of_unpublished_posts_are_stored_but_not_public() {
		foreach ( array( 'draft', 'pending', 'private', 'future' ) as $index => $status ) {
			$this->create_post_with_tasks(
				array(
					array(
						'taskId' => wp_generate_uuid4(),
						'title'  => 'Task of a ' . $status . ' post',
					),
				),
				array(
					'post_status' => $status,
					'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) : '',
				)
			);
		}
		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => wp_generate_uuid4(),
					'title'  => 'Task of a password protected post',
				),
			),
			array( 'post_password' => 'secret' )
		);

		$this->assertSame( 5, $this->count_tasks() );
		$this->assertSame( 0, Tasks::query()['total'] );
		$this->assertSame( 5, Tasks::query( array( 'public_only' => false ) )['total'] );
	}

	public function test_publishing_a_draft_makes_its_tasks_public() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task',
				),
			),
			array( 'post_status' => 'draft' )
		);

		$this->assertSame( 0, Tasks::query()['total'] );

		wp_publish_post( $post_id );

		$this->assertSame( 1, Tasks::query()['total'] );
		$this->assertSame( 1, $this->count_tasks() );
	}

	public function test_unsupported_post_types_are_ignored() {
		register_post_type( 'mt_internal', array( 'public' => false ) );

		$this->create_post(
			array(
				'post_type'    => 'mt_internal',
				'post_status'  => 'publish',
				'post_content' => $this->task_block(
					array(
						'taskId' => self::ID_A,
						'title'  => 'Internal',
					)
				),
			)
		);

		$this->assertSame( 0, $this->count_tasks() );

		unregister_post_type( 'mt_internal' );
	}

	public function test_invalid_assignees_dates_and_markup_are_sanitized() {
		$alice = self::factory()->user->create();

		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Call <a href="https://example.org/" onclick="x()">them</a><script>alert(1)</script><img src=x onerror=alert(1)>',
					'assignees' => array( $alice, $alice, 999999, 'abc', -1 ),
					'dueDate'   => '2030-02-31',
					'status'    => 'bogus',
				),
			)
		);

		$task = Tasks::get( self::ID_A );
		$this->assertSame( 'Call <a href="https://example.org/">them</a>alert(1)', $task->title );
		$this->assertSame( array( $alice ), $task->assignees );
		$this->assertNull( $task->due_date );
		$this->assertSame( 'not-started', $task->status );
	}

	public function test_deleting_a_user_removes_their_assignments() {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task',
					'assignees' => array( $alice, $bob ),
				),
			)
		);

		if ( is_multisite() ) {
			wpmu_delete_user( $alice );
		} else {
			wp_delete_user( $alice );
		}

		$this->assertSame( array( $bob ), Tasks::get( self::ID_A )->assignees );
	}

	public function test_block_with_unreadable_attributes_is_left_alone() {
		$this->act_as_unfiltered_user();
		$content = '<!-- wp:action-items/task {"title":broken} /-->';
		$post_id = $this->create_post( array( 'post_content' => $content ) );

		$this->assertSame( $content, get_post( $post_id )->post_content );
		$this->assertSame( 0, $this->count_tasks() );
	}

	public function test_actions_fire_for_created_tasks_and_changed_assignees() {
		$alice   = self::factory()->user->create();
		$bob     = self::factory()->user->create();
		$created = array();
		$changed = array();

		add_action(
			'action_items_task_created',
			static function ( $task ) use ( &$created ) {
				$created[] = $task->uuid;
			}
		);
		add_action(
			'action_items_assignees_changed',
			static function ( $task, $added, $removed ) use ( &$changed ) {
				$changed[] = array( $task->uuid, $added, $removed );
			},
			10,
			3
		);

		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task',
					'assignees' => array( $alice ),
				),
			)
		);
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId'    => self::ID_A,
						'title'     => 'Task',
						'assignees' => array( $bob ),
					)
				),
			)
		);

		$this->assertSame( array( self::ID_A ), $created );
		$this->assertSame( array( array( self::ID_A, array( $bob ), array( $alice ) ) ), $changed );
	}
}

# Data model and synchronization

This document explains where task data lives, which store is authoritative for each property, and how the stores are kept in sync. It is the reference for anyone changing `includes/class-sync.php`, `includes/class-tasks.php` or the block attributes.

## The problem

A task is written inside a post, as a block. It must also:

- be listed and filtered outside of that post, efficiently;
- have a status that people who cannot edit the post are able to change;
- never have that status overwritten by an editor who saves an older copy of the post;
- disappear from public view when its post is not public.

Post content alone cannot do this: changing it requires `edit_post`, creates revisions, and races with open editor sessions. A separate store alone cannot either: the block must remain a normal, movable, copyable part of the post. So the plugin uses both, and gives each property exactly one owner.

## Stores

### 1. Block attributes, in post content

```html
<!-- wp:action-items/task {"taskId":"0b0f6b53-5a0b-4d0c-9f0e-0d6c6f3f4a11","title":"Review the proposal","assignees":[12,34],"dueDate":"2026-11-13"} /-->
```

| Attribute | Type | Meaning |
| --- | --- | --- |
| `taskId` | string, UUID v4 | Immutable identifier of the task. |
| `title` | string, HTML | Task text. Allowed inline tags: `a`, `strong`, `em`, `code`, `s`. |
| `assignees` | integer[] | WordPress user IDs. |
| `dueDate` | string, `YYYY-MM-DD` | Optional. |
| `status` | `not-started`, `in-progress`, `completed` | Initial status only, see below. |

The block has no inner HTML (`save` returns `null`); it is rendered by PHP.

### 2. Task tables, one pair per site

`{prefix}action_items`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, primary key | Internal row ID, used for joins. Never exposed. |
| `task_uuid` | char(36), unique | The `taskId` of the block. Public identifier. |
| `post_id` | bigint unsigned, indexed | Post containing the block. |
| `title` | text | Sanitized HTML, mirrored from the block. |
| `status` | varchar(20) | Authoritative. |
| `due_date` | date, nullable | Mirrored from the block. |
| `position` | int unsigned | Order of the block within the post. |
| `created_at` | datetime (GMT) | When the row was created. Used for sorting. |
| `updated_at` | datetime (GMT) | Last change of any column. |
| `completed_at` | datetime (GMT), nullable | Set when the status becomes `completed`, cleared otherwise. |
| `status_changed_by` | bigint unsigned, nullable | User who last changed the status. |

Indexes: `PRIMARY (id)`, `UNIQUE task_uuid (task_uuid)`, `post_id (post_id)`, `status_due (status, due_date, created_at)`.

`{prefix}action_item_assignees`

| Column | Type |
| --- | --- |
| `task_id` | bigint unsigned |
| `user_id` | bigint unsigned |

Indexes: `PRIMARY (task_id, user_id)`, `user_id (user_id)`.

The schema version is stored in the `action_items_db_version` option of each site. `Schema::maybe_install()` compares it on `init` and before syncing a post, and runs `dbDelta()` when it differs. That is how tables appear on sites of a network without iterating over it.

### Why custom tables

| Option | Why not |
| --- | --- |
| Post meta on the source post | A post has many tasks; filtering tasks by assignee and status across posts is not expressible, and writing meta requires `edit_post`. |
| Custom post type, one post per task | Works, but assignees, status and due date become meta or terms. “My incomplete tasks, by due date, then creation date, page 2” needs several joins on `postmeta` plus a join to the parent post for visibility. Task posts would also have to be kept out of search, feeds, sitemaps, exports and REST post collections, and mirroring the parent's status onto each task is one more thing to keep in sync. |
| Custom tables (chosen) | One indexed query per list, visibility decided by a primary-key join to `wp_posts`, nothing leaks into post queries, and the site prefix isolates sites. The cost is schema management, which is limited to two small tables and one version option. |

## Who owns what

| Property | Owner | How the other side follows |
| --- | --- | --- |
| Identifier | Block attribute | Stored as `task_uuid` when the row is created. Never changes afterwards. |
| Title, assignees, due date, position | Block attributes | Copied to the tables on every save of the post. The tables are never edited directly. |
| Status, completion date, last actor | Tasks table | Read at render time. The block attribute is not updated when the status changes. |
| Owning post | Tasks table | Set when the row is created. A block that shows up in another post gets a new identifier instead of moving the row. |
| Visibility | The post | Not stored. Public queries join `wp_posts` and require `post_status = 'publish'`, an empty `post_password` and a supported post type. |

### The `status` attribute

The block keeps a `status` attribute for one purpose: a task that has no row yet (new block, unsaved post) needs somewhere to hold the status chosen in the editor. When the row is created, that value becomes the initial status. From then on the attribute is ignored on the server.

The editor also writes the attribute when a user changes the status of a saved task, so that the content stays roughly in line, but nothing relies on it.

## Synchronization

### Before a post is saved: unique identifiers

`Sync::ensure_unique_ids()` runs on `wp_insert_post_data` for supported post types whose content contains a Task block. A block gets a new identifier when:

- it has no `taskId`, or one that is not a lowercase UUID v4;
- an earlier block of the same content already uses it (block duplicated within the post);
- the identifier belongs to a task of another post (block copied to another post, or post duplicated by a plugin).

The first block that uses an identifier keeps it. Because this happens before the insert, the saved content already carries the final identifiers: there is no second update and no extra revision.

Only the `taskId` member of the affected block's attribute JSON is edited, in place. Other blocks and the rest of the content are not parsed and re-serialized by the plugin. Blocks whose attributes are not valid JSON are left alone.

A task created from a duplicate starts with the current status of the task it was copied from, when that task is known during the same request. This makes “move a block to another post” and “duplicate a block” keep the completion state.

The editor does the same thing client-side as soon as a block is duplicated or pasted, so identifiers are normally already unique when the content reaches the server. The server-side check is what guarantees it for other clients, imports and post duplication plugins.

### After a post is saved: mirror the blocks

`Sync::sync_post()` runs on `save_post`:

1. Skip revisions (which include the autosaves of published posts) and unsupported post types.
2. Parse the saved content and collect Task blocks, including nested ones, in document order.
3. For each block:
   - row exists for this post: update title, due date, position and assignees when they changed. **The status is not touched.**
   - no row: create one with the initial status. If the identifier already belongs to another post, skip the block.
4. Delete the rows of this post whose block is gone.

Titles are sanitized with a small KSES allow-list, due dates must be real dates, and assignees must be assignable users; invalid values are dropped from the tables but the content is not modified.

This runs for every post status, so that tasks exist as soon as a draft is saved and the editor can show and change their status. Visibility is enforced when reading.

### Status changes

`POST /action-items/v1/tasks/<id>` with `{ "status": "completed" }` updates one row after the permission check. The post is not saved, no revision is created, and `post_modified` does not change. The Task block and every Task List read the new status on their next render or request.

### Post lifecycle

| Event | Effect on tasks |
| --- | --- |
| Draft saved | Rows created or updated. Not public. |
| Autosave of a draft by its author | Same as saving: WordPress updates the post itself. |
| Autosave of a published post, or by another user | Stored as a revision: ignored. |
| Published | Rows become public through the join. Nothing is written. |
| Updated | Details mirrored, new blocks created, removed blocks deleted. Statuses kept. |
| Unpublished, made private, password protected | Rows kept, hidden from public queries. Assignees who cannot edit the post lose access. |
| Trashed | Rows kept, hidden. |
| Restored from the trash | Rows visible again once the post is published, with their status. |
| Permanently deleted | Rows and assignee rows deleted (`deleted_post`). |
| Revision restored | Treated as an update: surviving tasks keep their status, blocks that come back are created again with the status stored in the revision. |
| Post duplicated by another plugin | The copy's blocks get new identifiers and become separate tasks. |
| User deleted | Their rows in the assignees table of the current site are deleted. The block attribute keeps the stale ID, which is ignored. |
| Site deleted (multisite) | Both tables are dropped with the site (`wpmu_drop_tables`). |
| Plugin uninstalled | Tables and the version option are removed from every site. |

### Editor behavior

The editor keeps a small data store (`action-items/editor`) with the server-side state of the tasks of the post being edited. It is loaded when the first Task block mounts and reloaded after every save.

- The status shown for a block is the server-side status when the task exists, the attribute otherwise.
- Changing the status of an existing task sends the REST request immediately.
- A block that shares its identifier with an earlier block in the document gets a new one.

## Caching

- **Object cache.** `Tasks::query()` and the contributor list are cached in the `action-items` group. Keys include the `last_changed` value of that group and of the `posts` group, so any task write or post change invalidates them. The group is per site.
- **Page cache.** Block output is identical for every visitor. Personalized data only travels in REST responses to authenticated requests, for which WordPress sends no-cache headers. A stale cached page is corrected in the browser: the frontend script reloads the status of the Task blocks and the content of Task Lists.
- **Nonces.** The frontend uses `wp.apiFetch`, which refreshes an expired or foreign nonce found in a cached page and retries.

## Extending

For notifications, reminders or reports, use the actions `action_items_task_created`, `action_items_assignees_changed`, `action_items_status_changed` and `action_items_task_deleted`, together with `Tasks::query()`. Before notifying anyone, check `Permissions::is_post_public()`: tasks exist for drafts too. An overdue reminder is a scheduled event that queries incomplete tasks with a past `due_date`; the `status_due` index covers it. None of this needs a schema change.

When the schema does change, bump `Schema::DB_VERSION`; `dbDelta()` applies the difference on each site the next time the plugin runs there.

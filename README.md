# Action Items

Task blocks for meeting notes, with a centralized task list that stays in sync with the original posts.

Action Items is a WordPress plugin built for the WordPress.org Make network (first target: `make.wordpress.org/ai`) and for any self-hosted WordPress site. It is an independent implementation of the workflow offered by the WordPress.com P2 Task block, written against public WordPress APIs only.

1. A contributor writes meeting notes in the block editor and adds **Task** blocks: a title, one or more assignees, an optional due date.
2. Once the post is published, the tasks show up in every **Task List** block of the site, for instance on a `/tasks/` page.
3. Logged-in visitors see their own incomplete tasks first. They can switch to all tasks, filter by contributor or status, and mark tasks complete.
4. A status changed in the list shows in the meeting notes, and the other way around. Assignees do not need to be able to edit the post.

The meeting notes remain the permanent home of each task: every list item links back to the task inside its post.

- License: GPL-2.0-or-later
- Requires WordPress 6.5 or later and PHP 7.4 or later
- No external services, no settings screen, no custom admin pages

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Permissions](#permissions)
- [Architecture](#architecture)
- [REST API](#rest-api)
- [Hooks](#hooks)
- [Development and testing](#development-and-testing)
- [What has been verified](#what-has-been-verified)
- [Known limitations](#known-limitations)
- Further reading: [data model](docs/data-model.md), [WordPress.org Meta deployment](docs/meta-deployment.md), [manual testing](docs/manual-testing.md)

## Installation

### From a release zip

1. Build the zip (`npm ci && npm run build && npm run plugin-zip`) or take `action-items.zip` from a release.
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the zip and activate **Action Items**.

### From source

```sh
cd wp-content/plugins
git clone https://github.com/jeffpaul/action-items.git
cd action-items
npm ci
npm run build
wp plugin activate action-items
```

The `build/` directory is not committed. Without it the plugin shows an admin notice and registers no blocks.

### Multisite

Activate the plugin per site or network-wide. Each site gets its own two tables, created the first time the plugin runs on that site, so network activation does not loop over the network. Tasks never cross site boundaries.

## Usage

### Tasks in meeting notes

1. Edit a post and add a **Task** block where the action items go (type `/task`).
2. Type the task. Press Enter to add the next task; press Enter on an empty task to go back to a paragraph.
3. With the task selected, use **Assign** below the title (or the block sidebar) to search users and pick one or more assignees. Use **Set due date** for an optional due date.
4. The checkbox, the block toolbar and the sidebar change the status: Not started, In progress, Completed.
5. Publish the post.

A meeting post then looks like this, each line being an interactive Task block:

```
Action Items

☐ Review AI provider integration proposal
  Contributor One · Due Nov 13
☐ Update MCP Adapter documentation
  Contributor Two · Due Nov 18
☑ Publish contributor onboarding checklist
  Completed · Contributor Three
```

Duplicating, copying and moving Task blocks works as expected: every copy becomes its own task, with its own identifier. Deleting a block and saving the post deletes the task.

Bold, italic, strikethrough, inline code and links are allowed in task titles.

### The Tasks page

1. Create a page, for instance “Tasks” with the slug `tasks`.
2. Add the **Task List** block and publish.

The block lists the tasks of all published posts of the current site:

- Logged-in visitors start on **My Tasks**: their own incomplete tasks. **All Tasks** shows everyone's.
- The **Contributor** filter lists the tasks of one person; the **Status** filter switches between incomplete (default), each single status, and all tasks including completed ones.
- Incomplete tasks are sorted by due date (tasks without a due date last), then by creation date. Completed tasks come last, most recently completed first.
- Each task shows its status, assignees, due date and a link to the task inside its post.
- Visitors who are allowed to update a task get a checkbox and a status menu. Everyone else, including logged-out visitors, gets a read-only list.

Block settings (sidebar): default view for logged-in users (My Tasks or All Tasks), tasks per page (default 20), and whether to show the contributor and status filters.

## Permissions

| Action | Who |
| --- | --- |
| See the tasks of published, non password protected posts | Everyone, including logged-out visitors |
| See the tasks of drafts, pending, scheduled, private, password protected or trashed posts | Only users who can edit that post, and only in the context of that post (editor, preview) |
| Create, rename, assign, schedule or delete a task | Users who can edit the post, by editing its Task block (`edit_post`) |
| Change the status of a task | Users who can edit the post (`edit_post`), and the assignees of the task while the post is public |
| Search users to assign | Logged-in users with `edit_posts` (Contributors and up) |

Details and rationale:

- **Assignees do not need to be able to edit the post.** A status change goes to the tasks table through the REST API; the post, its content and its revisions are not touched.
- **Only the status can be changed outside of the post.** The REST API ignores every other property, so an assignee cannot rename or reassign a task.
- **An assignee loses the right** to update a task when they are removed from it, or when its post is unpublished.
- **User search does not require `list_users`.** The plugin's own endpoint returns only the user ID, display name, nicename and avatar URLs. It matches the display name, nicename and login, never the email address. On multisite it is limited to members of the current site unless a filter widens it.
- **Public responses contain only display names and avatars** of assignees. Avatar URLs follow the site's “Show Avatars” setting.
- **Unpublished tasks are reported as “not found”** rather than “forbidden”, so task identifiers of drafts cannot be probed.
- Authentication is the standard WordPress cookie plus REST nonce (`X-WP-Nonce`), or any other REST authentication the site provides, such as application passwords.

Filters to adjust the rules: `action_items_can_update_status`, `action_items_assign_capability`, `action_items_is_assignable_user`, `action_items_user_query_args`. See [Hooks](#hooks).

## Architecture

The full description is in [docs/data-model.md](docs/data-model.md). In short:

**Two per-site tables.** `{prefix}action_items` holds one row per task, `{prefix}action_item_assignees` one row per task and assignee. Custom tables were chosen over a custom post type or post meta because the list needs indexed filtering on assignee and status, a multi-column sort and pagination, which would take several meta joins; because tasks must not show up in search, feeds, exports or other post queries; and because the site prefix gives multisite isolation for free.

**Each property has one authoritative store.**

| Property | Authoritative store | Mirror |
| --- | --- | --- |
| Title, assignees, due date, position | Block attributes in the post content | Task tables, rewritten on every post save |
| Status, completion date, who changed it | Tasks table | The `status` block attribute is only the initial status of a task that has no row yet |
| Task identifier | Block attribute `taskId` (UUID v4) | Unique key in the tasks table |
| Post the task belongs to | Tasks table (`post_id`) | — |
| Visibility | The post: status, password, type | Never copied; joined at query time |

**Why stale content cannot overwrite a completion.** Saving a post never writes the status of an existing task. Whatever `status` the saved block carries, it is only read when the task is first created. This removes the whole class of “an editor had the post open while someone completed the task” problems without version counters or content rewriting.

**Rendering.** Both blocks are rendered on the server. The Task block shows the status from the tasks table, so the meeting notes are correct without any content update. Block output never depends on the current user, which makes it safe for full-page caches; the small frontend script then loads the visitor's own view and enables the controls they are allowed to use, through the REST API.

**Synchronization points.**

- `wp_insert_post_data`: before a post is saved, gives a fresh identifier to Task blocks that have none, repeat an identifier within the post, or use one that belongs to another post. Only the `taskId` attribute of those blocks is rewritten.
- `save_post`: creates, updates and deletes rows to match the saved blocks. Revisions, which include autosaves of published posts, are skipped.
- `deleted_post`: deletes the rows of a permanently deleted post. Trashing keeps them, hidden, so restoring brings the tasks back with their status.
- `deleted_user`: removes the user's assignments.

## REST API

Namespace: `action-items/v1`.

| Route | Method | Purpose | Access |
| --- | --- | --- | --- |
| `/tasks` | GET | List tasks. Parameters: `status` (list, default incomplete), `assignee` (user ID or `me`), `post`, `include` (task identifiers), `page`, `per_page` (max 100). Totals in `X-WP-Total` and `X-WP-TotalPages`. | Public for public tasks |
| `/tasks/<id>` | GET | One task. | Public for public tasks |
| `/tasks/<id>` | POST, PUT, PATCH | Change the status. Body: `status`. | See [Permissions](#permissions) |
| `/task-list` | GET | One rendered page of a Task List for the current user: `html`, `total`, `total_pages`, `page`, `view`, `viewer`. Used by the Task List block. | Public |
| `/users` | GET | Search assignable users: `search`, `include`, `per_page`. | `edit_posts` |

A task looks like this:

```json
{
  "id": "0b0f6b53-5a0b-4d0c-9f0e-0d6c6f3f4a11",
  "status": "in-progress",
  "title": { "rendered": "Review the <strong>proposal</strong>" },
  "due_date": "2026-11-13",
  "created": "2026-11-10T16:00:00",
  "modified": "2026-11-11T09:30:00",
  "completed": null,
  "assignees": [ { "id": 12, "name": "Contributor One", "avatar_urls": {} } ],
  "post": { "id": 345, "title": "AI Team meeting notes", "link": "https://example.org/meeting-notes/" },
  "link": "https://example.org/meeting-notes/#task-0b0f6b53-5a0b-4d0c-9f0e-0d6c6f3f4a11",
  "can_update": true
}
```

## Hooks

Filters:

| Filter | Purpose |
| --- | --- |
| `action_items_post_types` | Post types scanned for Task blocks. Default: all public post types except attachments. |
| `action_items_can_update_status` | Final say on whether a user may change the status of a task. |
| `action_items_assign_capability` | Capability required to search users. Default `edit_posts`. |
| `action_items_is_assignable_user` | Whether a user can be assigned. Default: existing user, and member of the site on multisite. |
| `action_items_user_query_args` | `WP_User_Query` arguments of the user search. |
| `action_items_user_label` | Name shown for an assignee. Default: display name. |
| `action_items_user_url` | URL an assignee name links to. Default: no link. |

Actions, intended for future features such as notifications and reminders:

| Action | Fired when |
| --- | --- |
| `action_items_task_created` | A task row was created from a block. The post may not be published yet. |
| `action_items_assignees_changed` | The assignees of an existing task changed; receives the added and removed user IDs. |
| `action_items_status_changed` | The status of a task changed; receives the old status and the acting user. |
| `action_items_task_deleted` | A task row was deleted. |

## Development and testing

Requirements: Node.js 22 or later (as required by `@wordpress/scripts`), Composer, PHP 7.4 or later, and for the PHP tests a MySQL or MariaDB server plus a WordPress checkout.

```sh
npm ci            # JavaScript dependencies
composer install  # PHP development dependencies

npm run build     # production build into build/
npm run start     # development build, watching for changes
```

### Linting

```sh
composer lint     # PHPCS: WordPress Coding Standards and PHPCompatibilityWP
npm run lint:js   # ESLint
npm run lint:css  # Stylelint
```

### PHP tests

The PHPUnit suite uses the WordPress test library (`wp-phpunit/wp-phpunit`, installed by Composer). It needs a WordPress checkout and an empty database **that it is allowed to wipe**:

```sh
export WP_TESTS_CORE_DIR=/path/to/wordpress   # directory containing wp-settings.php
export WP_TESTS_DB_NAME=wordpress_test
export WP_TESTS_DB_USER=root
export WP_TESTS_DB_PASSWORD=''
export WP_TESTS_DB_HOST=localhost

npm run build                                  # the render tests need the built blocks
composer test                                  # single site
vendor/bin/phpunit -c tests/phpunit/multisite.xml   # multisite
```

To test against another WordPress version, point `WP_TESTS_CORE_DIR` at that version and `WP_TESTS_PHPUNIT_DIR` at a matching copy of the test library.

The fixtures create their own users, posts and sites; nothing depends on WordPress.org infrastructure.

### JavaScript unit tests

```sh
npm run test:js
```

### End-to-end tests

The Playwright suite walks through the complete workflow in a real browser. It runs against any WordPress site with the plugin active and an administrator account; it creates its own users, post and page.

```sh
WP_BASE_URL=http://localhost:8888 WP_USERNAME=admin WP_PASSWORD=password npm run test:e2e
```

### A local site

Any local WordPress works. With Docker, [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) reads the included `.wp-env.json`:

```sh
npx @wordpress/env start    # http://localhost:8888, admin / password
```

### Continuous integration

`.github/workflows/test.yml` runs on every pull request and on every push to `main`:

| Job | What it runs |
| --- | --- |
| Lint, JavaScript tests and build | PHPCS, ESLint, Stylelint, the JavaScript unit tests and a production build |
| PHPUnit | The PHP suite on a single site and on multisite, for PHP 7.4 with WordPress 6.5, PHP 8.3 with WordPress 6.8, and PHP 8.4 with WordPress 7.1 |
| End-to-end | The Playwright suite in Chromium, against a fresh WordPress 7.1 site on PHP 8.3 |

WordPress comes from the `X.Y-branch` branches of the `WordPress/WordPress` mirror, so each job tests the latest release of that version plus the fixes queued for its next minor release. The PHPUnit job installs the WordPress test library that matches the version under test. When a new WordPress version comes out, add it to the matrix and move the end-to-end job to it.

## What has been verified

Executed on 2026-11-11 with PHP 8.3 and MariaDB 10.11:

| Check | Result |
| --- | --- |
| PHPUnit, WordPress 7.1.4, single site | 81 tests, 79 passed, 2 skipped (multisite-only) |
| PHPUnit, WordPress 7.1.4, multisite | 81 tests passed |
| PHPUnit, WordPress 6.8.12 and 6.5.14, single site and multisite | Same results |
| Playwright end-to-end, Chromium, WordPress 7.1.4, Twenty Twenty-Five | 6 tests passed |
| JavaScript unit tests | 14 tests passed |
| PHPCS (WordPress, PHPCompatibilityWP for PHP 7.4+), ESLint, Stylelint | No errors |

Not executed, so treat as unverified:

- PHP versions other than 8.3. PHP 7.4 to 8.2 compatibility is only checked statically by PHPCompatibilityWP.
- The block editor on WordPress versions older than 7.1. The PHP side is tested on 6.5 and 6.8; the editor scripts are not.
- Browsers other than Chromium, and assistive technology. The markup uses native controls, labels and live announcements, but no screen reader session was run.
- `wp-env`. The configuration file is included, but Docker was not available where the plugin was built.
- The WordPress.org environment itself: its object cache, page cache, user tables and themes.
- Whether the slug `action-items` is free in the WordPress.org plugin directory.

## Known limitations

- **Deactivating the plugin hides the tasks.** The blocks are rendered on the server and store no static markup. The task data stays in the post content and in the tables, and comes back when the plugin is reactivated.
- **A removed task loses its status history.** Deleting a Task block and saving the post deletes the task. Restoring an older revision brings the block back as a new task with the status stored in that revision, not the one it had when it was deleted.
- **Status changes made in the editor are immediate.** For a task that already exists, changing the status in the editor saves right away, like in the list, and is not undone by leaving the post unsaved.
- **Imports and exports carry the content, not the status.** A WXR import recreates the tasks from the blocks, with the status last saved in the content, which is usually “Not started”.
- **Task blocks only work in posts.** Blocks placed in synced patterns, template parts, templates or widgets render from their attributes, read-only, and do not appear in lists.
- **Lists are per site.** There is no network-wide list.
- **The server-rendered list is the public view.** Without JavaScript, visitors get the first page of all incomplete tasks, read-only, without filters or pagination.
- **Filter state is not in the URL.** A filtered or paginated list cannot be bookmarked, and the browser's back button does not step through filters.
- **The contributor filter is a plain menu** of everyone with at least one public task. It is not paginated.
- **Deleting a user network-wide only cleans the current site.** On other sites the assignment rows stay, harmless: unknown users are skipped when rendering.
- **Due dates are dates without a time.** “Overdue” is evaluated in the site's timezone when the page is rendered, so on a cached page the highlight can be out of date by the lifetime of the cache.
- **No notifications, reminders, reports or integrations.** The actions listed under [Hooks](#hooks) are there so these can be added without changing the data model.

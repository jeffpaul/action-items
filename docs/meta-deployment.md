# Considerations for WordPress.org Meta deployment

Action Items is a standalone plugin. It was built and tested on ordinary WordPress installs, single site and multisite; it was **not** tested on WordPress.org infrastructure. This document lists what the Meta team would want to review before installing it on `make.wordpress.org/ai`, and the switches the plugin offers.

## What the plugin does to a site

- Creates two tables per site on which it runs: `{prefix}action_items` and `{prefix}action_item_assignees`, through `dbDelta()`, the first time the plugin loads on that site. One autoloaded option per site, `action_items_db_version`.
- Registers two blocks, one REST namespace (`action-items/v1`), and hooks on `wp_insert_post_data`, `save_post`, `deleted_post`, `deleted_user` and `wpmu_drop_tables`.
- Adds no admin pages, settings, cron events, roles, capabilities, rewrite rules, custom post types or taxonomies.
- Makes no outbound HTTP requests and loads nothing from third parties. Avatars come from `get_avatar()` and follow the site's avatar settings.
- Does not modify core or other plugins, and does not depend on the theme.

## Activation on the Make network

- Activate it only on the sites that should have tasks, or network-wide. In both cases tables are created lazily per site; network activation does not iterate over sites.
- If schema changes are not allowed to run from web requests, create the tables ahead of time on each target site with `wp eval 'ActionItems\Schema::install();' --url=make.wordpress.org/ai`. After that, `Schema::maybe_install()` is a single autoloaded option read.
- Uninstalling drops the tables on every site by looping over `get_sites()`. On a large network, prefer dropping them per site with WP-CLI: `wp eval 'ActionItems\Schema::uninstall();' --url=<site>`.

## Users

WordPress.org has one very large user table, and people who take part in a Make team are not necessarily members of that site.

- **Who can be assigned.** By default, on multisite, only members of the current site. To accept any WordPress.org account:

  ```php
  add_filter( 'action_items_is_assignable_user', function ( $assignable, $user_id ) {
      return (bool) get_userdata( $user_id );
  }, 10, 2 );
  ```

- **User search.** By default the search is a `WP_User_Query` limited to the current site, matching `*term*` against login, nicename and display name. A leading wildcard on a table with millions of rows is slow. For a network-wide search, switch to prefix matching on indexed columns, or replace the query altogether:

  ```php
  add_filter( 'action_items_user_query_args', function ( $args, $search ) {
      $args['blog_id'] = 0;
      if ( '' !== $search ) {
          $args['search']         = $search . '*';
          $args['search_columns'] = array( 'user_login', 'user_nicename' );
      }
      return $args;
  }, 10, 2 );
  ```

  With an empty search term the endpoint lists the first users by display name. On a network-wide search, return no results for short terms from the same filter (for instance with `'include' => array( 0 )`).

- **Who can search.** Users with `edit_posts` on the site (`action_items_assign_capability`).
- **How assignees are shown.** Display name by default. To show `@handles` linking to profiles, as Make sites usually do:

  ```php
  add_filter( 'action_items_user_label', fn( $label, $user ) => '@' . $user->user_nicename, 10, 2 );
  add_filter( 'action_items_user_url', fn( $url, $user ) => 'https://profiles.wordpress.org/' . $user->user_nicename . '/', 10, 2 );
  ```

- **Privacy.** Public output contains display name and avatar only. The nicename is returned only by the authenticated user search. Email addresses are never read or returned.

## Permissions

The rules are in `includes/class-permissions.php` and summarized in the README. Points worth a review:

- Any assignee can change the status of their task while the post is public, without any capability on the site beyond being logged in. This is the purpose of the plugin, and it is limited to the three status values.
- Users with `edit_post` on the post can change the status of its tasks.
- `action_items_can_update_status` can widen this (for instance to all team reps) or narrow it.
- Status changes are attributed (`status_changed_by`) but there is no audit log or history.

## Caching

- **Page cache.** Block markup is the same for all visitors, so cached pages never contain one person's view. The Task List's personalized content and all write controls arrive through REST requests made by the browser. Those requests must not be cached for logged-in users; WordPress sends `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` for authenticated REST requests, and the plugin relies on that being honored.
- **Anonymous REST requests.** Every anonymous view of a page with a Task or Task List block makes one uncached-by-default `GET` to `action-items/v1`. The responses contain only public data and can be cached at the edge for a short time if needed; the cost is that anonymous visitors see status changes later.
- **Object cache.** Query results are stored in the `action-items` group, per site, invalidated through `wp_cache_set_last_changed()`. No persistent cache is required. The group must not be registered as a global group.
- **Nonces.** The frontend script uses `wp-api-fetch`, which recovers from a stale nonce in a cached page through `admin-ajax.php?action=rest-nonce`. If that endpoint is blocked, logged-in users on cached pages would see read-only tasks.

## Performance

- Reads: one `COUNT` and one `SELECT` joining the tasks table to `wp_posts` on the primary key, plus one query for the assignees of the page of results. Results are cached in the object cache.
- Writes on post save: for posts without Task blocks and without existing tasks, one indexed `SELECT` on `post_id` and nothing else. For posts with tasks, one write per changed task.
- The contributor filter runs one `DISTINCT` query over public tasks, cached the same way.
- The plugin has been exercised with tens of tasks, not load-tested with tens of thousands.

## Content and other systems

- **Subscriptions and feeds.** Task blocks render as server-side HTML, so they appear in feeds and in anything built from rendered content. Feeds get plain markup without form controls. Email clients were not tested; if post notification emails are generated outside of a feed context they will contain a disabled checkbox and a hidden status menu.
- **Themes.** Styles are minimal and inherit fonts and colors. The only hard-coded color is the red used for overdue dates and errors. Check contrast on the Make themes.
- **Deactivation.** Without the plugin, Task blocks render nothing on the frontend and show as unsupported blocks in the editor. The data stays in the content and in the tables.
- **Slug.** The slug and text domain are `action-items`. Whether that slug is available in the plugin directory was not checked.

## Review checklist

1. Read `includes/class-permissions.php` and `includes/class-rest-controller.php`: all access decisions are there.
2. Read `includes/class-sync.php`: the only code that changes post content, limited to the `taskId` attribute of Task blocks.
3. Decide on the user filters above.
4. Confirm how the page cache treats authenticated REST requests and `admin-ajax.php?action=rest-nonce`.
5. Run the PHPUnit suite on the target PHP and WordPress versions, in multisite mode.
6. Walk through `docs/manual-testing.md` on a sandbox.

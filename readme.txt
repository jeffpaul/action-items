=== Action Items ===
Contributors: (to be set)
Tags: tasks, blocks, meeting notes, todo, collaboration
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Task blocks for meeting notes, with a centralized task list that stays in sync with the original posts.

== Description ==

Action Items adds two blocks:

* **Task**: an action item inside a post, with one or more assignees, an optional due date and a status (Not started, In progress, Completed).
* **Task List**: a list of the tasks of all published posts. Logged-in visitors see their own incomplete tasks first and can switch to all tasks, filter by contributor or status, and mark tasks complete.

A status changed in the list shows in the original post and the other way around. Assignees can update their tasks without being able to edit the post. The post stays the permanent home of each task.

The plugin works on single sites and on multisite, where each site has its own tasks. It needs no external service.

== Installation ==

1. Upload the plugin and activate it.
2. Add Task blocks to a post and publish it.
3. Create a page, for instance "Tasks", and add the Task List block.

== Frequently Asked Questions ==

= Who can mark a task complete? =

Users who can edit the post that contains the task, and the assignees of the task while that post is public.

= Are tasks of drafts or private posts visible? =

No. Only tasks of published posts without a password are listed.

= What happens to my tasks if I deactivate the plugin? =

They are no longer displayed, but the data stays in the post content and in the plugin's tables, and comes back when the plugin is reactivated. Uninstalling the plugin deletes its tables.

== Changelog ==

= 0.1.0 =
* Initial version.

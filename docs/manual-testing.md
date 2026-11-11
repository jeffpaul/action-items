# Manual testing

A short acceptance test for the block editor and the frontend. It needs a site with the plugin active and four accounts:

| Account | Role |
| --- | --- |
| `editor` | Editor or Administrator |
| `alice` | Contributor |
| `bob` | Subscriber |
| `carol` | Subscriber |

## Acceptance checklist

### 1. Write meeting notes

Logged in as `editor`:

- [ ] Create a post titled “Meeting notes”. Type `/task` and choose **Task**. The cursor is in the task title.
- [ ] Type a task and press Enter. A second Task block appears. Add a third one the same way.
- [ ] Select the first task, click **Assign**, type part of Alice's name, and pick her from the suggestions. The block shows “Assigned to: Alice”.
- [ ] Assign the second task to Bob and the third to Carol.
- [ ] On the first task, click **Set due date** and pick a date. The block shows “Due …”.
- [ ] Open the block sidebar: status, assignees and due date are there too and stay in sync with the block.
- [ ] Duplicate a task (block menu → Duplicate), then delete the copy. No error.
- [ ] Publish. View the post: three tasks with assignees, the due date, and checkboxes.

### 2. Create the Tasks page

- [ ] Create a page titled “Tasks”, add the **Task List** block, publish.
- [ ] The editor shows a preview of the list. The sidebar offers the default view, tasks per page and the filters toggle.

### 3. Logged-out visitor

In a private window:

- [ ] The Tasks page lists the three tasks with assignee, due date and a link to “Meeting notes”.
- [ ] Checkboxes are disabled; there is no status menu and no “My Tasks” button.
- [ ] The link of a task opens the meeting notes scrolled to that task.

### 4. Assignee completes a task from the list

Logged in as `bob`:

- [ ] The Tasks page opens on **My Tasks** and shows only Bob's task.
- [ ] **All Tasks** shows all three; only Bob's has an enabled checkbox.
- [ ] The **Contributor** filter set to Carol shows only Carol's task.
- [ ] Back on **My Tasks**, check Bob's task. It is struck through at once.
- [ ] Reload: “No incomplete tasks assigned to you.”
- [ ] Set **Status** to “All, including completed”: the task is back, completed.
- [ ] Open the meeting notes: Bob's task is completed there too. Bob has no “Edit Post” link.

### 5. Assignee updates a task from the meeting notes

Logged in as `carol`:

- [ ] On the meeting notes, Carol's task has a status menu. Set it to “In progress”.
- [ ] On the Tasks page, Carol's task shows “In progress”.

### 6. Editing the notes does not undo anything

Logged in as `editor`:

- [ ] Edit the meeting notes. Bob's task shows as completed and Carol's as in progress in the editor.
- [ ] Rename the first task and update the post.
- [ ] On the Tasks page (All Tasks, all statuses): the new title is shown, Bob's task is still completed, Carol's still in progress.
- [ ] Uncheck Bob's task in the list. The meeting notes show it as not started.

## More checks

### Editor

- [ ] Pressing Enter on an empty task turns it into a paragraph. Backspace on an empty task removes it.
- [ ] Copy a Task block and paste it into another post. After saving, both posts have their own task; completing one does not complete the other.
- [ ] Remove a Task block and update the post: the task is gone from the Tasks page.
- [ ] A paragraph can be transformed into a Task and back.
- [ ] As `alice` (Contributor): create a draft with a task, search and assign a user, save the draft. The task is not on the Tasks page until an editor publishes the post.

### Post lifecycle

- [ ] Switch the published meeting notes back to draft: its tasks leave the Tasks page. Publish again: they are back, statuses intact.
- [ ] Trash the post: tasks disappear. Restore and publish: they are back, statuses intact.
- [ ] Delete the post permanently: tasks are gone for good.
- [ ] Set a password on a post with tasks: its tasks leave the Tasks page.

### Frontend

- [ ] With more tasks than “tasks per page”, Previous and Next appear and work; “Page X of Y” is correct.
- [ ] Keyboard only: Tab reaches the view buttons, filters, checkboxes, status menus and pagination; Space toggles a checkbox.
- [ ] With a screen reader: changing a filter announces the number of tasks found; changing a status announces the new status.
- [ ] On a narrow screen the controls and the task details wrap without horizontal scrolling.
- [ ] A task with a due date in the past and not completed is highlighted as overdue.

### Permissions, with the browser's developer tools

- [ ] Logged out: `fetch('/wp-json/action-items/v1/tasks/<id>', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{"status":"completed"}' })` answers 401.
- [ ] As a user who is neither assignee nor editor, the same request through `wp.apiFetch` answers 403.
- [ ] `/wp-json/action-items/v1/users?search=a` answers 401 when logged out and 403 for a Subscriber.
- [ ] No response of the `action-items/v1` namespace contains an email address.

### Multisite

- [ ] Activate the plugin on two sites. Tasks published on one never appear in the Task List of the other.

=== Rootstuff Media Folders ===
Contributors: atomjb
Tags: media library, folders, organize, media, files
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Organize your media library into real folders on the server — not virtual ones. Files are physically moved and every database path is updated.

== Description ==

Most media folder plugins are *virtual*: they organize the media library screen but never touch the files on your server, which stay in year/month folders. Rootstuff Media Folders is different — folders are **real directories inside wp-content/uploads**, and the plugin keeps WordPress fully consistent when files move:

* Moves the original file **and every generated thumbnail size**
* Updates `_wp_attached_file` and attachment metadata
* Updates the attachment GUID (optional)
* Rewrites file URLs in your post content (optional)
* Creates **301 redirects** from the old file location (optional), served automatically for any moved file
* Uploads go **directly into the folder selected in the tree** — no upload-then-move step; a default upload folder can also be set for everywhere else

Because the filesystem itself is the source of truth, there is no folder taxonomy to fall out of sync — what you see is what is actually on your server. This is ideal for sites migrated from static HTML where files must keep a meaningful directory structure.

= Features =

* **Folder tree sidebar** in the media library (grid and list mode) with expandable nested folders and file counts
* **Live folder search** that filters the tree as you type, with match highlighting (Enter opens the first match)
* **Drag and drop**: drag files onto a folder to physically move them (multi-select supported); drag folders onto folders to restructure the tree
* **Inline management**: create, rename, and delete folders right in the tree — no page reloads
* **Folder Settings** screen (under Media) with plugin settings and the redirect log
* **Folder filter** dropdown in the media modal (post editor)
* **Move to folder…** bulk action in the media library list view
* **Folder field** on every attachment's edit screen and in the media modal
* **Folder column** in the media library list table
* Renaming a folder updates every attachment inside it (at any depth) and adds a single prefix redirect
* Redirect chains are collapsed automatically (A→B then B→C becomes A→C)
* Developer hooks: `rsmf_attachment_moved`, `rsmf_folder_created`, `rsmf_folder_renamed`, `rsmf_folder_deleted`, `rsmf_upload_folder`, `rsmf_move_capability`, `rsmf_manage_capability`

= What it deliberately does not do =

* It never overwrites an existing file — moves that would collide are skipped with a clear error.
* Only empty folders can be deleted.
* Post meta is not rewritten automatically (it may contain serialized data). Use the `rsmf_attachment_moved` action to update custom storage.

== Frequently Asked Questions ==

= How are redirects served? =

On standard WordPress rewrite setups (Apache with the WordPress .htaccess rules, or nginx with try_files), a request for a file that no longer exists falls through to WordPress. The plugin intercepts the resulting 404 and issues a 301 to the file's new location.

= Does this work with page builders or custom fields? =

URLs inside post content are rewritten. URLs stored in post meta or options are not (rewriting serialized data blindly is unsafe), but the 301 redirects keep those references working. The `rsmf_attachment_moved` action lets you update custom storage yourself.

= Does it work with very large media libraries? =

Folder filtering matches against the `_wp_attached_file` meta value, which WordPress does not index. Libraries in the tens of thousands of attachments work fine; at hundreds of thousands, media queries with a folder filter may slow down. If that is your scale, adding a database index on `wp_postmeta (meta_key, meta_value(191))` restores fast filtering.

= Is multisite supported? =

Version 1.0 targets single-site installs. On multisite, activate it per-site rather than network-wide.

= What happens on uninstall? =

The settings option and the redirects table are removed. Your files and folders are left exactly where they are.

== Screenshots ==

1. The folder tree beside the media library grid. Folders are real directories inside wp-content/uploads; selecting one shows the files that are physically inside it.
2. Live folder search filters the tree as you type, with match highlighting at any depth.
3. The Folder field in the attachment details modal. Changing it moves the file on the server, updates database paths, and creates a 301 redirect.
4. The Folder Settings screen: move behavior options and the redirect log, where rules can be deleted.
5. List mode with the folder tree, a folder filter, and a sortable folder column.

== Changelog ==

= 1.2.3 =
* Readme: screenshot captions updated for the wp.org listing. No code changes.

= 1.2.2 =
* Fixed: the "Drop files to upload" panel in the media grid was misplaced and partially hidden behind the toolbar.

= 1.2.1 =
* Fixed: folder navigation in the media grid stopped working after the attachment details modal had been opened (WordPress reassigns wp.media.frame to the modal; the tree now reads the grid frame from wp.media.frames.browse).
* Fixed: folder counts in the tree did not update after moving a file via the folder select in the attachment details modal.
* Redirect rules can now be deleted from the redirect log on the Folder Settings screen.
* Fixed: moving a file back to a previous location left behind a circular self-redirect. Self-redirects are no longer created, and existing ones are cleaned up on the next move.

= 1.2.0 =
* Renamed plugin to Rootstuff Media Folders. All internal prefixes changed from `pmf`/`PMF` to `rsmf`/`RSMF`, including the settings option (`rsmf_settings`), the redirects table (`rsmf_redirects`), and the developer hooks (`rsmf_attachment_moved`, `rsmf_folder_created`, `rsmf_folder_renamed`, `rsmf_folder_deleted`, `rsmf_upload_folder`, `rsmf_move_capability`, `rsmf_manage_capability`).

= 1.1.19 =
* The modal attachments grid is now positioned from the measured toolbar height instead of a fixed offset, so thumbnails are never clipped regardless of modal variant (labeled filters, wrapped controls) or window size. The New Folder button matches the 32px control height.

= 1.1.18 =
* Fixed the folder combobox inside the media modal: the dropdown panel is now fixed-positioned so it escapes the details sidebar's scroll clipping (with flip-up near the bottom of the screen), the input shows the full folder path instead of an ambiguous indented basename, and the label no longer reads a transient value mid filter-change.
* Fixed the modal toolbar: the folder filter and New Folder button sat behind the thumbnails (core's toolbar has a fixed height); they now get their own row and the grid moves down to make room.

= 1.1.17 =
* The media modal (including the block editor's "Media Library" flow) is now a full folder workflow: uploads made in the modal land in the folder selected in its dropdown, and a New Folder button creates folders inline — the new folder becomes the active filter and upload destination.
* New uploads appear immediately in folder-filtered modal views (the upload-queue observation patch now covers the modal, not just the library grid).

= 1.1.16 =
* Block editor (REST API) uploads now respect the folder routing, so the default upload folder applies to Gutenberg uploads too.
* Moving files now requires edit permission on each attachment, not just upload_files — moves rewrite URLs in post content, so Authors can no longer indirectly edit content they cannot edit directly.
* Prefix redirects match with LOCATE instead of LIKE, so folder names containing underscores no longer over-match unrelated paths.
* Folder renames record their redirect before updating attachments, so files stay reachable even if the update is interrupted mid-way.
* Large bulk-move selections are passed via a transient instead of the URL, and batch moves reset the PHP time limit per file.
* List rows no longer stay drag-enabled after a click, restoring filename text selection.
* The folder list ships once on the library screen instead of twice; assorted small cleanups.

= 1.1.15 =
* Fixed sporadic dead clicks on tree folders. Three causes: clicks landing during a background tree re-render lost their row (folder activation is now a single delegated listener on the stable container); tiny mouse movements promoted clicks to drags which browsers then swallow (a drag that ends within a few pixels of where it started, undropped, is now treated as the click it was meant to be); and passive count refreshes briefly disabled pointer events on the whole sidebar (they no longer do).
* Grid folder clicks retry once if the media frame is mid-transition instead of silently dropping.

= 1.1.14 =
* Fixed: the searchable folder box now matches the exact height of the neighboring dropdowns (32px); a broader admin input rule was inflating it to 40px. Grid toolbar controls aligned to the same height.

= 1.1.13 =
* Every folder select is now a searchable combobox: type to filter with match highlighting, arrow keys + Enter to choose. Applies to the grid and list filters, the attachment folder field, the Upload to folder picker, the default-upload-folder setting, and the bulk-move destination. No external libraries; the native select stays as the source of truth.

= 1.1.12 =
* Fixed: dragging a file from the grid onto a folder now works reliably. Core's "Drop files to upload" overlay appears on any drag (it never checks the payload) and was swallowing the drop before it reached the folder tree; the overlay is now suppressed during internal move-drags only. Drag-to-upload from your desktop is unaffected.
* Grid tiles are marked draggable proactively, and a hint appears in the sidebar while dragging.

= 1.1.11 =
* Added a Search Media button to the grid toolbar, matching the list view.

= 1.1.10 =
* The grid toolbar now uses the same two-row layout as the list view: all dropdowns on one row, search below.

= 1.1.9 =
* List view filters and search are now fully async: the media type, date, and folder dropdowns apply on change, search filters as you type (keeping focus), and pagination / sort links swap in place. Bulk actions still submit normally.
* The grid toolbar controls are restyled to match the classic list-view look, and the grid search label is visually hidden like the list view's.

= 1.1.8 =
* List mode now filters asynchronously: clicking a folder swaps the list table in place instead of reloading the page. URLs and the browser back/forward buttons still work, with a full-navigation fallback if the fetch fails.

= 1.1.7 =
* The Media > Folders page is now Media > Folder Settings: settings and the redirect log only. All folder management lives in the media library sidebar.
* New "collapse all folders" button in the tree header.

= 1.1.6 =
* Fixed: folders named like file extensions (mp3, jpg, pdf, …) showed no files when filtered. Folder path segments are no longer run through sanitize_file_name(), which rewrites extension-like names to "unnamed-file.<ext>"; a dedicated folder-name sanitizer strips the same unsafe characters without filename semantics.

= 1.1.5 =
* Fixed: new uploads now appear in the grid immediately when a folder filter is active (core only lets queries with allowlisted args watch the upload queue; folder-filtered queries are now included).
* The folder tree refreshes its counts and shows a confirmation notice when uploads finish.

= 1.1.4 =
* Fixed: uploads from media-new.php ignored the chosen folder (the legacy async-upload flow sends no action parameter and was not recognized as a media upload).

= 1.1.3 =
* "Upload to folder" picker on the Add Media File screen (media-new.php), preselected from the folder you were viewing.
* The list view's Add Media File link now carries the current folder along.

= 1.1.2 =
* Uploads now land directly in the folder currently selected in the tree sidebar.
* Fixed the grid-mode layout so the attachments grid sits beside the sidebar.

= 1.1.0 =
* New folder tree sidebar in the media library (grid and list modes) with expandable folders.
* Drag and drop files onto folders to move them physically; drag folders to restructure.
* Inline folder create/rename/delete with AJAX (no page reloads).
* Spring-loaded folders: hovering during a drag expands the folder.
* Live folder search box in the sidebar with match highlighting.

= 1.0.0 =
* Initial release.

# Rootstuff Media Folders

Organize your WordPress media library into **real folders on the server** — not virtual ones.

Most media folder plugins are virtual: they organize the media library screen but never touch the files on your server, which stay in `year/month` folders. Rootstuff Media Folders is different — folders are real directories inside `wp-content/uploads`, and the plugin keeps WordPress fully consistent when files move:

- Moves the original file **and every generated thumbnail size**
- Updates `_wp_attached_file` and attachment metadata
- Rewrites file URLs in your post content (optional)
- Creates **301 redirects** from old file locations (optional), served automatically
- Uploads go **directly into the folder selected in the tree** — no upload-then-move step

Because the filesystem itself is the source of truth, there is no folder taxonomy to fall out of sync — what you see is what is actually on your server. Ideal for sites migrated from static HTML where files must keep a meaningful directory structure.

## Features

- Folder tree sidebar in the media library (grid and list mode) with drag-and-drop file moves
- Live folder search and searchable folder dropdowns everywhere
- Async filtering in both views — no page reloads
- Inline folder create / rename / delete
- Folder renames update every attachment inside and add a single prefix redirect
- Redirect chains collapse automatically (A→B then B→C becomes A→C)

See [readme.txt](readme.txt) for the full feature list, FAQ, and changelog.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Single-site installs (on multisite, activate per-site)

## License

GPLv2 or later — see [readme.txt](readme.txt).

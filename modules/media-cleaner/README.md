# Media Cleaner

Finds media the site no longer needs (attachments nothing refers to, attachments whose file is missing, files in the uploads folder that are not in the Media Library, and byte-for-byte duplicates) and moves them to a trash you can restore from. It is for site administrators: scanning, every action and the settings need the `manage_options` capability (shop managers see the page with a notice). Module ID: `media-cleaner`, off until it is switched on in **FFL Funnels → Dashboard**. It reads Bricks, WooCommerce, ACF, Elementor, Beaver Builder, Oxygen, WS Form and this plugin's own modules. WP-CLI is optional.

> **Back up first.** Take a full backup of the database **and** the `wp-content/uploads` folder before you trash or delete media, and keep it until you have checked the storefront. The trash is reversible, but a scan can still list files that are in use (see *What can still be flagged by mistake*), and **Delete permanently**, **Empty trash**, auto-empty and **Skip the trash** cannot be undone.

## What it does

| Result | Meaning | Found by |
|---|---|---|
| Unused | An attachment that nothing the scan reads refers to. | *Scan the media library* + *Check references in content* |
| Broken (file missing) | An attachment whose main file is gone from disk. | *Scan the media library* |
| Orphan file | A file in the uploads folder that belongs to no attachment and that content does not link to. | *Scan the uploads folder for orphan files* |
| Duplicate | An attachment that nothing uses whose main file is byte-identical to an older attachment's. | *Detect duplicate files* |

- Results sit in three tabs (**Issues**, **Ignored**, **Trash**), 25 per page, largest first, with a path search.
- Per row or for the selected rows: **Trash**, **Ignore**, **Restore**, **Delete permanently**, **Stop ignoring**. **Trash all** trashes every result in the Issues tab (matching the search). **Empty trash** deletes everything in Trash.
- The trash can empty itself after 7, 30 or 90 days.
- A scan runs in small batches, so large libraries do not time out. The same scan can run from WP-CLI.

## Setup

1. Back up the database and the uploads folder.
2. As an administrator, switch the module on in **FFL Funnels → Dashboard** and open **FFL Funnels → Media Cleaner**.
3. Under **What to scan**, start with the defaults (media library, with references in content). Click **Save Settings**: a scan always uses the saved settings.
4. Click **Start scan** and keep the tab open until it reads *Scan complete.* The browser drives the scan; closing the tab stops it.
5. Go through **Issues**. The `#ID` link opens the attachment. **Ignore** anything you know is in use.
6. **Trash** what should go (selected rows, or **Trash all**), then check the storefront: product and category pages, header, footer, popups and templates. **Restore** anything that went missing.
7. When you are sure, **Empty trash** (or let auto-empty do it). A new scan keeps the Trash and Ignored tabs, so there is no rush.

## Settings

**FFL Funnels → Media Cleaner**, saved with **Save Settings** (needs `manage_options`).

| Setting | What it does | Default |
|---|---|---|
| **What to scan** | | |
| Scan the media library for unused media | Checks every attachment: *Unused* and *Broken (file missing)*. | On |
| Check references in content | Reads content to decide what is used. Off: only broken attachments are reported and nothing is judged unused. | On |
| Scan the uploads folder for orphan files | Lists files in the year/month upload folders (and the top level of uploads) that are not in the Media Library. Other plugins' folders are skipped. Higher risk than the library scan. | Off |
| Detect duplicate files | Flags byte-identical copies that nothing uses, keeping the first (lowest ID) as the original. A copy a page uses is never flagged. | Off |
| **Deletion** | | |
| Skip the trash (delete immediately) | The Trash buttons become **Delete permanently** / **Delete all** and remove files with no recovery. Strongly discouraged. | Off |
| Auto-empty the trash | How long trashed media is kept before it is deleted for good: *After 7 days*, *After 30 days*, *After 90 days*, or never. | Never (empty it manually) |
| **Performance** | | |
| Posts per batch | Posts read per request while collecting references (1–500). | 30 |
| Media per batch | Attachments checked per request (1–500). | 80 |
| Files per batch | Files on disk checked per request (1–1000). | 100 |

If none of the media library, uploads folder or duplicate scans is on, a scan runs the media library check anyway.

## How it works

### The scan

1. **Preparing**: clears the open results and the reference cache. **Trash and Ignored entries are kept** (see *Rescans keep your decisions*).
2. **Reading content**: collects every attachment ID and uploads URL in use (sources below). Runs unless only the broken-file check is on.
3. **Checking the media library**: an attachment that is ignored, already listed in Trash or Ignored, or whose ID or any of its file paths is in use, is skipped. The rest are *Unused*, or *Broken (file missing)* if the file is gone. With *Check references in content* on, a broken attachment is only listed when nothing refers to it; turn it off to list every broken attachment.
4. **Indexing the library** (uploads-folder scan only): records every file that belongs to an attachment. It runs after the library check, so it can never make unused media look used.
5. **Listing files / Checking files on disk** (uploads-folder scan): files directly inside the year/month folders (`2024/03/photo.jpg`) and at the top level of uploads, except the trash folder, names starting with a dot and `index.php` files. Other plugins' folders (WooCommerce downloads and logs, form uploads, builder caches, backups) are never listed; the filter `ffla_mclean_scan_file` changes this. A file that belongs to no attachment, is not linked from content, and is not already ignored or trashed is an *Orphan file*.
6. **Finding duplicates**: compares the main file of every attachment (files over 96 MB are skipped). A copy is only listed when nothing uses it, it is not ignored and it has no other result.

**Stop** ends the scan and keeps whatever it found so far. Starting again begins from scratch. Only one scan runs at a time: if another tab or WP-CLI is still driving one (advanced in the last 10 minutes), **Start scan** asks before starting over and `wp ffla-media scan` needs `--force`.

### What counts as in use

| Source | What is read |
|---|---|
| Posts | Content and excerpt of every public post type plus `bricks_template`, `wp_template`, `wp_template_part`, `wp_block`, `elementor_library` and `product_variation`, in the statuses publish, private, draft, pending, future and inherit (auto-drafts and trashed posts are not read). Any uploads URL, including in inline CSS, `srcset` and builder JSON; `wp-image-N` classes; shortcode attributes `id`, `ids`, `image`, `images`, `include`, `url`, `link`, `src`, `image_url`; classic galleries. |
| Post meta | Featured image (every size), and meta keys `_thumbnail_id`, keys containing `gallery` and keys ending in `_ids`. |
| Bricks | `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2` and `_bricks_page_settings` on every scanned post and template (image fields plus any uploads URL in them, including page custom CSS), and the options `bricks_global_settings`, `bricks_theme_styles`, `bricks_color_palette`, `bricks_global_classes`, `bricks_global_elements` and `bricks_components` (image fields such as `id`, `url`, `full`, `image`, `src`, `background`, `poster`, `file`, plus any uploads URL inside their text, such as custom CSS). |
| WooCommerce | Product galleries, variation images, downloadable product files, every term thumbnail (product categories, tags, brands) and the placeholder image. |
| ACF (when active) | Image, gallery and file fields on posts, terms, users and options pages, including inside repeaters, groups and flexible content. Fields defined in the database, in PHP or in local JSON are all read. |
| Users | Profile-picture meta keys of common avatar plugins (filter `ffla_mclean_user_meta_id_keys`) and any uploads URL stored in user meta. |
| Elementor, Beaver Builder, Oxygen | `_elementor_data` and `_elementor_page_settings`; `_fl_builder_data`; `ct_builder_shortcodes`. |
| Theme and site | Custom logo, site icon, header image, background image, image IDs and URLs in theme mods. |
| Widgets | Image and media widgets, text widgets, block widgets. |
| This plugin | Product Reviews photos and videos, Loadout hero, brand-logo and cross-sell images, WooBooster bundle images. Read whenever those tables exist, even with the module switched off. |
| WS Form | Any uploads URL in its `wsf_*` tables, except tables with *submit*, *log* or *error* in the name (first 5,000 rows of each). |

**Matching.** A URL counts when it contains the uploads path (`/wp-content/uploads` on a standard site); the scheme and domain are ignored. Size suffixes such as `-300x200` are stripped, so a reference to any size keeps the whole attachment. An attachment owns its main file, the original of a scaled image, every generated size, and the backup copies WordPress keeps after an image is edited. If media is served from a CDN whose URLs do not contain the uploads path, add it with `ffla_mclean_url_anchors` (see *For developers*), otherwise those images are listed as unused.

### What can still be flagged by mistake

Check these before trashing:

- **Orphan files** in the year/month folders that another plugin put there, such as image copies made by optimisation plugins (`.webp`/`.avif` next to the original).
- **Unused** media referenced only from places the scan does not read, for example: theme or plugin files and stylesheets; other plugins' own tables or options (WS Form excepted); any other meta key holding a bare attachment ID.

### Trash and restore

- **Trash** an attachment: all its files (main, original, every size, edit backups) move to `uploads/<trash folder>/<number>/<original path>`, and its post type changes to `ffla_mclean_trash`, so it disappears from the Media Library while the database row and metadata are kept. If any file cannot be moved, the ones already moved go back and the attachment stays live. A broken attachment is just hidden. An orphan file is moved the same way.
- **Restore** moves the files back and returns the attachment to the Media Library. It refuses, and leaves the item in Trash, if a file now exists at the original path (for example a new upload with the same name).
- One attachment has one trash entry. Trashing it through one result removes its other open results, and restoring it removes any other trash entry for it, so **Empty trash** can never delete an attachment that is back in the library.
- **Ignore** on a trashed item restores it first.
- **The trash folder** is `ffla-media-trash` on sites that already had one, and `ffla-media-trash-<random>` on new sites (option `ffla_mclean_trash_dirname`), so it cannot be guessed. It gets `.htaccess` rules for Apache 2.2/2.4 and LiteSpeed, a `web.config` for IIS and an empty `index.php`. Nginx reads none of these; add a rule to the server block:

  ```nginx
  location ~* /wp-content/uploads/ffla-media-trash { deny all; }
  ```

### Deleting for good

- **Delete permanently**, **Empty trash** and auto-empty: WordPress deletes the attachment's database records, and its files are deleted from its trash folder. Files are not moved back first, so a newer upload that has since taken the original name is never touched. If the attachment is a live library item again, only the stale trash entry is removed. An orphan file is deleted from the trash folder.
- **Empty trash** only deletes items listed in the Trash tab, and a numbered folder is only removed once it is empty. Other files in the trash folder are left alone.
- Auto-empty is a daily WP-Cron event, first run one day after the setting is saved. It deletes items trashed more than the chosen number of days ago. Switching the module off clears the schedule.
- With **Skip the trash** on, the buttons read **Delete permanently** / **Delete all** and delete straight away (attachments through WordPress, orphan files from disk). The confirmations say it cannot be undone, and `wp ffla-media trash` asks first (or pass `--yes`).

### Ignore

- Ignoring an attachment flags it (post meta `_ffla_mclean_ignored`), so later scans skip it as used. It stays in the Ignored tab, where **Stop ignoring** brings it back.
- Ignoring an orphan file keeps it in the Ignored tab, and later scans do not list it again.

### Rescans keep your decisions

A new scan only clears the open results in **Issues**. **Trash** and **Ignored** entries survive, and result numbers keep counting up, so a new item never lands in an older item's trash folder.

Versions before 1.55.1 emptied all three lists on every scan, which left trashed items hidden with no way to restore them from this screen. The first time an administrator opens Media Cleaner after updating, and before every scan, those items get a new Trash entry and move to their own numbered folder, and the page says how many were found. Trashing into a numbered folder that still holds such leftovers re-homes them first.

### What the module never does

- A scan never moves or deletes files. Only the actions above (Trash, Trash all, Restore, Delete permanently, Empty trash), auto-empty, the recovery of lost Trash entries (which only moves files between trash folders) and the WP-CLI `trash` and `empty-trash` commands do.
- The trash folder, hidden files and `index.php` files are never listed as orphan files.
- Restore never overwrites an existing file.
- Paths containing `..` are refused.
- Uninstalling never deletes trashed media: it is put back first (see *Data and uninstall*).

## Where it shows up

| Place | What appears |
|---|---|
| **FFL Funnels → Media Cleaner** | Intro with the backup reminder; **Scan** card with *Issues found*, *Reclaimable*, *In trash*, *Ignored*, **Start scan** / **Stop** and a progress bar; **Results** card with the tabs, search, bulk buttons, **Trash all** (Issues tab) and **Empty trash** (Trash tab); settings cards **What to scan**, **Deletion**, **Performance**. |
| **Media → Library** | Trashed attachments disappear until restored. |
| `wp-content/uploads/<trash folder>/` | Trashed files, one numbered folder per result. |
| WP-CLI | `wp ffla-media …` |

Shop managers can open the page (it sits under FFL Funnels), but they only see the intro and a notice that only administrators can run Media Cleaner. The AJAX actions and settings save also check `manage_options`.

## Data and uninstall

| Data | Where | On uninstall |
|---|---|---|
| Results | table `{prefix}ffla_mclean_scan` | Dropped |
| Reference cache (rebuilt every scan) | table `{prefix}ffla_mclean_refs` | Dropped |
| Settings | option `ffla_media_cleaner_settings` | Deleted |
| Table version | option `ffla_media_cleaner_db_version` | Deleted |
| Running scan | option `ffla_mclean_job` | Deleted |
| File list during an uploads-folder scan | option `ffla_mclean_file_list` | Deleted |
| Trash folder name | option `ffla_mclean_trash_dirname` | Deleted |
| One-time recovery flag | option `ffla_mclean_adopted` | Deleted |
| Ignore flag | post meta `_ffla_mclean_ignored` | Deleted |
| Auto-empty schedule | cron hook `ffla_mclean_auto_empty` | Cleared |
| Trashed files | `uploads/<trash folder>/` | **Restored** to their original paths; the folder is removed when nothing is left in it |
| Trashed attachments | posts with post type `ffla_mclean_trash` | **Restored** to the Media Library |

Uninstall runs this cleanup whenever Media Cleaner data exists, even with the module switched off. Everything in the trash is put back before the tables are dropped. A file whose original name has since been taken by a newer upload stays in the trash folder (its attachment returns to the library without it); move it by hand. Switching the module off clears the auto-empty schedule and keeps everything else, including the trash.

## Troubleshooting

- **"Only administrators can scan…" notice.** You need an administrator account (`manage_options`).
- **"The scan hit an error. It has been stopped."** A batch failed or timed out. Lower the batch sizes under **Performance**, or run `wp ffla-media scan`. The next scan starts from scratch.
- **"Another scan is still running."** A scan in another tab or WP-CLI advanced in the last 10 minutes. Wait for it, or confirm to start over (`wp ffla-media scan --force`).
- **Something in use is listed.** **Ignore** it. If it is served from a CDN, use `ffla_mclean_url_anchors`; otherwise check *What can still be flagged by mistake*.
- **An image disappeared after trashing.** Restore it from the Trash tab and check the page again.
- **Restore does nothing for an item.** A file now sits at the original path. Rename or move that file, then restore.
- **Items trashed with an older version are missing from the Trash tab.** Open Media Cleaner as an administrator or start a scan: lost items get a new Trash entry automatically. If the plugin was already deleted with an older version, restore by hand: list the hidden attachments with `wp db query "SELECT ID, post_title FROM $(wp db prefix)posts WHERE post_type = 'ffla_mclean_trash'"`, move each attachment's files from `uploads/ffla-media-trash/<number>/` back to the same path under uploads (the path in its `_wp_attached_file` meta), then run `wp post update <ID> --post_type=attachment`.
- **Auto-empty does not run.** It relies on WP-Cron, which needs site visits or a server cron job.

## For developers

**WP-CLI** (available while the module is on)

| Command | What it does |
|---|---|
| `wp ffla-media scan [--force]` | Runs a full scan with the saved settings and prints each phase. Like the button, it clears the open results and keeps Trash and Ignored. Refuses while another scan is running unless `--force`. |
| `wp ffla-media status` | Issues, reclaimable size, in trash, ignored. |
| `wp ffla-media list [--status=<active\|ignored\|trashed>] [--limit=<n>]` | Table of id, issue, size and path, largest first. Default 50, at most 200. |
| `wp ffla-media trash <id>...` or `wp ffla-media trash --all` | Trashes the given result IDs, or every result in Issues. With *Skip the trash* on this deletes permanently and asks first unless `--yes`. |
| `wp ffla-media empty-trash [--yes]` | Permanently deletes everything in Trash; asks first unless `--yes`. `empty_trash` also works. |

There are no restore or ignore commands.

**Filters**

| Hook | Arguments | Use |
|---|---|---|
| `ffla_mclean_url_anchors` | `array $anchors` (default: the uploads path, e.g. `/wp-content/uploads`) | Extra prefixes that mark the start of an uploads-relative path, e.g. a CDN host. |
| `ffla_mclean_scanned_post_types` | `array $types` | Post types read for references. |
| `ffla_mclean_scanned_statuses` | `array $statuses` | Post statuses read for references. |
| `ffla_mclean_post_html` | `string $html`, `int $post_id` | Append markup kept outside `post_content` so the URL sweep sees it. |
| `ffla_mclean_bricks_meta_keys` | `array $keys` | Bricks post meta keys read. |
| `ffla_mclean_bricks_option_keys` | `array $keys` | Bricks options read. |
| `ffla_mclean_bricks_look_for` | `array $keys` | Setting names treated as image fields in Bricks data. |
| `ffla_mclean_scan_file` | `bool $scan`, `string $relative_path` | Whether the uploads-folder scan looks at a file (default: year/month folders and the top level of uploads). |
| `ffla_mclean_user_meta_id_keys` | `array $keys` | User meta keys that hold a profile-picture attachment ID. |

```php
// Media served from https://cdn.example.com/2024/03/photo.jpg
add_filter('ffla_mclean_url_anchors', function (array $anchors): array {
    $anchors[] = 'cdn.example.com';
    return $anchors;
});
```

**Actions for custom parsers**

| Hook | Arguments | Fires |
|---|---|---|
| `ffla_mclean_scan_once` | none | Once per scan, for site-wide references. |
| `ffla_mclean_scan_widget` | `array $widget` | For each registered widget. |
| `ffla_mclean_scan_postmeta` | `int $post_id` | For each scanned post. |
| `ffla_mclean_scan_post` | `string $html`, `int $post_id` | For each scanned post, with its content plus anything added through `ffla_mclean_post_html`. |
| `ffla_mclean_scan_self` | `Media_Cleaner_Core $core` | After this plugin's own references. |
| `ffla_mclean_parsers_loaded` | `string $dir` | After the parser files are loaded. |

Inside these hooks the global `$ffla_mclean` (`Media_Cleaner_Core`) records references: `add_reference_id( $id_or_ids, $label, $post_id = null )` and `add_reference_url( $paths, $label, $post_id = null )`. Paths must be relative to uploads (`2024/03/photo.jpg`); get them with `clean_url( $url )` or `get_urls_from_html( $html )`. Any extra `.php` file in `parsers/` is loaded automatically, but hooks in your own plugin survive updates.

```php
add_action('ffla_mclean_scan_once', function () {
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }
    $ids = array_map('intval', (array) get_option('my_plugin_image_ids', []));
    $ffla_mclean->add_reference_id($ids, 'My plugin');
});
```

**AJAX endpoints** (`admin-ajax.php`, all need `manage_options` and the nonce `ffla_mclean_admin`): `ffla_mclean_scan_start` (`force` = 1 to start over a running scan; otherwise returns `busy`), `ffla_mclean_scan_step`, `ffla_mclean_scan_abort`, `ffla_mclean_results` (`status`, `page`, `search`), `ffla_mclean_action` (`op` = `trash`, `restore`, `ignore`, `unignore` or `delete`; `ids[]`), `ffla_mclean_bulk_all` (`op` = `trash` or `ignore`; `status`, `search`; 40 per call, returns `remaining`), `ffla_mclean_empty_trash`. Settings post to `admin-post.php` with action `ffla_mclean_save_settings`.

**Tables**

- `{prefix}ffla_mclean_scan`: one row per result. `type` 0 = file, 1 = attachment; `post_id`; `path` (uploads-relative, attachments labelled "(+ N thumbnails)"); `size` in bytes; `issue` = `NO_CONTENT` (Unused), `ORPHAN_MEDIA` (Broken), `ORPHAN_FILE` (Orphan file) or `DUPLICATE`; `parent_id` = the original attachment of a duplicate; `ignored`; `deleted` (1 = in Trash); `time` = when found, or last trashed or restored. Rows are never renumbered: the ID names the trash folder.
- `{prefix}ffla_mclean_refs`: the reference cache. `media_id` or `media_url` (uploads-relative), `origin_type` (a label such as `Content`, `Bricks (ID)`, `WooCommerce Gallery`, `MEDIA LIBRARY`), `origin` (post ID), unique `ref_hash`. Duplicate detection also stores file hashes here as `HASHDUP` rows.

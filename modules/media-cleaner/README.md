# Media Cleaner

Finds media the site no longer needs (attachments nothing refers to, attachments whose file is missing, files in the uploads folder that are not in the Media Library, and byte-for-byte duplicates) and moves them to a trash you can restore from. It is for site administrators: scanning, every action and the settings need the `manage_options` capability. Module ID: `media-cleaner`, off until it is switched on in **FFL Funnels → Dashboard**. It reads Bricks, WooCommerce, ACF, Elementor, Beaver Builder, Oxygen, WS Form and this plugin's own modules. WP-CLI is optional.

> **Back up first.** Take a full backup of the database **and** the `wp-content/uploads` folder before you trash or delete media, and keep it until you have checked the storefront. The trash is reversible, but a scan can still list files that are in use (see *What can still be flagged by mistake*), and **Delete permanently**, **Empty trash**, auto-empty and **Skip the trash** cannot be undone.

## What it does

| Result | Meaning | Found by |
|---|---|---|
| Unused | An attachment that nothing the scan reads refers to. | *Scan the media library* + *Check references in content* |
| Broken (file missing) | An attachment whose main file is gone from disk. | *Scan the media library* |
| Orphan file | A file in the uploads folder that belongs to no attachment and that content does not link to. | *Scan the uploads folder for orphan files* |
| Duplicate | An attachment whose main file is byte-identical to an older attachment's. | *Detect duplicate files* |

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
7. When you are sure, **Empty trash** (or let auto-empty do it). Do this, or restore everything, **before the next scan**: see *A new scan clears the lists*.

## Settings

**FFL Funnels → Media Cleaner**, saved with **Save Settings** (needs `manage_options`).

| Setting | What it does | Default |
|---|---|---|
| **What to scan** | | |
| Scan the media library for unused media | Checks every attachment: *Unused* and *Broken (file missing)*. | On |
| Check references in content | Reads content to decide what is used. Off: only broken attachments are reported and nothing is judged unused. | On |
| Scan the uploads folder for orphan files | Lists files on disk that are not in the Media Library. Higher risk: other plugins keep legitimate files there. | Off |
| Detect duplicate files | Flags byte-identical attachments, keeping the first (lowest ID) as the original. | Off |
| **Deletion** | | |
| Skip the trash (delete immediately) | **Trash** deletes permanently, with no recovery. Strongly discouraged. | Off |
| Auto-empty the trash | How long trashed media is kept before it is deleted for good: *After 7 days*, *After 30 days*, *After 90 days*, or never. | Never (empty it manually) |
| **Performance** | | |
| Posts per batch | Posts read per request while collecting references (1–500). | 30 |
| Media per batch | Attachments checked per request (1–500). | 80 |
| Files per batch | Files on disk checked per request (1–1000). | 100 |

If none of the media library, uploads folder or duplicate scans is on, a scan runs the media library check anyway.

## How it works

### The scan

1. **Preparing**: empties both module tables (see *A new scan clears the lists*).
2. **Reading content**: collects every attachment ID and uploads URL in use (sources below). Runs when the library check with content references or the uploads-folder scan is on.
3. **Indexing the library** (uploads-folder scan only): records every file that belongs to an attachment.
4. **Checking the media library**: an attachment that is ignored, or whose ID or any of its file paths is in use, is skipped. The rest are *Unused*, or *Broken (file missing)* if the file is gone. With *Check references in content* on, a broken attachment is only listed when nothing refers to it; turn it off to list every broken attachment.
5. **Listing files / Checking files on disk** (uploads-folder scan): every file under uploads except the trash folder, names starting with a dot and `index.php` files. A file that belongs to no attachment and is not linked from content is an *Orphan file*.
6. **Finding duplicates**: compares the main file of every attachment (files over 96 MB are skipped).

**Stop** ends the scan and keeps whatever it found so far. Starting again begins from scratch.

### What counts as in use

| Source | What is read |
|---|---|
| Posts | Content and excerpt of every public post type plus `bricks_template`, `wp_template`, `wp_template_part`, `wp_block`, `elementor_library` and `product_variation`, in the statuses publish, private, draft, pending, future and inherit (auto-drafts and trashed posts are not read). Any uploads URL, including in inline CSS, `srcset` and builder JSON; `wp-image-N` classes; shortcode attributes `id`, `ids`, `image`, `images`, `include`, `url`, `link`, `src`, `image_url`; classic galleries. |
| Post meta | Featured image (every size), and meta keys `_thumbnail_id`, keys containing `gallery` and keys ending in `_ids`. |
| Bricks | `_bricks_page_content_2`, `_bricks_page_header_2` and `_bricks_page_footer_2` on every scanned post and template (image fields plus any uploads URL in them), and the options `bricks_global_settings`, `bricks_theme_styles`, `bricks_color_palette`, `bricks_global_classes`, `bricks_global_elements` and `bricks_components` (image fields such as `id`, `url`, `full`, `image`, `src`, `background`, `poster`, `file`). |
| WooCommerce | Product galleries, variation images, every term thumbnail (product categories, tags, brands) and the placeholder image. |
| ACF (when active) | Image, gallery and file fields on posts, including inside repeaters and flexible content, and on options pages. |
| Elementor, Beaver Builder, Oxygen | `_elementor_data` and `_elementor_page_settings`; `_fl_builder_data`; `ct_builder_shortcodes`. |
| Theme and site | Custom logo, site icon, header image, background image, image IDs and URLs in theme mods. |
| Widgets | Image and media widgets, text widgets, block widgets. |
| This plugin | Product Reviews photos and videos, Loadout hero, brand, tier-item and cross-sell images, WooBooster bundle images. Read whenever those tables exist, even with the module switched off. |
| WS Form | Any uploads URL in its `wsf_*` tables, except tables with *submit*, *log* or *error* in the name (first 5,000 rows of each). |

**Matching.** A URL counts when it contains the uploads path (`/wp-content/uploads` on a standard site); the scheme and domain are ignored. Size suffixes such as `-300x200` are stripped, so a reference to any size keeps the whole attachment. An attachment owns its main file, the original of a scaled image, and every generated size. If media is served from a CDN whose URLs do not contain the uploads path, add it with `ffla_mclean_url_anchors` (see *For developers*), otherwise those images are listed as unused.

### What can still be flagged by mistake

Check these before trashing:

- **Duplicates** are listed without checking whether they are used. The newer copy is flagged even when it is the one on your pages.
- **Orphan files**: everything under uploads is listed unless it belongs to an attachment or is linked from content, including files other plugins keep there (generated CSS, logs, protected downloads, image copies made by optimisation plugins) and the backup copies WordPress keeps when you edit an image.
- **Unused** media referenced only from places the scan does not read, for example: theme or plugin files and stylesheets; other plugins' own tables or options (WS Form excepted); user profiles; WooCommerce downloadable product files; ACF fields defined only in PHP or local JSON (field definitions are read from the database); free-text values in the Bricks options above, such as custom CSS; any other meta key holding a bare attachment ID.

### Trash and restore

- **Trash** an attachment: all its files (main, original, every size) move to `uploads/ffla-media-trash/<number>/<original path>`, and its post type changes to `ffla_mclean_trash`, so it disappears from the Media Library while the database row and metadata are kept. If any file cannot be moved, the ones already moved go back and the attachment stays live. A broken attachment is just hidden. An orphan file is moved the same way.
- **Restore** moves the files back and returns the attachment to the Media Library. It refuses, and leaves the item in Trash, if a file now exists at the original path (for example a new upload with the same name).
- **Ignore** on a trashed item restores it first.
- The trash folder gets an `.htaccess` file (`Deny from all`) and an empty `index.php`. Servers that do not read `.htaccess`, such as Nginx, need their own rule to block it.

### Deleting for good

- **Delete permanently**, **Empty trash** and auto-empty: an attachment's files are moved back and WordPress deletes the attachment with all its files and metadata; anything left in its numbered trash folder is removed. An orphan file is deleted from the trash folder.
- **Empty trash** only deletes items listed in the Trash tab. Other files in the trash folder are left alone.
- Auto-empty is a daily WP-Cron event, first run one day after the setting is saved. It deletes items trashed more than the chosen number of days ago. Switching the module off clears the schedule.
- With **Skip the trash** on, **Trash**, **Trash all** and `wp ffla-media trash` delete permanently straight away (attachments through WordPress, orphan files from disk). The confirmation messages do not change and still say the action is reversible.

### Ignore

- Ignoring an attachment flags it (post meta `_ffla_mclean_ignored`), so later scans skip it as used. After the next scan it is no longer listed in the Ignored tab, so **Stop ignoring** is only possible until then; afterwards remove the meta key.
- Ignoring an orphan file only lasts until the next scan.

### A new scan clears the lists

Starting a scan (in the browser or WP-CLI) empties the results table: **Issues, Ignored and Trash**. Items already in the trash stay trashed (attachments hidden, files in the trash folder) but are no longer listed, so they cannot be restored, deleted or auto-emptied from this screen. Numbering also starts again, so a new trashed item can land in the same numbered folder as an old one, and deleting the new item permanently removes that whole folder. **Restore or empty the trash before every new scan.** If it already happened, see *Troubleshooting*.

The same attachment can appear twice (for example as *Unused* and as *Duplicate*). Each row is its own trash item: restoring one does not restore the other. If you restore such a file, restore its other row too before emptying the trash, otherwise **Empty trash** deletes it.

### What the module never does

- A scan never moves or deletes files. Only the actions above (Trash, Trash all, Restore, Delete permanently, Empty trash), auto-empty and the WP-CLI `trash` and `empty_trash` commands do.
- The trash folder, hidden files and `index.php` files are never listed as orphan files.
- Restore never overwrites an existing file.
- Paths containing `..` are refused.
- Uninstalling never deletes the trash folder.

## Where it shows up

| Place | What appears |
|---|---|
| **FFL Funnels → Media Cleaner** | Intro with the backup reminder; **Scan** card with *Issues found*, *Reclaimable*, *In trash*, *Ignored*, **Start scan** / **Stop** and a progress bar; **Results** card with the tabs, search, bulk buttons, **Trash all** (Issues tab) and **Empty trash** (Trash tab); settings cards **What to scan**, **Deletion**, **Performance**. |
| **Media → Library** | Trashed attachments disappear until restored. |
| `wp-content/uploads/ffla-media-trash/` | Trashed files, one numbered folder per result. |
| WP-CLI | `wp ffla-media …` |

Shop managers can open the page (it sits under FFL Funnels), but scanning, actions and saving settings are refused for anyone without `manage_options`: the scan stops with an error, the lists stay empty and saving shows "Permission denied."

## Data and uninstall

| Data | Where | On uninstall |
|---|---|---|
| Results | table `{prefix}ffla_mclean_scan` | Dropped |
| Reference cache (rebuilt every scan) | table `{prefix}ffla_mclean_refs` | Dropped |
| Settings | option `ffla_media_cleaner_settings` | Deleted |
| Table version | option `ffla_media_cleaner_db_version` | Deleted |
| Running scan | option `ffla_mclean_job` | Deleted |
| File list during an uploads-folder scan | option `ffla_mclean_file_list` | Deleted |
| Ignore flag | post meta `_ffla_mclean_ignored` | Deleted |
| Auto-empty schedule | cron hook `ffla_mclean_auto_empty` | Cleared |
| Trashed files | `uploads/ffla-media-trash/` | **Kept** |
| Trashed attachments | posts with post type `ffla_mclean_trash` | **Kept, still hidden** |

Uninstall cleanup only runs if the module is switched on when the plugin is deleted. Switching the module off clears the auto-empty schedule and keeps everything else, including the trash. **Restore or empty the trash before uninstalling**: afterwards there is no screen for it and the tables that tie the numbered folders to attachments are gone.

## Troubleshooting

- **"Permission denied", or the scan fails at once.** You need an administrator account (`manage_options`).
- **"The scan hit an error. It has been stopped."** A batch failed or timed out. Lower the batch sizes under **Performance**, or run `wp ffla-media scan`. The next scan starts from scratch.
- **No unused media is found while *Scan the uploads folder for orphan files* is on.** In that combination every attachment's files are recorded as in use before the library check runs, so it reports nothing (broken attachments are still listed if *Check references in content* is off). Run the library scan with the uploads-folder scan off, and the uploads-folder scan separately.
- **Something in use is listed.** **Ignore** it. If it is served from a CDN, use `ffla_mclean_url_anchors`; otherwise check *What can still be flagged by mistake*.
- **An image disappeared after trashing.** Restore it from the Trash tab and check the page again.
- **Restore does nothing for an item.** A file now sits at the original path. Rename or move that file, then restore.
- **The Trash tab is empty but files are still in `ffla-media-trash`** (a new scan ran, or the plugin was uninstalled). Restore by hand: list the hidden attachments with `wp db query "SELECT ID, post_title FROM $(wp db prefix)posts WHERE post_type = 'ffla_mclean_trash'"`, move each attachment's files from `uploads/ffla-media-trash/<number>/` back to the same path under uploads (the path in its `_wp_attached_file` meta), then run `wp post update <ID> --post_type=attachment`.
- **Auto-empty does not run.** It relies on WP-Cron, which needs site visits or a server cron job.
- **An ignored attachment cannot be un-ignored.** Delete its meta: `wp post meta delete <ID> _ffla_mclean_ignored`.

## For developers

**WP-CLI** (available while the module is on)

| Command | What it does |
|---|---|
| `wp ffla-media scan` | Runs a full scan with the saved settings and prints each phase. Like the button, it first clears all results, including the Trash list. |
| `wp ffla-media status` | Issues, reclaimable size, in trash, ignored. |
| `wp ffla-media list [--status=<active\|ignored\|trashed>] [--limit=<n>]` | Table of id, issue, size and path, largest first. Default 50, at most 200. |
| `wp ffla-media trash <id>...` or `wp ffla-media trash --all` | Trashes the given result IDs, or every result in Issues. With *Skip the trash* on this deletes permanently, without a prompt. |
| `wp ffla-media empty_trash [--yes]` | Permanently deletes everything in Trash; asks first unless `--yes`. |

The subcommand is `empty_trash` with an underscore: WP-CLI names subcommands after the method. There are no restore or ignore commands.

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

**AJAX endpoints** (`admin-ajax.php`, all need `manage_options` and the nonce `ffla_mclean_admin`): `ffla_mclean_scan_start`, `ffla_mclean_scan_step`, `ffla_mclean_scan_abort`, `ffla_mclean_results` (`status`, `page`, `search`), `ffla_mclean_action` (`op` = `trash`, `restore`, `ignore`, `unignore` or `delete`; `ids[]`), `ffla_mclean_bulk_all` (`op` = `trash` or `ignore`; `status`, `search`; 40 per call, returns `remaining`), `ffla_mclean_empty_trash`. Settings post to `admin-post.php` with action `ffla_mclean_save_settings`.

**Tables**

- `{prefix}ffla_mclean_scan`: one row per result. `type` 0 = file, 1 = attachment; `post_id`; `path` (uploads-relative, attachments labelled "(+ N thumbnails)"); `size` in bytes; `issue` = `NO_CONTENT` (Unused), `ORPHAN_MEDIA` (Broken), `ORPHAN_FILE` (Orphan file) or `DUPLICATE`; `parent_id` = the original attachment of a duplicate; `ignored`; `deleted` (1 = in Trash); `time` = when found, or last trashed or restored.
- `{prefix}ffla_mclean_refs`: the reference cache. `media_id` or `media_url` (uploads-relative), `origin_type` (a label such as `Content`, `Bricks (ID)`, `WooCommerce Gallery`, `MEDIA LIBRARY`), `origin` (post ID), unique `ref_hash`. Duplicate detection also stores file hashes here as `HASHDUP` rows.

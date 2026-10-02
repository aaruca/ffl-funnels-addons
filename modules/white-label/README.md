# White Label

Brands wp-admin for client stores and limits what client logins can reach. It recolours the admin with a light and a dark palette (each user switches with a sun/moon button), adds FFL Funnels branding, reorders the sidebar for clients, hides and blocks admin screens and admin-bar items for clients, can replace the WordPress dashboard with a branded client dashboard, and makes categories, brands and tags easy to find on products (product editor tools). Clients usually log in as Administrators, so the module tells your staff and clients apart by **email address**, not by role. Module ID: `white-label`, off until it is switched on in **FFL Funnels → Dashboard**. Like every module it needs WooCommerce active; MonsterInsights and SnapFind are optional data sources for the dashboard.

> **Read this first.** Restrictions do nothing until at least one **Exempt email pattern** is saved (or `FFLA_WL_SUPERUSERS` in `wp-config.php` lists at least one address). From then on every logged-in user who does not match is a client: the FFL Funnels menu disappears for them, they cannot open these settings, and every restriction applies. Save the exempt list while logged in as yourself: if the list would not cover the person saving it, their email is added automatically and the page shows a notice saying so.

## What it does

- **Agency branding, for everyone** while the module is on: the FFL Funnels logo and wordmark at the top of the admin sidebar (links to fflfunnels.com), the footer credit "Thank you for growing with FFL Funnels.", and no WordPress logo menu in the admin bar (wp-admin and front end).
- **Light and dark colours**: 32 colour fields plus a dashboard corner radius. Every field has a Light and a Dark value; each user picks the mode with the sun/moon button in the admin bar.
- **Sidebar order and dividers** for clients.
- **Restrictions** for clients: hide menu items (also blocked by direct URL) and remove admin-bar items.
- **Client dashboard**: replaces `/wp-admin/` with quick-link cards, 30-day WooCommerce sales, and MonsterInsights and SnapFind analytics tabs.
- **Product editor tools**, for everyone who can edit products: search on the category, brand and tag boxes and in Quick Edit / Bulk Edit, a folding category tree, ticked brands kept in place, optional parent ticking, and searchable Products list filters with a new tag filter.
- **Import / export** of the whole configuration as JSON.

## Setup

1. Switch on **White Label** in **FFL Funnels → Dashboard**, then open **FFL Funnels → White Label**.
2. **Restrictions** tab: add your staff to **Exempt email patterns**, one per line, e.g. `*@fflfunnels.com`. Optionally add a safety net clients can never edit to `wp-config.php`:
   ```php
   define( 'FFLA_WL_SUPERUSERS', [ 'you@agency.com' ] );
   ```
3. **Styles** tab: set the colours you want for Light and Dark. Edits preview live on the page.
4. **Menu** tab: drag the top-level items into order and add dividers.
5. **Restrictions** tab: tick the menu items and admin-bar items clients should not see.
6. **Dashboard** tab: switch on **Replace the WordPress dashboard** and fill in the quick-link URLs.
7. **Products** tab: the product editor tools are on by default (parent ticking is off); switch off any you do not want.
8. Click **Save Settings**. One button saves the Styles, Menu, Dashboard, Restrictions and Products tabs together.
9. Log in as a client account to check the result. Exempt staff keep the native menu, so the order, dividers and hidden items do not show in your own sidebar.
10. **Import / Export** tab: download a backup (**Download .json**).

## Settings

All settings are on **FFL Funnels → White Label**, in six tabs.

### Styles

Type a hex colour (`#rgb` or `#rrggbb`) or pick one with the swatch; anything else is dropped on save. Colours only take effect once at least one colour (in either mode) or the corner radius is saved; until then wp-admin keeps its stock look. After that, a blank field uses the built-in default below, which is also shown as the field's placeholder. Fields marked *Primary colour* have no built-in default and follow **Primary colour** when blank.

| Setting | What it does | Default (light / dark) |
|---|---|---|
| General → Primary colour | Accent for buttons, links, "Add New" title buttons, sidebar count bubbles, and every hover/current colour left blank. | None (WordPress blue `#2271b1`) |
| General → Primary contrast (button text) | Text on primary buttons and on the sidebar count bubbles. | `#ffffff` / `#ffffff` |
| General → Content borders (tables, cards) | Row lines in list tables (posts, products, orders…). | `#dee4e9` / `#e2e4e9` |
| Sidebar → Background | Admin menu background. | `#fafcfe` / `#151a22` |
| Sidebar → Border & dividers | Sidebar edge and divider lines. | `#e2e4e9` / `#2c3338` |
| Sidebar → Item text | Menu item text. | `#1d2327` / `#e7edf7` |
| Sidebar → Item icon | Menu icons, including plugin SVG icons. | `#50575e` / `#a7aaad` |
| Sidebar → Item hover background | Background of the hovered item. | Primary colour |
| Sidebar → Item hover text | Text of the hovered item. | `#ffffff` / `#ffffff` |
| Sidebar → Item hover icon | Icon of the hovered item. | `#ffffff` / `#ffffff` |
| Sidebar → Current item background | Background of the open section. | Primary colour |
| Sidebar → Current item text | Text of the open section. | `#ffffff` / `#ffffff` |
| Sidebar → Current item icon | Icon of the open section. | `#ffffff` / `#ffffff` |
| Submenu → Background | Submenu background. | `#eff3f6` / `#1f272d` |
| Submenu → Item text | Submenu text. | `#3c434a` / `#c3c4c7` |
| Submenu → Item hover text | Hovered submenu text. | Primary colour |
| Submenu → Current item text | Current submenu item. | Primary colour |
| Admin bar → Background | Top bar background. | `#fafcfe` / `#151a22` |
| Admin bar → Bottom border | Line under the top bar. | `#e2e4e9` / `#2c3338` |
| Admin bar → Item text | Top bar text. | `#1d2327` / `#e7edf7` |
| Admin bar → Item icon | Top bar icons. | `#50575e` / `#a7aaad` |
| Admin bar → Item hover background | Hovered top bar item. | Primary colour |
| Admin bar → Item hover text | Hovered top bar text. | `#ffffff` / `#ffffff` |
| Admin bar dropdown → Background | Dropdown background. | `#ffffff` / `#1f272d` |
| Admin bar dropdown → Item text | Dropdown text. | `#3c434a` / `#c3c4c7` |
| Admin bar dropdown → Item hover background | Hovered dropdown item. | Primary colour |
| Admin bar dropdown → Item hover text | Hovered dropdown text. | `#ffffff` / `#ffffff` |
| Dashboard → Base corner radius | Corner radius of the client dashboard cards, 0–40 px (0 = square); smaller items scale from it. | Blank (14 px) |
| Dashboard → Page background | Client dashboard background. The **light** value is also the content-area background of every admin page, in both modes. | `#eff3f6` / `#1f272d` |
| Dashboard → Card background | Dashboard cards. | `#ffffff` / `#151a22` |
| Dashboard → Text | Dashboard text. | `#0b1220` / `#f8fafc` |
| Dashboard → Muted text | Secondary dashboard text. | `#6c737c` / `#8c949d` |
| Dashboard → Border | Dashboard borders. | `#dee4e9` / `#2e363e` |

### Menu

| Setting | What it does | Default |
|---|---|---|
| Sidebar order | Drag the top-level menu items into the order clients see. Menus added later (a new plugin) appear at the bottom until you move them. | WordPress order |
| Add divider | Adds a divider you can drag anywhere in the list; remove it with its × button. | No dividers |

### Dashboard

| Setting | What it does | Default |
|---|---|---|
| Replace the WordPress dashboard | Turns `/wp-admin/` into the branded client dashboard (below). | Off |
| Support | URL of the **Help & Support** card. Opens in a new tab. | Blank (card hidden) |
| Knowledge Base | URL of the **Knowledge Base** card. Opens in a new tab. | Blank (card hidden) |
| Cockpit | URL of the **Cockpit** card, e.g. `admin.php?page=g-ffl-cockpit-settings`. Opens in the same tab. | Blank (card hidden) |
| Command Center | URL of the **Command Center** (email marketing) card. Opens in a new tab. | Blank (card hidden) |

### Restrictions

| Setting | What it does | Default |
|---|---|---|
| Exempt email patterns | One per line. `*` matches any run of characters; the whole address must match; case is ignored. `*@fflfunnels.com`, `adeel*` or a full address. Matching users keep full access and the native menu. | Empty (restrictions off) |
| Menu visibility | Tick any menu item or sub-item to hide it from clients and block its page by URL; hiding a content list also blocks adding and editing that content. Grouped by top-level menu. | Nothing hidden |
| Admin bar | Tick top-level admin-bar items to remove for clients, listed with their node ID. Only needed for items that do not link to a page hidden above (dropdowns, custom URLs). | Nothing removed |

### Products

| Setting | What it does | Default |
|---|---|---|
| Category, brand & tag search | Search fields on the Edit Product screen's taxonomy boxes and on the Quick Edit / Bulk Edit checklists, a "Selected only" view and a tag checklist. | On |
| Keep ticked brands and terms in place | Stops WordPress moving ticked terms to the top of Brands and other nested product taxonomies (WooCommerce already does this for categories). | On |
| Collapsible category tree | Fold arrows on parent categories, **Expand all** / **Collapse all**; long lists start folded. | On |
| Tick parent categories automatically | Ticking a subcategory ticks its parents. | Off |
| Better Products list filters | A **Filter by tag** dropdown on Products; the category, brand and tag filters become searchable. | On |

These apply to everyone who can edit products, exempt staff and clients alike. A setting that was never saved uses its default.

### Import / Export

- **Export settings** shows the configuration as JSON, with **Download .json** (file `white-label-<host>-<YYYYMMDD>.json`) and **Copy to clipboard**.
- **Import settings** takes a `.json` file (**Choose a .json file**) or pasted JSON (**…or paste JSON**); the file is used when both are given. After a confirmation it **replaces** the Styles, Menu, Dashboard, Restrictions and Products settings; a section missing from the file is reset to empty (for Products: back to the defaults). It cannot be undone, so export first. Imported data goes through the same checks as the form. Exports from before light/dark mode (one flat set of colours) import as the dark palette, the way those settings were already shown.

## How it works

### Staff and clients

- A user is **exempt** when their email matches an exempt pattern or `FFLA_WL_SUPERUSERS`, or when they are a multisite super admin. Everyone else who is logged in is a client, whatever their role.
- Restrictions (hidden menu items, blocked pages, removed admin-bar items, the hidden FFL Funnels menu, locked White Label settings) start only once there is at least one exempt pattern or a non-empty `FFLA_WL_SUPERUSERS`.
- Once they are active, only exempt staff can open, save, import or export these settings. Clients get "You do not have permission to access these settings."
- **No self-lockout.** On every save and import, if the resulting list would switch restrictions on without covering you, your email is added to the exempt patterns and the page shows "Your email address (…) was added to the exempt staff list so you keep access to these settings." `FFLA_WL_SUPERUSERS` only counts as cover when it matches you.
- Until the first pattern is saved nobody is exempt, so the menu order and dividers apply to everyone, you included.

### Colours and dark mode

- The sun/moon button sits on the right of the admin bar in wp-admin, for every user. The choice is saved per user and applied as a body class before the page paints, so there is no flash. **New users start in dark mode.**
- Colours apply to everyone, staff included. Until a colour or the radius is saved, the button only switches the client dashboard.
- The content area of every admin page uses the light **Dashboard → Page background** in both modes, so third-party plugin screens keep their light contrast.
- Plugin SVG menu icons are recoloured through WordPress's own icon painter with the sidebar icon colours and repaint instantly when the mode changes. Plugin icons shipped as images are shown as a light or dark silhouette.
- With colours active, WordPress buttons, inputs, cards and notices get square corners (fixed in the stylesheet, not a setting).
- The Styles tab previews edits to the sidebar, top bar, submenus, buttons and borders on the page itself; dashboard colours show on the dashboard. The preview lasts until reload; click **Save Settings** to keep it. On a site with no saved colours, the preview stays on the stock look until you enter a first colour (or the radius), exactly as saving would.

### Sidebar order

- Applies to clients only. For them, the separators WordPress and plugins add are hidden (even with no saved order) and only your dividers show.
- Saved items come first in the saved order; items not in the saved order follow in their usual order; saved items that no longer exist are dropped.

### Hiding and blocking

- Ticking a top-level item ticks all its sub-items. Unticking one sub-item unticks the top-level box; the other sub-items stay hidden.
- A hidden item is removed from the sidebar and blocked by URL. Opening it redirects to the Dashboard; if the Dashboard is hidden too, to Profile; if both are hidden, the client sees "Access to this area of the dashboard is restricted."
- Blocking matches the menu page's own address (`plugins.php`, `edit.php?post_type=page`, `admin.php?page=<slug>`).
- Hiding a content list also blocks adding and editing that content: hiding **Posts** (`edit.php`), **Media** (`upload.php`) or any `edit.php?post_type=<type>` list (Pages, Products…) blocks `post-new.php` and `post.php` for that post type. A filtered list (more than a `post_type` in the address) does not.
- The **FFL Funnels** menu is always hidden from clients, and every page under it (the FFL Funnels dashboard and each module's settings page, White Label included) is blocked by URL.
- Admin-bar items that link to a hidden or blocked page are removed automatically, in wp-admin and on the front end. Removal runs after every plugin has added its items, so very late ones (for example WP Rocket) are caught.
- Restrictions work on the admin screens. They do not change what the user's role may do through other routes, such as the REST API.
- The **Admin bar** list is captured from the admin bar as staff see it in wp-admin and kept for a day, so items that only appear on the front end are not listed.

### Client dashboard

When **Replace the WordPress dashboard** is on, `/wp-admin/` shows a greeting, the quick-link cards that have a URL, and:

- **Business at a glance**: **Sales**, **Orders** and **Average order value** for the last 30 days including today, in the site's time zone, with change against the previous 30 days, and a daily **Sales overview** chart. Sales are the full totals of shop orders in WooCommerce's paid statuses, by order creation date; refunds are not subtracted. Cached for 10 minutes.
- **Analytics** with **MonsterInsights** and **SnapFind** tabs and a 7 / 30 / 90-day range. Each user's last tab and range are remembered. Tables sort by clicking a heading or with Enter/Space.
- The refresh icon next to the date range recalculates the sales figures and bypasses the analytics caches.
- WordPress and plugin widgets, the welcome panel, Screen Options, Help and admin notices are removed on this page, and the WordPress version is hidden from its footer. The FFL Funnels footer credit stays, as on every admin page.
- The branded dashboard is shown to users who can edit theme options: Administrators, and Shop Managers (WooCommerce gives them that capability). The analytics requests require the same capability. Users without it, such as Editors, keep the standard WordPress dashboard with its widgets.

**MonsterInsights tab.** Reads reports through MonsterInsights' existing Google connection; it adds no Google tag, tracking event or Google sign-in.

- Before anything is shown (cached or not) it checks that MonsterInsights is active, the user has MonsterInsights' report permission (`monsterinsights_view_dashboard`), reporting is not disabled, Google Analytics is connected (site or network), and, on Pro, that the license is valid. Otherwise it shows the reason; the **Open MonsterInsights settings** button appears only for users who can change MonsterInsights settings.
- Ranges are complete days ending yesterday (site time zone), compared with the previous period of the same length. Without Pro only **30 days** works.
- MonsterInsights 11.2 or later: **Sessions**, **Pageviews**, **New users**, **Engagement rate**, a **Traffic trend** chart (sessions and pageviews), **Top pages** (10) and **Traffic sources** (5).
- On 11.2 or later with Pro, an eligible license and the eCommerce Addon, also: **Purchases**, **Analytics revenue**, **Average order value**, **Purchases / sessions** and **Top products** (10). Revenue is in the Google Analytics property currency and is separate from the WooCommerce sales tiles.
- Older MonsterInsights versions, or a network-only connection: **Sessions**, **Pageviews**, **Bounce rate**, the trend and **Top pages**; commerce details point to MonsterInsights.
- Demo or sample data is refused, and a missing value shows "—", never 0. Results are cached per user and site for 10 minutes (failures for 30 seconds); a refresh within 30 seconds of the last fetch reuses it.

**SnapFind tab.** Needs SnapFind's analytics. Shows **Searches**, **Product clicks**, **Search CTR** and **Search conversion** with change against the previous period, a funnel (Searches → Product clicks → Purchases) and the top 10 search terms. Cached for 10 minutes.

### Product editor tools

They work on WordPress's own boxes, in the browser, and never replace them: the product is saved exactly as before, and other plugins that read or change those boxes keep working.

- **Category and brand boxes** (any nested product taxonomy with a box): a search field above the *All* / *Most Used* tabs filters the *All* list as you type. Matching ignores case and accents (`senal` finds *Señales*), matches anywhere in the name, and every word typed must match (`pump shot` finds *Pump Action Shotguns*). The parents of each match stay visible, greyed, so you can tell which branch it is in; matches are highlighted and counted, and "No matches" shows when nothing fits. **Esc** clears the search; **Enter** never submits the product. **Selected only** shows just the ticked terms (with their parents) and a live "N selected" count. The *All* panel can be dragged taller. Terms added with **+ Add new category** become searchable straight away.
- **Folding tree**: every parent category gets an arrow that shows or hides its subcategories, plus **Expand all** and **Collapse all**. Lists with 15 or more terms (filter `ffla_term_search_collapse_min`) start folded, with every branch that holds a ticked term open. While searching, folded branches open so every match shows.
- **Tags** (and other flat product taxonomies): under WordPress's own field, a search field and a scrollable checklist of every existing tag. Ticking assigns the tag, unticking removes it, through WordPress's own tag field, so the chips and the checklist always agree (adding a tag by typing, or removing a chip, updates the ticks). With more than 2,000 tags (filter `ffla_term_search_inline_limit`) the list shows the product's tags and searches the server from 2 characters (50 results).
- **Quick Edit and Bulk Edit** on Products: the same search and folding tree above each category checklist.
- **Tick parent categories automatically**: ticking a subcategory ticks every parent above it, in all of these boxes. Unticking never changes anything else.
- **Products list**: **Filter by tag** lists the tags in use with their product counts (up to 1,000). The category (when WooCommerce lists it as a plain dropdown), brand and tag filters become searchable dropdowns.
- Only on the classic product editor. WooCommerce's new block-based product editor is not covered.

## Where it shows up

| Place | What | Who |
|---|---|---|
| **FFL Funnels → White Label** | Settings (needs the `manage_woocommerce` capability). | Everyone with access until restrictions are active; then exempt staff only |
| Top of the admin sidebar | FFL Funnels logo and wordmark | Everyone |
| Admin footer | "Thank you for growing with FFL Funnels." | Everyone |
| Admin bar, wp-admin and front end | WordPress logo menu removed | Everyone |
| Admin bar, right side (wp-admin) | Sun/moon light/dark button | Everyone |
| All of wp-admin | Colours (once one is saved) | Everyone |
| Admin sidebar | Order, dividers, no default separators | Clients |
| Sidebar, admin bar (wp-admin and front end), page URLs | Hidden and blocked items, hidden FFL Funnels menu | Clients, once restrictions are active |
| `/wp-admin/` | Client dashboard (when switched on) | Users who can edit theme options (Administrators, Shop Managers) |
| **Products → Edit Product**, Quick Edit, Bulk Edit, Products list filters | Product editor tools | Everyone who can edit products |

## Data and uninstall

- **Settings**: one option, `ffla_white_label_settings` (not autoloaded), created empty when the module is first switched on.
- **Per user** (user meta): `ffla_wl_theme_mode` (light/dark), `ffla_wl_dashboard_analytics_source` and `ffla_wl_dashboard_analytics_range` (last dashboard tab and range).
- **Caches** (transients): `ffla_wl_adminbar_nodes` (admin-bar list, 1 day), `ffla_wl_dash_*` (sales and SnapFind, 10 minutes), `ffla_wl_mi_*` (MonsterInsights, 10 minutes or 30 seconds after a failure).
- Switching the module off keeps everything, so switching it on again restores the configuration.
- Deleting the plugin removes the option, the three user meta keys and the cached transients.
- An export contains your exempt email patterns and the site address. Treat the file accordingly.

## Troubleshooting

- **My menu order, dividers or hidden items don't show.** You are exempt staff; you keep the native menu. Check with a client login.
- **Restrictions don't apply to a client.** No exempt pattern is saved yet, or the client's email matches a pattern (wildcards match more than you might expect), or they are a multisite super admin.
- **Locked out of White Label.** Add your email to `FFLA_WL_SUPERUSERS` in `wp-config.php`; that list is always exempt.
- **A client can still open a page.** Blocking covers the menu page's own address (and, for content lists, adding and editing that content). If a sub-page has its own address, tick that sub-item too.
- **An admin-bar item is still there.** It does not link to a hidden page; tick it under **Admin bar**. If the list says "No admin-bar items detected", reload the page as staff: the list is captured from the admin bar as staff load wp-admin pages.
- **Colours don't change.** Nothing applies until one valid colour or the radius is saved. Values that are not `#rgb` or `#rrggbb` are dropped on save.
- **Everyone sees the dark palette.** Dark is the default for every user until they click the sun/moon button.
- **Some users see the standard dashboard.** The branded dashboard only shows to users who can edit theme options (Administrators, Shop Managers); Editors and other roles keep the WordPress dashboard.
- **Import errors.** "No file or JSON was provided." or "That file is not a valid White Label export." The file must be a White Label export or a settings object with at least one of `styles`, `restrictions`, `menu`, `dashboard`, `term_search`.
- **No search box on a product's category box.** **Category, brand & tag search** is off on the Products tab, or the page uses WooCommerce's block-based product editor.
- **The category list starts folded.** Lists with 15 or more terms start folded on purpose; use **Expand all**, search, or switch off **Collapsible category tree**.
- **MonsterInsights tab messages.**
  - "Activate and connect MonsterInsights…": MonsterInsights is not active.
  - "Your role does not have permission to view MonsterInsights reports…": grant report access in MonsterInsights.
  - "Reporting is disabled in MonsterInsights settings." / "Connect Google Analytics in MonsterInsights…" / "Resolve the MonsterInsights license issue…": fix it in MonsterInsights.
  - "Select 30 days, or use MonsterInsights Pro for other date ranges.": only 30 days without Pro.
- **SnapFind tab.** "SnapFind analytics is not active on this site." means the SnapFind analytics class is missing; "This SnapFind version does not expose analytics data." means the installed version lacks the expected interface.

## For developers

- **Constant**: `FFLA_WL_SUPERUSERS` — array of email patterns in `wp-config.php`, always exempt; also activates restrictions when it has a non-empty entry.
- **Filters**
  - `ffla_wl_is_exempt` (`bool $exempt`, `WP_User $user`) — final exemption decision, memoised per request.
  - `ffla_wl_admin_footer_text` (`string $text`) — the footer credit HTML (dynamic parts already escaped). Runs at `PHP_INT_MAX` on `admin_footer_text`.
  - `ffla_term_search_post_types` (`string[] $post_types`, default `['product']`) — edit screens that get the product editor tools.
  - `ffla_term_search_inline_limit` (`int $limit` 2000, `string $taxonomy`) — flat taxonomies with more terms are searched over AJAX.
  - `ffla_term_search_collapse_min` (`int` 15) — lists with at least this many terms start folded.
- **JS event**: `ffla-wl-theme-changed` on `document` when the mode is toggled; `event.detail.mode` is `light` or `dark`.
- **Body classes** (wp-admin): `ffla-theme-light` / `ffla-theme-dark`.
- **CSS variables**: `--ffla-wl-<key>` per mode on `body.ffla-theme-<mode>` (keys as in the option below), plus `--ffla-wl-dashRadius` and `--ffla-wl-contentBg` on `body.wp-admin.wp-core-ui`.
- **Option shape** (`ffla_white_label_settings`), read with `White_Label_Settings::get( 'styles.dark.primaryColor' )` (dot paths):
  ```
  styles:       { light: { <key>: '#hex' }, dark: { <key>: '#hex' }, dashRadius: 0–40 }
  restrictions: { exempt_emails: [], hidden_menu: [], hidden_adminbar: [] }
  menu:         { top: [] }
  dashboard:    { enabled: bool, links: { support, knowledge_base, cockpit, command_center } }
  term_search:  { enabled, keep_order, collapse_tree, list_filters: bool (default true), auto_parents: bool (default false) }
  ```
  `hidden_menu` holds menu slugs, sub-items as `parent::child`. Divider tokens in `menu.top` start with `ffla-divider-`.
- **Export envelope**: `{ marker: "ffla_white_label", version, exported (UTC ISO 8601), site, settings }`. Import also accepts a bare settings object.
- **Endpoints**: `admin-post.php` actions `ffla_wl_save_settings`, `ffla_wl_export`, `ffla_wl_import` (`manage_woocommerce`, exempt once restrictions are active, nonces). AJAX `ffla_wl_term_search` (POST `taxonomy`, `q`; nonce `ffla_wl_term_search`; the taxonomy's `assign_terms` capability) for large tag lists, `ffla_wl_toggle_theme` (POST `mode`, nonce `ffla_wl_theme_mode`) and `ffla_wl_dashboard_analytics` (POST `source` = `google` | `snapfind`, `range` = 7 | 30 | 90, optional `force=1`; needs `edit_theme_options`). The MonsterInsights tab is `google` internally.
- **DOM**: admin-bar node `ffla-wl-theme-toggle`; sidebar brand `#ffla-wl-brand`.
- **Redirect flags** on the settings page: `settings-updated=1`, `ffla_wl_import=success|empty|invalid`, and `ffla_wl_self_exempt=1` when the save or import added the current user to the exempt list.
- **Tests**: `php tests/smoke/white-label-smoke.php` (blocking of FFL Funnels pages and hidden post types, admin-bar removal, flat-format import, self-exempt flag, dashboard capability, site time zone sales window), `php tests/smoke/white-label-monsterinsights-smoke.php [legacy|missing|no-addon]` (offline report checks) `tests/smoke/white-label-monsterinsights-ui-smoke.js` (Playwright, offline fixtures) and `tests/smoke/white-label-term-search-ui-smoke.js` (Playwright against a test site: search, folding tree, parent ticking, tags, Quick and Bulk Edit, list filters, settings, 390px layout; creates and removes its own data).

<?php
/**
 * Media Cleaner lifecycle checks against a real WordPress + WooCommerce site.
 *
 * Usage: wp eval-file tests/smoke/media-cleaner-lifecycle-wpcli.php
 * Creates its own attachments under uploads/2099/01 and removes them again.
 */
// Media Cleaner regression checks on the live test site (wp eval-file).
global $wpdb;
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function ck($c, $m) { if ($c) { $GLOBALS['pass']++; echo "  ok  $m\n"; } else { $GLOBALS['fail']++; echo "  FAIL $m\n"; } }

$saved_settings = get_option('ffla_media_cleaner_settings', null);
update_option('ffla_media_cleaner_settings', array_merge(Media_Cleaner_Core::get_default_settings(), [
    'scan_media_library' => '1', 'scan_filesystem' => '1', 'detect_duplicates' => '1', 'scan_content' => '1',
]), false);
Media_Cleaner_Core::flush_settings_memo();
Media_Cleaner_Database::install();

$up = wp_upload_dir();
$base = trailingslashit($up['basedir']); $GLOBALS['base'] = $base;
$sub = 'mctest/2026/10';
// Use a real year/month folder so the filesystem scan considers it.
$ym = '2099/01'; $GLOBALS['ym'] = $ym;
wp_mkdir_p($base . $ym);

// Tiny PNGs with different content.
function mc_png($seed) { $im = imagecreatetruecolor(4, 4); imagefill($im, 0, 0, imagecolorallocate($im, $seed % 255, 10, 20)); ob_start(); imagepng($im); return ob_get_clean(); }
function mc_attach($name, $bytes) {
    $base = $GLOBALS['base']; $ym = $GLOBALS['ym'];
    if (!$base || !$ym) { throw new Exception('fixture paths missing'); }
    file_put_contents($base . $ym . '/' . $name, $bytes);
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit'], $base . $ym . '/' . $name);
    update_post_meta($id, '_wp_attached_file', $ym . '/' . $name);
    wp_update_attachment_metadata($id, ['file' => $ym . '/' . $name, 'width' => 4, 'height' => 4, 'sizes' => []]);
    return $id;
}
$created_posts = [];
$A = mc_attach('mc-unused-a.png', mc_png(1));
$B = mc_attach('mc-used-b.png', mc_png(2));
$C = mc_attach('mc-dup-used-c.png', mc_png(1));   // same bytes as A, used
$D = mc_attach('mc-dup-unused-d.png', mc_png(1)); // same bytes as A, unused
file_put_contents($base . $ym . '/mc-orphan-file.png', mc_png(9));
wp_mkdir_p($base . 'someplugin-folder');
file_put_contents($base . 'someplugin-folder/mc-plugin-file.png', mc_png(8));
$post = wp_insert_post(['post_title' => 'MC test', 'post_status' => 'publish', 'post_type' => 'page',
    'post_content' => '<img src="' . $up['baseurl'] . '/' . $ym . '/mc-used-b.png"> <img class="wp-image-' . $C . '" src="x">']);
$created_posts[] = $post;
$atts = [$A, $B, $C, $D];

function mc_scan() {
    $core = new Media_Cleaner_Core(); $GLOBALS['ffla_mclean'] = $core;
    $sc = new Media_Cleaner_Scanner(new Media_Cleaner_Engine($core));
    $p = $sc->start(true); $g = 0;
    do { $p = $sc->step(); $g++; } while (empty($p['done']) && $g < 10000);
}
function mc_rows($where = '1=1') { global $wpdb; return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ffla_mclean_scan WHERE $where"); }
function mc_row_for($post_id) { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffla_mclean_scan WHERE post_id = %d", $post_id)); }
function mc_row_path($path) { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffla_mclean_scan WHERE type = 0 AND path = %s", $path)); }

echo "Scan 1\n";
mc_scan();
ck(count(mc_row_for($A)) === 1 && mc_row_for($A)[0]->issue === 'NO_CONTENT', 'unused A flagged once even with the uploads-folder scan on');
ck(count(mc_row_for($B)) === 0, 'used B not flagged'); if (mc_row_for($B)) { print_r(mc_row_for($B)); echo "B=$B C=$C post content: ", get_post_field('post_content', $post), "\n"; }
ck(count(mc_row_for($C)) === 0, 'used duplicate C not flagged');
ck(count(mc_row_for($D)) === 1, 'unused duplicate D has exactly one row');
ck(count(mc_row_path($ym . '/mc-orphan-file.png')) === 1, 'orphan file in a year/month folder flagged');
ck(count(mc_row_path('someplugin-folder/mc-plugin-file.png')) === 0, 'other plugin folder not scanned');

$core = new Media_Cleaner_Core(); $GLOBALS['ffla_mclean'] = $core; $m = new Media_Cleaner_Manager($core);
$rowA = mc_row_for($A)[0];
ck($m->trash((int) $rowA->id), 'trash A');
ck(get_post_type($A) === 'ffla_mclean_trash' && !file_exists($base . $ym . '/mc-unused-a.png'), 'A hidden and file moved');
$orphan = mc_row_path($ym . '/mc-orphan-file.png')[0];
ck($m->ignore((int) $orphan->id, true), 'ignore orphan file');
$max_before = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}ffla_mclean_scan");

echo "Scan 2 (rescan keeps Trash and Ignored)\n";
mc_scan();
$ra = mc_row_for($A);
ck(count($ra) === 1 && (int) $ra[0]->deleted === 1 && (int) $ra[0]->id === (int) $rowA->id, 'A trash entry survives the rescan');
$ro = mc_row_path($ym . '/mc-orphan-file.png');
ck(count($ro) === 1 && (int) $ro[0]->ignored === 1, 'ignored orphan file stays ignored and is not re-reported');
$min_new = (int) $wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}ffla_mclean_scan WHERE deleted = 0 AND ignored = 0");
ck($min_new > $max_before, 'new result IDs never reuse old ones');

echo "Restore + stale duplicate row\n";
// Legacy situation: a second trashed row for A (as older versions allowed).
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $A, 'path' => 'x', 'deleted' => 1, 'issue' => 'DUPLICATE']);
$stale = (int) $wpdb->insert_id;
ck($m->restore((int) $rowA->id), 'restore A');
ck(get_post_type($A) === 'attachment' && file_exists($base . $ym . '/mc-unused-a.png'), 'A back in the library with its file'); echo '   type=', get_post_type($A), ' file=', var_export(file_exists($base . $ym . '/mc-unused-a.png'), true), ' paths=', json_encode($core->get_paths_from_attachment($A)), "\n";
// Even if a stale row survived, deleting it must not touch the live attachment.
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $A, 'path' => 'x', 'deleted' => 1, 'issue' => 'DUPLICATE']);
$m->empty_trash();
ck(get_post($A) && get_post_type($A) === 'attachment' && file_exists($base . $ym . '/mc-unused-a.png'), 'empty trash does not delete a restored attachment');

echo "Trash twice through two rows\n";
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $D, 'path' => 'd2', 'deleted' => 0, 'issue' => 'DUPLICATE']);
$d2 = (int) $wpdb->insert_id;
$d1 = (int) mc_row_for($D)[0]->id;
ck($m->trash($d1), 'trash D through first row');
ck(count(mc_row_for($D)) === 1, 'second open row for D removed when D is trashed');

echo "Recovery of entries lost by older versions\n";
$E = mc_attach('mc-stranded-e.png', mc_png(5));
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $E, 'path' => $ym . '/mc-stranded-e.png', 'deleted' => 0, 'issue' => 'NO_CONTENT']);
$re = (int) $wpdb->insert_id;
$m->trash($re);
file_put_contents($base . $ym . '/mc-stranded-file.png', mc_png(6));
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 0, 'path' => $ym . '/mc-stranded-file.png', 'deleted' => 0, 'issue' => 'ORPHAN_FILE']);
$rf = (int) $wpdb->insert_id;
$m->trash($rf);
// Simulate the old TRUNCATE: rows gone, files and hidden post left behind.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ffla_mclean_scan WHERE id IN (%d, %d)", $re, $rf));
$n = $m->adopt_stranded();
ck($n === 2, "two lost items recovered ($n)");
$newE = mc_row_for($E);
ck(count($newE) === 1 && (int) $newE[0]->deleted === 1 && (int) $newE[0]->id !== $re, 'E has a new Trash entry with a new folder');
ck(!is_dir(Media_Cleaner_Trash::dir() . '/' . $re), 'old folder emptied');
ck($m->restore((int) $newE[0]->id) && get_post_type($E) === 'attachment' && file_exists($base . $ym . '/mc-stranded-e.png'), 'recovered E restores');
$nf = mc_row_path($ym . '/mc-stranded-file.png');
ck(count($nf) === 1 && (int) $nf[0]->deleted === 1, 'loose file recovered into Trash');
ck($m->restore((int) $nf[0]->id) && file_exists($base . $ym . '/mc-stranded-file.png'), 'recovered loose file restores');

echo "Permanent delete when a new file took the old name\n";
$F = mc_attach('mc-reused-f.png', mc_png(7));
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $F, 'path' => 'f', 'deleted' => 0, 'issue' => 'NO_CONTENT']);
$rF = (int) $wpdb->insert_id;
$m->trash($rF);
file_put_contents($base . $ym . '/mc-reused-f.png', 'NEW FILE');
$m->delete_permanently($rF);
ck(!get_post($F), 'F attachment deleted');
ck(file_exists($base . $ym . '/mc-reused-f.png') && file_get_contents($base . $ym . '/mc-reused-f.png') === 'NEW FILE', 'the newer file at the old path is untouched');
ck(!is_dir(Media_Cleaner_Trash::dir() . '/' . $rF), 'F trash folder removed');
@unlink($base . $ym . '/mc-reused-f.png');

echo "Bucket collision guard\n";
$G = mc_attach('mc-guard-g.png', mc_png(11));
$wpdb->insert($wpdb->prefix . 'ffla_mclean_scan', ['time' => current_time('mysql'), 'type' => 1, 'post_id' => $G, 'path' => 'g', 'deleted' => 0, 'issue' => 'NO_CONTENT']);
$rG = (int) $wpdb->insert_id;
wp_mkdir_p(Media_Cleaner_Trash::dir() . '/' . $rG . '/2099/01');
file_put_contents(Media_Cleaner_Trash::dir() . '/' . $rG . '/2099/01/mc-legacy-leftover.png', 'LEGACY');
ck($m->trash($rG), 'trash into a folder that held legacy files');
$legacy = mc_row_path('2099/01/mc-legacy-leftover.png');
ck(count($legacy) === 1 && (int) $legacy[0]->deleted === 1, 'legacy file re-homed under its own Trash entry first');
ck(file_exists(Media_Cleaner_Trash::dir() . '/' . $legacy[0]->id . '/2099/01/mc-legacy-leftover.png'), 'legacy file now in its own folder');

echo "Uninstall restore\n";
require_once dirname(__DIR__, 2) . '/modules/media-cleaner/includes/uninstall-restore.php';
$res = ffla_mclean_uninstall_restore();
ck(get_post_type($G) === 'attachment' && file_exists($base . $ym . '/mc-guard-g.png'), 'uninstall restore brings G back');
ck(get_post_type($D) === 'attachment' && file_exists($base . $ym . '/mc-dup-unused-d.png'), 'uninstall restore brings D back');
ck(file_exists($base . '2099/01/mc-legacy-leftover.png'), 'uninstall restore brings loose files back');
echo '  restored=' . $res['restored'] . ' left=' . $res['left'] . "\n";

echo "Protection files\n";
Media_Cleaner_Trash::ensure_dir();
ck(file_exists(Media_Cleaner_Trash::dir() . '/web.config') && strpos(file_get_contents(Media_Cleaner_Trash::dir() . '/.htaccess'), 'Require all denied') !== false, 'trash folder has Apache 2.4 and IIS rules');
ck(preg_match('/^ffla-media-trash-[a-z0-9]{16}$/', Media_Cleaner_Trash::dirname()) === 1, 'new site gets an unguessable trash folder name');

// Cleanup.
foreach ([$A, $B, $C, $D, $E, $G] as $id) { if (get_post($id)) { wp_update_post(['ID' => $id, 'post_type' => 'attachment']); wp_delete_attachment($id, true); } }
foreach ($created_posts as $p) { wp_delete_post($p, true); }
foreach (['mc-orphan-file.png', 'mc-stranded-file.png'] as $f) { @unlink($base . $ym . '/' . $f); }
@unlink($base . '2099/01/mc-legacy-leftover.png');
@unlink($base . 'someplugin-folder/mc-plugin-file.png'); @rmdir($base . 'someplugin-folder');
@rmdir($base . '2099/01'); @rmdir($base . '2099');
$wpdb->query("DELETE FROM {$wpdb->prefix}ffla_mclean_scan");
if ($saved_settings === null) { delete_option('ffla_media_cleaner_settings'); } else { update_option('ffla_media_cleaner_settings', $saved_settings); }

echo "\n" . $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed\n";
if ($GLOBALS["fail"] > 0) { exit(1); }

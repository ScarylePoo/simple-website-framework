<?php
/*
    editor-backup.php
    Page versions, a trash for deleted pages, and whole-site backup archives.
    Included by the editor (index.php, editor-save.php, editor-download.php)
    and by backup-cli.php. Never access this file directly.

    Everything lives under one directory, $backupDir in config.php, which
    defaults to backups/ in the site root:

        backups/
          .htaccess            denies all web access (Apache)
          index.html           empty, so a directory listing shows nothing
                               even on a server that ignores .htaccess
          versions/<page>/<stamp>.html     previous versions of each page
          trash/<page>/<stamp>.html        pages that were deleted
          archives/swf-<site>-<kind>-<stamp>-<how>.zip   content and full-site backups

    The directory is created on first use with both protection files. If
    your web server is not Apache, point $backupDir at a folder outside the
    web root instead; everything here works with any writable path.

    All functions assume SWF_ROOT is defined (the site root with a trailing
    slash) and config.php has been loaded, so the $backup* settings and
    $editor* settings are in scope as globals.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

// ── Settings, with defaults for installs whose config.php predates them ───

function backup_setting($name, $default) {
    return isset($GLOBALS[$name]) && $GLOBALS[$name] !== '' ? $GLOBALS[$name] : $default;
}

function backup_keep_versions() { return max(1, (int) backup_setting('backupKeepVersions', 20)); }
function backup_keep_archives() { return max(1, (int) backup_setting('backupKeepArchives', 7)); }
function backup_auto_days()     { return max(0, (int) backup_setting('backupAutoDays', 7)); }

// ── The backup directory ──────────────────────────────────────────────────

/*
    Resolve $backupDir to an absolute path with a trailing slash, creating it
    and its protection files if needed. Returns false if it cannot be made
    writable, which callers treat as "backups unavailable" and report loudly
    rather than silently carrying on without them.
*/
function backup_dir($sub = '') {
    static $root = null;
    if ($root === null) {
        $configured = backup_setting('backupDir', 'backups');
        $configured = str_replace('\\', '/', $configured);
        // Absolute paths (Unix or Windows drive) are used as given; anything
        // else is relative to the site root.
        if ($configured[0] === '/' || preg_match('#^[A-Za-z]:/#', $configured)) {
            $root = rtrim($configured, '/') . '/';
        } else {
            $root = SWF_ROOT . trim($configured, '/') . '/';
        }
        if (!is_dir($root) && !@mkdir($root, 0755, true)) {
            $root = false;
        } elseif (!is_writable($root)) {
            $root = false;
        } else {
            backup_protect_dir($root);
        }
    }
    if ($root === false) {
        return false;
    }
    if ($sub !== '') {
        $path = $root . trim($sub, '/') . '/';
        if (!is_dir($path) && !@mkdir($path, 0755, true)) {
            return false;
        }
        return $path;
    }
    return $root;
}

/*
    Write the two files that keep the directory private. Rewritten on every
    run so a deleted or edited copy is put back.

    .htaccess works on Apache (and LiteSpeed). index.html is the second
    layer: with directory listings on and .htaccess ignored, a request for
    the folder shows an empty page instead of the file list. Nothing here
    can protect the files themselves on a server that ignores .htaccess —
    that is what the "move $backupDir outside the web root" advice is for.
*/
function backup_protect_dir($dir) {
    $ht = "# Backups must never be served over the web.\n"
        . "# Written by plugins/editor/editor-backup.php on every run.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n"
        . "Options -Indexes\n";
    if (!file_exists($dir . '.htaccess') || file_get_contents($dir . '.htaccess') !== $ht) {
        @file_put_contents($dir . '.htaccess', $ht);
    }
    if (!file_exists($dir . 'index.html')) {
        @file_put_contents($dir . 'index.html', '');
    }
}

/*
    True when the backup directory sits inside the web root, which is the
    default and the case the protection files exist for.
*/
function backup_dir_is_inside_webroot() {
    $dir = backup_dir();
    return $dir !== false && strpos($dir, SWF_ROOT) === 0;
}

// Web-root-relative name of the backup directory, for messages and robots.txt
function backup_dir_label() {
    $dir = backup_dir();
    if ($dir === false) { return backup_setting('backupDir', 'backups'); }
    return strpos($dir, SWF_ROOT) === 0 ? substr($dir, strlen(SWF_ROOT)) : $dir;
}

function backup_stamp($time = null) {
    return date('Ymd-His', $time ?? time());
}

function backup_stamp_to_time($stamp) {
    $t = DateTime::createFromFormat('Ymd-His', $stamp);
    return $t ? $t->getTimestamp() : 0;
}

/*
    A page name is validated by the caller (editor_validate_path); here it is
    only turned into a directory path under versions/ or trash/. Names may
    contain slashes (posts/my-post), which become nested folders.
*/
function backup_page_dir($kind, $pagename) {
    $pagename = trim(str_replace('\\', '/', $pagename), '/');
    if ($pagename === '' || strpos($pagename, '..') !== false) {
        return false;
    }
    return backup_dir($kind . '/' . $pagename);
}

// ── Page versions ─────────────────────────────────────────────────────────

/*
    Copy the current file of a page into versions/<page>/<stamp>.html.
    Called by the save handler BEFORE the new content is written, so the
    history is "what the page was before each save". Returns the stamp, or
    false if the page has no file yet (a brand new page has nothing to keep)
    or the backup directory is unavailable.
*/
function backup_version_page($pagename) {
    $file = SWF_ROOT . 'pages/' . $pagename . '.html';
    if (!file_exists($file)) {
        return false;
    }
    $dir = backup_page_dir('versions', $pagename);
    if ($dir === false) {
        return false;
    }
    $stamp = backup_stamp();
    $dest  = $dir . $stamp . '.html';
    // Two saves inside one second: keep both by suffixing
    $n = 1;
    while (file_exists($dest)) {
        $dest = $dir . $stamp . '-' . (++$n) . '.html';
    }
    if (!@copy($file, $dest)) {
        return false;
    }
    @touch($dest, filemtime($file));
    backup_prune_versions($pagename);
    return basename($dest, '.html');
}

function backup_list_versions($pagename) {
    $dir = backup_page_dir('versions', $pagename);
    if ($dir === false) { return array(); }
    $out = array();
    foreach (glob($dir . '*.html') as $f) {
        $stamp = basename($f, '.html');
        $out[] = array(
            'stamp' => $stamp,
            'time'  => backup_stamp_to_time(substr($stamp, 0, 15)),
            'size'  => filesize($f),
            'file'  => $f,
        );
    }
    usort($out, function ($a, $b) { return strcmp($b['stamp'], $a['stamp']); });
    return $out;
}

function backup_prune_versions($pagename) {
    $keep = backup_keep_versions();
    $list = backup_list_versions($pagename);
    foreach (array_slice($list, $keep) as $v) {
        @unlink($v['file']);
    }
}

// Validate a stamp supplied by the browser before using it in a path
function backup_valid_stamp($stamp) {
    return (bool) preg_match('/^\d{8}-\d{6}(-\d+)?$/', (string) $stamp);
}

function backup_version_file($pagename, $stamp) {
    if (!backup_valid_stamp($stamp)) { return false; }
    $dir = backup_page_dir('versions', $pagename);
    if ($dir === false) { return false; }
    $f = $dir . $stamp . '.html';
    return file_exists($f) ? $f : false;
}

/*
    Put an old version back as the live page. The current content is
    versioned first, so a restore can itself be undone.
*/
function backup_restore_version($pagename, $stamp) {
    $src = backup_version_file($pagename, $stamp);
    if ($src === false) { return false; }
    backup_version_page($pagename);
    $file = SWF_ROOT . 'pages/' . $pagename . '.html';
    return @copy($src, $file);
}

// ── Trash ─────────────────────────────────────────────────────────────────

/*
    Deleting a page moves its file to trash/<page>/<stamp>.html and takes its
    versions along (into trash/<page>/versions/), so restoring from the trash
    brings the history back too. Returns the stamp or false.
*/
function backup_trash_page($pagename) {
    $file = SWF_ROOT . 'pages/' . $pagename . '.html';
    if (!file_exists($file)) { return false; }
    $dir = backup_page_dir('trash', $pagename);
    if ($dir === false) { return false; }
    $stamp = backup_stamp();
    $dest  = $dir . $stamp . '.html';
    $n = 1;
    while (file_exists($dest)) { $dest = $dir . $stamp . '-' . (++$n) . '.html'; }
    if (!@rename($file, $dest)) {
        if (!@copy($file, $dest) || !@unlink($file)) { return false; }
    }
    // Carry the version history along
    $vdir = backup_page_dir('versions', $pagename);
    if ($vdir !== false && glob($vdir . '*.html')) {
        $tv = $dir . 'versions/';
        if (is_dir($tv) || @mkdir($tv, 0755, true)) {
            foreach (glob($vdir . '*.html') as $v) { @rename($v, $tv . basename($v)); }
        }
    }
    // Remove now-empty parent folders in pages/ (e.g. pages/posts/ stays if other posts exist)
    $parent = dirname($file);
    while ($parent !== rtrim(SWF_ROOT . 'pages', '/') && strpos($parent, SWF_ROOT . 'pages/') === 0) {
        if (!@rmdir($parent)) { break; }
        $parent = dirname($parent);
    }
    return basename($dest, '.html');
}

function backup_list_trash() {
    $root = backup_dir('trash');
    if ($root === false) { return array(); }
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root)));
        // Skip carried-along version files: <page>/versions/<stamp>.html
        if (preg_match('#(^|/)versions/[^/]+$#', $rel)) { continue; }
        $stamp = basename($rel, '.html');
        $page  = substr($rel, 0, -strlen($stamp) - 6); // strip "/<stamp>.html"
        $raw   = file_get_contents($f->getPathname());
        $title = preg_match('/<!--\s*pagetitle\s*:\s*(.*?)\s*-->/', $raw, $m) ? trim($m[1]) : $page;
        $out[] = array(
            'page'  => $page,
            'stamp' => $stamp,
            'time'  => backup_stamp_to_time(substr($stamp, 0, 15)),
            'title' => $title,
            'size'  => $f->getSize(),
            'file'  => $f->getPathname(),
        );
    }
    usort($out, function ($a, $b) { return strcmp($b['stamp'], $a['stamp']); });
    return $out;
}

/*
    Bring a deleted page back. Refuses if a page with that name exists again,
    so nothing live is overwritten by accident.
*/
function backup_untrash_page($pagename, $stamp) {
    if (!backup_valid_stamp($stamp)) { return 'Invalid request.'; }
    $dir = backup_page_dir('trash', $pagename);
    if ($dir === false) { return 'Backup directory unavailable.'; }
    $src = $dir . $stamp . '.html';
    if (!file_exists($src)) { return 'That deleted page was not found.'; }
    $file = SWF_ROOT . 'pages/' . $pagename . '.html';
    if (file_exists($file)) { return 'A page called ' . $pagename . ' already exists. Rename or delete it first.'; }
    $parent = dirname($file);
    if (!is_dir($parent) && !@mkdir($parent, 0755, true)) { return 'Could not create the page folder.'; }
    if (!@rename($src, $file) && !(@copy($src, $file) && @unlink($src))) { return 'Could not move the file back.'; }
    // Bring the history back too
    $tv = $dir . 'versions/';
    if (is_dir($tv)) {
        $vdir = backup_page_dir('versions', $pagename);
        if ($vdir !== false) {
            foreach (glob($tv . '*.html') as $v) { @rename($v, $vdir . basename($v)); }
            @rmdir($tv);
        }
    }
    return true;
}

function backup_purge_trash($pagename, $stamp) {
    if (!backup_valid_stamp($stamp)) { return false; }
    $dir = backup_page_dir('trash', $pagename);
    if ($dir === false) { return false; }
    $src = $dir . $stamp . '.html';
    if (!file_exists($src)) { return false; }
    @unlink($src);
    // If nothing else is left for this page, drop its folder and its carried versions
    if (!glob($dir . '*.html')) {
        foreach (glob($dir . 'versions/*.html') as $v) { @unlink($v); }
        @rmdir($dir . 'versions');
        @rmdir($dir);
    }
    return true;
}

// ── Line diff ─────────────────────────────────────────────────────────────

/*
    A small longest-common-subsequence line diff, enough to show what a save
    changed. Returns a list of [type, line] where type is ' ', '-' or '+'.
    Inputs beyond 4000 lines are not diffed (the editor shows the two
    versions side by side instead) so a huge page cannot stall the server.
*/
function backup_diff_lines($old, $new) {
    $a = explode("\n", str_replace("\r\n", "\n", $old));
    $b = explode("\n", str_replace("\r\n", "\n", $new));
    $n = count($a); $m = count($b);
    if ($n > 4000 || $m > 4000) { return null; }

    // Trim common prefix and suffix first; most edits touch a small region
    $start = 0;
    while ($start < $n && $start < $m && $a[$start] === $b[$start]) { $start++; }
    $endA = $n; $endB = $m;
    while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $endA--; $endB--; }

    $midA = array_slice($a, $start, $endA - $start);
    $midB = array_slice($b, $start, $endB - $start);
    $la = count($midA); $lb = count($midB);

    // LCS table on the middle only
    $L = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
    for ($i = $la - 1; $i >= 0; $i--) {
        for ($j = $lb - 1; $j >= 0; $j--) {
            $L[$i][$j] = ($midA[$i] === $midB[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }
    $out = array();
    for ($k = 0; $k < $start; $k++) { $out[] = array(' ', $a[$k]); }
    $i = 0; $j = 0;
    while ($i < $la && $j < $lb) {
        if ($midA[$i] === $midB[$j]) { $out[] = array(' ', $midA[$i]); $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = array('-', $midA[$i]); $i++; }
        else { $out[] = array('+', $midB[$j]); $j++; }
    }
    while ($i < $la) { $out[] = array('-', $midA[$i++]); }
    while ($j < $lb) { $out[] = array('+', $midB[$j++]); }
    for ($k = $endA; $k < $n; $k++) { $out[] = array(' ', $a[$k]); }
    return $out;
}

/*
    Render a diff as HTML, showing changed lines with a little context and
    folding long unchanged runs. Returns '' when the two are identical.
*/
function backup_diff_html($old, $new, $context = 3) {
    $diff = backup_diff_lines($old, $new);
    if ($diff === null) {
        return '<p class="diff-note">This page is too long to diff here. Open the version to read it.</p>';
    }
    $changed = array();
    foreach ($diff as $i => $d) { if ($d[0] !== ' ') { $changed[] = $i; } }
    if (!$changed) { return ''; }
    $show = array();
    foreach ($changed as $i) {
        for ($k = max(0, $i - $context); $k <= min(count($diff) - 1, $i + $context); $k++) { $show[$k] = true; }
    }
    $html = '<pre class="diff">';
    $last = -1;
    foreach ($diff as $i => $d) {
        if (!isset($show[$i])) { continue; }
        if ($last !== -1 && $i !== $last + 1) { $html .= '<span class="diff-skip">&middot;&middot;&middot; ' . ($i - $last - 1) . ' unchanged lines &middot;&middot;&middot;</span>'; }
        $cls = $d[0] === '+' ? 'diff-add' : ($d[0] === '-' ? 'diff-del' : 'diff-ctx');
        // Each line is a block-level span; no newline between them or <pre> would double-space
        $html .= '<span class="' . $cls . '">' . $d[0] . ' ' . htmlspecialchars($d[1], ENT_QUOTES, 'UTF-8') . '</span>';
        $last = $i;
    }
    return $html . '</pre>';
}

// ── Site archives ─────────────────────────────────────────────────────────

/*
    What the framework ships, as root-relative paths. This is the same list
    the release workflow packages into the upgrade zip, which is the point:
    an upgrade replaces exactly these, and a content backup contains exactly
    everything else. Keep the two in step.
*/
function backup_framework_paths() {
    return array(
        'index.php', 'rss_generator.php', 'LICENSE',
        'required', 'includes', 'css', 'plugins',
        'themes/starter', 'themes/skeleton',
        'config/config-example.php', 'config/.htaccess',
    );
}

/*
    Never archived: the backup directory itself, cache files, version control,
    development leftovers, and the editor's runtime state.
*/
function backup_excluded($rel) {
    static $backupRel = null;
    if ($backupRel === null) {
        $backupRel = backup_dir_is_inside_webroot() ? rtrim(backup_dir_label(), '/') : "\0none";
    }
    if ($rel === $backupRel || strpos($rel, $backupRel . '/') === 0) { return true; }
    if (preg_match('#^cached-.*\.html$#', $rel)) { return true; }
    if (preg_match('#^(\.git|\.github|extras|build|dist|node_modules)(/|$)#', $rel)) { return true; }
    if (preg_match('#^plugins/editor/(lockouts|totp-used)\.json$#', $rel)) { return true; }
    if (preg_match('#(^|/)\.DS_Store$#', $rel) || $rel === '.gitignore') { return true; }
    return false;
}

function backup_is_framework_path($rel) {
    foreach (backup_framework_paths() as $p) {
        if ($rel === $p || strpos($rel, $p . '/') === 0) { return true; }
    }
    return false;
}

/*
    List every file to archive for a kind ('content' or 'full'), as
    root-relative paths. Content = everything the framework does not own.
*/
function backup_collect_files($kind) {
    $files = array();
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(SWF_ROOT, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) {
                $rel = str_replace('\\', '/', substr($current->getPathname(), strlen(SWF_ROOT)));
                return !backup_excluded($rel);
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(SWF_ROOT)));
        if ($kind === 'content' && backup_is_framework_path($rel)) { continue; }
        $files[] = $rel;
    }
    sort($files);
    return $files;
}

/*
    The site's name for file names: its domain from $WebsiteURL, lowercased,
    without scheme, www. or path, keeping only letters, digits, dots and
    hyphens. "https://www.Example.com/" becomes "example.com". With many
    sites' backups on one machine this is what tells them apart.
*/
function backup_site_slug() {
    $url = strtolower(trim((string) ($GLOBALS['WebsiteURL'] ?? '')));
    $url = preg_replace('#^[a-z]+://#', '', $url);
    $url = preg_replace('#^www\.#', '', $url);
    $url = preg_replace('#[/:?\#].*$#', '', $url);      // drop path, port, query
    $url = preg_replace('/[^a-z0-9.\-]+/', '-', $url);
    $url = trim($url, '.-');
    return $url !== '' ? $url : 'site';
}

function backup_archive_dir() {
    return backup_dir('archives');
}

/*
    Build a zip. $kind is 'content' or 'full'; $trigger is recorded in the
    file name so you can tell a manual download from a weekly automatic one
    or a cron run: swf-content-20261020-231500-auto.zip. Returns
    [file => path, name => basename, size => bytes, count => files] or a
    string error message.
*/
function backup_make_archive($kind, $trigger = 'manual') {
    if (!in_array($kind, array('content', 'full'), true)) { return 'Unknown backup kind.'; }
    if (!class_exists('ZipArchive')) {
        return 'PHP\'s zip extension is not installed on this server, so archives cannot be built. Page versions still work.';
    }
    $dir = backup_archive_dir();
    if ($dir === false) {
        return 'The backup directory (' . backup_dir_label() . ') is not writable.';
    }
    $trigger = preg_replace('/[^a-z]/', '', strtolower($trigger)) ?: 'manual';
    $name = 'swf-' . backup_site_slug() . '-' . $kind . '-' . backup_stamp() . '-' . $trigger . '.zip';
    $path = $dir . $name;
    $tmp  = $path . '.part';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return 'Could not create the archive file.';
    }
    $files = backup_collect_files($kind);
    $count = 0;
    foreach ($files as $rel) {
        if ($zip->addFile(SWF_ROOT . $rel, $rel)) { $count++; }
    }
    $zip->addFromString('BACKUP.txt',
        "Simple Website Framework " . $kind . " backup of " . backup_site_slug() . "\n"
      . "Made " . date('Y-m-d H:i:s') . " (" . $trigger . ")\n"
      . "Files: " . $count . "\n\n"
      . ($kind === 'content'
          ? "This is everything that is yours: pages, images, your theme(s), config.php,\nrobots.txt and .htaccess. It contains no framework code. To restore, unzip it\nover a working framework install (new or existing) and replace files when asked.\n"
          : "This is the complete site, framework included. To restore, unzip it into an\nempty web root, or over an existing install replacing files when asked.\n")
      . "\nconfig/config.php holds your editor password hash and, if set, the two-factor\nsecret. Keep this file as private as the site itself.\n");
    $zip->close();
    if (!@rename($tmp, $path)) { @unlink($tmp); return 'Could not finish writing the archive.'; }
    backup_prune_archives();
    return array('file' => $path, 'name' => $name, 'size' => filesize($path), 'count' => $count);
}

function backup_list_archives() {
    $dir = backup_archive_dir();
    if ($dir === false) { return array(); }
    $out = array();
    foreach (glob($dir . 'swf-*.zip') as $f) {
        $name = basename($f);
        // The site part is optional so archives made before it was added still list and rotate
        if (!preg_match('/^swf-(?:([a-z0-9.\-]+)-)?(content|full)-(\d{8}-\d{6})-([a-z]+)\.zip$/', $name, $m)) { continue; }
        $out[] = array('name' => $name, 'site' => $m[1], 'kind' => $m[2], 'stamp' => $m[3], 'time' => backup_stamp_to_time($m[3]), 'trigger' => $m[4], 'size' => filesize($f), 'file' => $f);
    }
    usort($out, function ($a, $b) { return strcmp($b['stamp'], $a['stamp']); });
    return $out;
}

/*
    Rotation. Automatic archives (trigger "auto" or "cron") are kept to
    $backupKeepArchives per kind. Manual ones are left alone: if you clicked
    the button, you decide when it goes.
*/
function backup_prune_archives() {
    $keep = backup_keep_archives();
    $byKind = array();
    foreach (backup_list_archives() as $a) {
        if ($a['trigger'] === 'manual') { continue; }
        $byKind[$a['kind']][] = $a;
    }
    foreach ($byKind as $list) {
        foreach (array_slice($list, $keep) as $a) { @unlink($a['file']); }
    }
}

function backup_archive_file($name) {
    if (!preg_match('/^swf-(?:[a-z0-9.\-]+-)?(content|full)-\d{8}-\d{6}-[a-z]+\.zip$/', (string) $name)) { return false; }
    $dir = backup_archive_dir();
    if ($dir === false) { return false; }
    $f = $dir . $name;
    return file_exists($f) ? $f : false;
}

function backup_delete_archive($name) {
    $f = backup_archive_file($name);
    return $f !== false && @unlink($f);
}

function backup_latest_archive_time($kind = null) {
    $latest = 0;
    foreach (backup_list_archives() as $a) {
        if ($kind !== null && $a['kind'] !== $kind) { continue; }
        $latest = max($latest, $a['time']);
    }
    return $latest;
}

/*
    The opportunistic scheduler. Called after every editor save: if automatic
    backups are on ($backupAutoDays > 0) and no content archive — of any
    trigger — is newer than that many days, make one. Returns the archive
    info when it ran, null when it did not need to, or an error string.
*/
function backup_maybe_auto() {
    $days = backup_auto_days();
    if ($days <= 0) { return null; }
    $latest = backup_latest_archive_time('content');
    if ($latest > 0 && time() - $latest < $days * 86400) { return null; }
    return backup_make_archive('content', 'auto');
}

// Human readable size
function backup_fmt_size($bytes) {
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)    { return round($bytes / 1024) . ' KB'; }
    return $bytes . ' B';
}

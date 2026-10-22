<?php
/*
    editor-save.php
    AJAX endpoint for the page editor plugin.
    Handles saving edits to existing pages, creating new pages, staging a
    preview draft, and logging out.
    Called via POST from the editor's JavaScript (plugins/editor/index.php).
    Never access this file directly from a browser.

    This file is served by Apache directly — it never passes through index.php
    or the cache layer in required/top-cache.php, which is what makes a POST
    possible at all on a cached site.
*/

define('SWF_EDITOR', true);

error_reporting(0);
ini_set('display_errors', 0);

define('SWF_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/../../')) . '/');

require_once SWF_ROOT . 'config/config.php';
require_once __DIR__ . '/editor-auth.php';
require_once __DIR__ . '/editor-backup.php';
require_once __DIR__ . '/editor-files.php';

define('SWF_EDITOR_PREVIEW_KEY', 'swf_editor_preview');

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Refuse to act when the editor is switched off
if (!isset($editorEnabled) || $editorEnabled !== true) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Editor is not enabled.']);
    exit;
}

// Must be authenticated
if (!editor_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

// Verify CSRF token on all state-changing actions
$action    = $_POST['action'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? '';

if ($action !== 'logout' && !editor_verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please reload the editor.']);
    exit;
}

/*
    The metadata tags the editor knows about. This list must match the tags
    read by required/vitalfunctions.php — anything missing here would be
    silently dropped from the file on every save.

    pagecategory is the comma separated list of categories a post belongs to.
    postcategory is the filter on an archive page. Both are written only when
    non-empty so that pages which never used categories stay byte-for-byte
    identical in shape.
*/
function build_meta_block($fields) {
    $always   = ['pagetitle', 'pagelayout', 'pagedate', 'pageimage', 'pageexcerpt', 'pagekeywords', 'pageauthor', 'pagetype'];
    $optional = ['pagecategory', 'postcategory'];
    $meta = '';
    foreach ($always as $key) {
        $value = isset($fields[$key]) ? trim($fields[$key]) : '';
        $value = str_replace(['-->', "\r", "\n"], ['--&gt;', '', ' '], $value);
        $meta .= '<!-- ' . $key . ': ' . $value . ' -->' . "\n";
    }
    foreach ($optional as $key) {
        $value = isset($fields[$key]) ? trim($fields[$key]) : '';
        if ($value === '') { continue; }
        $value = str_replace(['-->', "\r", "\n"], ['--&gt;', '', ' '], $value);
        $meta .= '<!-- ' . $key . ': ' . $value . ' -->' . "\n";
    }
    return $meta;
}

// Assemble the full file text from POST fields
function build_page_file($fields, $content) {
    return rtrim(build_meta_block($fields)) . "\n\n" . trim($content) . "\n";
}

/*
    Settle the address of a new page. If the address is missing, or is only a
    folder prefix ("posts/" with nothing after it — the new-post form prefills
    that), the page's own name is derived from its title: "Shields That React"
    becomes "shields-that-react", under the prefix if there was one. A page
    with neither an address nor a title is refused rather than guessed at.
    Returns the address, or a string starting with "!" carrying the error.
*/
function editor_settle_new_address($address, $title) {
    $address = trim(str_replace('\\', '/', (string) $address));
    $slug = strtolower(trim((string) $title));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if (strlen($slug) > 80) { $slug = rtrim(substr($slug, 0, 80), '-'); }

    $bareFolder = $address === '' || substr($address, -1) === '/';
    if ($bareFolder) {
        if ($slug === '') {
            return '!Give the page a title or an address first; the address is made from the title when it is left blank.';
        }
        $address = rtrim($address, '/');
        $address = $address === '' ? $slug : $address . '/' . $slug;
    }
    return $address;
}

/*
    Cache invalidation.

    required/top-cache.php names cache files after the LAST segment of the URL
    only: /posts/my-post is cached as cached-my-post.html, and a paginated
    archive as cached-archives?page=2.html. So we clear by basename, including
    every paginated variant.

    Editing a post also changes what every archive and category page should
    list, and there is no cheap way to know which pages those are. A flat-file
    site regenerates a cache file on the next request at essentially no cost,
    so when a post is involved we clear the whole cache rather than guess.
*/
function editor_clear_cache($pagename) {
    $base = basename($pagename);
    foreach (glob(SWF_ROOT . 'cached-' . $base . '*.html') as $f) {
        // glob's * would also match cached-my-post-two.html; keep only the
        // exact page and its ?page=N variants.
        $tail = substr(basename($f), strlen('cached-' . $base));
        if ($tail === '.html' || preg_match('/^\?page=\d+\.html$/', $tail)) {
            @unlink($f);
        }
    }
    if (strpos($pagename, 'posts/') === 0) {
        editor_clear_all_cache();
    }
}

function editor_clear_all_cache() {
    foreach (glob(SWF_ROOT . 'cached-*.html') as $f) {
        @unlink($f);
    }
}

// ── SAVE EXISTING PAGE ──────────────────────────────────────────────────────

if ($action === 'save') {
    $pagename = trim($_POST['pagename'] ?? '');
    $content  = $_POST['content'] ?? '';

    if (empty($pagename)) {
        echo json_encode(['success' => false, 'message' => 'No page name provided.']);
        exit;
    }

    $filePath = editor_validate_path($pagename, SWF_ROOT);
    if ($filePath === false || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or non-existent page path.']);
        exit;
    }

    $newFile = build_page_file($_POST, $content);

    // Keep the previous version, unless nothing actually changed
    $versioned = false;
    if (file_get_contents($filePath) !== $newFile) {
        $versioned = backup_version_page($pagename);
        if ($versioned === false && backup_dir() === false) {
            echo json_encode(['success' => false, 'message' => 'Not saved: the backup directory (' . backup_dir_label() . ') cannot be written, so the previous version could not be kept. Fix its permissions or set $backupDir in config.php.']);
            exit;
        }
    }

    if (file_put_contents($filePath, $newFile) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write file. Check permissions.']);
        exit;
    }

    editor_clear_cache($pagename);
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);

    $auto = backup_maybe_auto();
    $msg  = 'Saved.';
    if (is_array($auto)) { $msg .= ' Weekly backup made (' . backup_fmt_size($auto['size']) . ').'; }
    elseif (is_string($auto)) { $msg .= ' Weekly backup failed: ' . $auto; }

    echo json_encode(['success' => true, 'message' => $msg, 'versioned' => (bool) $versioned]);
    exit;
}

// ── CREATE NEW PAGE ─────────────────────────────────────────────────────────

if ($action === 'new') {
    $pagename = trim($_POST['pagename'] ?? '');
    $content  = $_POST['content'] ?? '';

    // No address, or only "posts/": make one from the title rather than
    // creating a page literally called /posts (which is what once happened).
    $pagename = editor_settle_new_address($pagename, $_POST['pagetitle'] ?? '');
    if ($pagename[0] === '!') {
        echo json_encode(['success' => false, 'message' => substr($pagename, 1)]);
        exit;
    }

    $pagename = editor_validate_new_pagename($pagename, SWF_ROOT);
    if ($pagename === false) {
        echo json_encode(['success' => false, 'message' => 'Page name may only contain lowercase letters, numbers and hyphens, separated by forward slashes, and must not clash with an existing site folder.']);
        exit;
    }

    $filePath  = SWF_ROOT . 'pages/' . $pagename . '.html';
    $parentDir = dirname($filePath);

    if (file_exists($filePath)) {
        echo json_encode([
            'success'  => false,
            'message'  => 'A page already exists at /' . $pagename . ' (pages/' . $pagename . '.html).',
            'existing' => $pagename,
        ]);
        exit;
    }

    if (!is_dir($parentDir)) {
        if (!mkdir($parentDir, 0755, true)) {
            echo json_encode(['success' => false, 'message' => 'Failed to create directory. Check permissions.']);
            exit;
        }
    }

    if (empty($_POST['pageauthor']))  { $_POST['pageauthor']  = $WebsiteAuthor; }
    if (empty($_POST['pagedate']))    { $_POST['pagedate']    = date('n/j/Y'); }
    if (empty($_POST['pagelayout']))  { $_POST['pagelayout']  = 'page-html'; }
    if (empty($_POST['pagetype']))    { $_POST['pagetype']    = 'website'; }

    if (file_put_contents($filePath, build_page_file($_POST, $content)) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write file. Check permissions.']);
        exit;
    }

    editor_clear_cache($pagename);
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);
    backup_maybe_auto();

    echo json_encode([
        'success'  => true,
        'message'  => 'Page created.',
        'pagename' => $pagename
    ]);
    exit;
}

// ── RESTORE A PREVIOUS VERSION ──────────────────────────────────────────────

if ($action === 'restore') {
    $pagename = trim($_POST['pagename'] ?? '');
    $stamp    = trim($_POST['stamp'] ?? '');
    $filePath = editor_validate_path($pagename, SWF_ROOT);
    if ($filePath === false || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or non-existent page path.']);
        exit;
    }
    $pagename = strtolower(ltrim($pagename, '/.'));
    if (!backup_restore_version($pagename, $stamp)) {
        echo json_encode(['success' => false, 'message' => 'That version could not be restored.']);
        exit;
    }
    editor_clear_cache($pagename);
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);
    echo json_encode(['success' => true, 'message' => 'Restored. The version you replaced was kept in the history.']);
    exit;
}

// ── DELETE A PAGE (to the trash) ────────────────────────────────────────────

/*
    The theme cannot render without these three, so they cannot be deleted.
*/
$editorProtectedPages = array('home', '404', 'navigation');

if ($action === 'delete') {
    $pagename = trim($_POST['pagename'] ?? '');
    $filePath = editor_validate_path($pagename, SWF_ROOT);
    if ($filePath === false || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or non-existent page path.']);
        exit;
    }
    $pagename = strtolower(ltrim($pagename, '/.'));
    if (in_array($pagename, $editorProtectedPages, true) || strpos($pagename, 'footer/') === 0) {
        echo json_encode(['success' => false, 'message' => 'The site needs this page; it cannot be deleted. Edit it instead.']);
        exit;
    }
    if (backup_dir() === false) {
        echo json_encode(['success' => false, 'message' => 'Not deleted: the backup directory (' . backup_dir_label() . ') cannot be written, so the page could not be moved to the trash.']);
        exit;
    }
    $stamp = backup_trash_page($pagename);
    if ($stamp === false) {
        echo json_encode(['success' => false, 'message' => 'The page could not be moved to the trash.']);
        exit;
    }
    editor_clear_cache($pagename);
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);
    backup_maybe_auto();
    echo json_encode(['success' => true, 'message' => 'Moved to the trash.', 'stamp' => $stamp]);
    exit;
}

// ── TRASH: RESTORE OR PURGE ────────────────────────────────────────────────

if ($action === 'untrash' || $action === 'purge') {
    $pagename = strtolower(trim($_POST['pagename'] ?? ''));
    $stamp    = trim($_POST['stamp'] ?? '');
    // Same character rules as a page path; the file need not exist (it is in the trash)
    $pagename = ltrim($pagename, '/.');
    if (!preg_match('/^[a-z0-9\-_\/]+$/', $pagename) || strpos($pagename, '..') !== false) {
        echo json_encode(['success' => false, 'message' => 'Invalid page name.']);
        exit;
    }
    if ($action === 'purge') {
        echo json_encode(backup_purge_trash($pagename, $stamp)
            ? ['success' => true, 'message' => 'Deleted permanently.']
            : ['success' => false, 'message' => 'Nothing to delete.']);
        exit;
    }
    $r = backup_untrash_page($pagename, $stamp);
    if ($r !== true) {
        echo json_encode(['success' => false, 'message' => $r]);
        exit;
    }
    editor_clear_all_cache();
    echo json_encode(['success' => true, 'message' => 'Page restored.', 'pagename' => $pagename]);
    exit;
}

// ── SITE BACKUP ARCHIVES ───────────────────────────────────────────────────

if ($action === 'backup') {
    $kind = $_POST['kind'] ?? 'content';
    @set_time_limit(300);
    $r = backup_make_archive($kind, 'manual');
    if (!is_array($r)) {
        echo json_encode(['success' => false, 'message' => $r]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Backup made.', 'name' => $r['name'], 'size' => backup_fmt_size($r['size']), 'count' => $r['count']]);
    exit;
}

// ── FILES ───────────────────────────────────────────────────────────────────

if ($action === 'upload') {
    $dir = files_validate_dir($_POST['dir'] ?? '');
    if ($dir === false) {
        echo json_encode(['success' => false, 'message' => 'That folder is not an upload location. Allowed: ' . implode(', ', files_upload_dirs()) . ' and their subfolders.']);
        exit;
    }
    $replace = !empty($_POST['replace']);
    $entries = files_normalize_uploads($_FILES['files'] ?? array());
    if (!$entries) {
        // A POST that exceeded post_max_size arrives with $_FILES empty and $_POST empty too
        $msg = empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            ? 'The upload was bigger than the server accepts in one go (' . backup_fmt_size(files_php_limit()) . '). Try fewer or smaller files.'
            : 'No files received.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $results = array();
    $ok = 0;
    foreach ($entries as $e) {
        $r = files_store_upload($e, $dir, $replace);
        if ($r['ok']) { $ok++; }
        $results[] = $r;
    }
    editor_clear_all_cache();
    echo json_encode(['success' => $ok > 0, 'message' => $ok . ' of ' . count($entries) . ' uploaded.', 'results' => $results, 'dir' => $dir]);
    exit;
}

// Listing for the file picker dialog in the page editor
if ($action === 'listfiles') {
    $dir = files_validate_dir($_POST['dir'] ?? '');
    if ($dir === false) {
        $roots = files_upload_dirs();
        $dir = $roots ? $roots[0] : false;
    }
    if ($dir === false) {
        echo json_encode(['success' => false, 'message' => 'No upload folders are configured.']);
        exit;
    }
    $l = files_list($dir);
    $parent = strpos($dir, '/') !== false ? dirname($dir) : '';
    echo json_encode([
        'success' => true,
        'dir'     => $dir,
        'parent'  => files_validate_dir($parent) !== false ? $parent : '',
        'roots'   => files_upload_dirs(),
        'folders' => $l['folders'],
        'files'   => array_map(function ($f) { return ['name' => $f['name'], 'path' => $f['path'], 'size' => $f['size'], 'mtime' => $f['mtime'], 'image' => $f['image'], 'ext' => $f['ext']]; }, $l['files']),
    ]);
    exit;
}

if ($action === 'mkdir') {
    $r = files_make_dir($_POST['dir'] ?? '', $_POST['name'] ?? '');
    echo json_encode(strpos($r, '/') !== false && file_exists(SWF_ROOT . $r)
        ? ['success' => true, 'message' => 'Folder created.', 'dir' => $r]
        : ['success' => false, 'message' => $r]);
    exit;
}

if ($action === 'deletefile') {
    $path = files_validate_file($_POST['path'] ?? '');
    if ($path === false) { echo json_encode(['success' => false, 'message' => 'That file was not found.']); exit; }
    if (backup_dir() === false) { echo json_encode(['success' => false, 'message' => 'Not deleted: the backup directory cannot be written, so the file could not be moved to the trash.']); exit; }
    echo json_encode(files_trash_file($path)
        ? ['success' => true, 'message' => 'Moved to the trash.']
        : ['success' => false, 'message' => 'The file could not be moved to the trash.']);
    exit;
}

if ($action === 'untrashfile' || $action === 'purgefile') {
    $path = $_POST['path'] ?? ''; $stamp = $_POST['stamp'] ?? '';
    if ($action === 'purgefile') {
        echo json_encode(files_purge($path, $stamp) ? ['success' => true, 'message' => 'Deleted permanently.'] : ['success' => false, 'message' => 'Nothing to delete.']);
        exit;
    }
    $r = files_untrash($path, $stamp);
    echo json_encode($r === true ? ['success' => true, 'message' => 'File restored.'] : ['success' => false, 'message' => $r]);
    exit;
}

if ($action === 'deletebackup') {
    echo json_encode(backup_delete_archive($_POST['name'] ?? '')
        ? ['success' => true, 'message' => 'Backup deleted.']
        : ['success' => false, 'message' => 'That backup was not found.']);
    exit;
}

// ── STAGE A PREVIEW DRAFT ───────────────────────────────────────────────────

/*
    Nothing is written to disk. The assembled file text is parked in the
    session under the page name; the editor then loads /<pagename>?preview in
    an iframe and plugins/editor/editor-preview.php (included from index.php)
    hands the draft to the theme. Only this session can see it.
*/
if ($action === 'preview') {
    $pagename = trim($_POST['pagename'] ?? '');
    $content  = $_POST['content'] ?? '';
    $isNew    = !empty($_POST['is_new']);

    if ($isNew) {
        $pagename = editor_settle_new_address($pagename, $_POST['pagetitle'] ?? '');
        if ($pagename[0] === '!') {
            echo json_encode(['success' => false, 'message' => substr($pagename, 1)]);
            exit;
        }
        $pagename = editor_validate_new_pagename($pagename, SWF_ROOT);
        if ($pagename === false) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid page name before previewing.']);
            exit;
        }
    } else {
        $filePath = editor_validate_path($pagename, SWF_ROOT);
        if ($filePath === false || !file_exists($filePath)) {
            echo json_encode(['success' => false, 'message' => 'Invalid or non-existent page path.']);
            exit;
        }
        $pagename = strtolower(ltrim($pagename, '/.'));
    }

    $_SESSION[SWF_EDITOR_PREVIEW_KEY] = [
        'pagename' => $pagename,
        'content'  => build_page_file($_POST, $content),
        'time'     => time(),
    ];

    echo json_encode([
        'success' => true,
        'url'     => '/' . $pagename . '?preview=' . time()
    ]);
    exit;
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────

if ($action === 'logout') {
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);
    editor_logout();
    echo json_encode(['success' => true, 'message' => 'Logged out.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);

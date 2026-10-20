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

    if (file_put_contents($filePath, build_page_file($_POST, $content)) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write file. Check permissions.']);
        exit;
    }

    editor_clear_cache($pagename);
    unset($_SESSION[SWF_EDITOR_PREVIEW_KEY]);

    echo json_encode(['success' => true, 'message' => 'Saved.']);
    exit;
}

// ── CREATE NEW PAGE ─────────────────────────────────────────────────────────

if ($action === 'new') {
    $pagename = trim($_POST['pagename'] ?? '');
    $content  = $_POST['content'] ?? '';

    if (empty($pagename)) {
        echo json_encode(['success' => false, 'message' => 'No page name provided.']);
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
        echo json_encode(['success' => false, 'message' => 'A page with that name already exists.']);
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

    echo json_encode([
        'success'  => true,
        'message'  => 'Page created.',
        'pagename' => $pagename
    ]);
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

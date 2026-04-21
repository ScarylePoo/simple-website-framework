<?php
/*
    editor-save.php
    AJAX endpoint for the page editor plugin.
    Handles saving edits to existing pages and creating new pages.
    Called via POST from editor.php JavaScript.
    Never access this file directly from a browser.
*/

define('SWF_EDITOR', true);

error_reporting(0);
ini_set('display_errors', 0);

define('SWF_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/../../')) . '/');

require_once SWF_ROOT . 'config/config.php';
require_once __DIR__ . '/editor-auth.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Must be authenticated
if (!editor_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
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

// Build a metadata block from POST fields — shared by save and new
function build_meta_block($fields) {
    $keys = ['pagetitle', 'pagelayout', 'pagedate', 'pageimage', 'pageexcerpt', 'pagekeywords', 'pageauthor', 'pagetype'];
    $meta = '';
    foreach ($keys as $key) {
        $value = isset($fields[$key]) ? trim($fields[$key]) : '';
        $meta .= '<!-- ' . $key . ': ' . $value . ' -->' . "\n";
    }
    return $meta;
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

    $metaBlock  = build_meta_block($_POST);
    $newContent = rtrim($metaBlock) . "\n\n" . trim($content) . "\n";

    if (file_put_contents($filePath, $newContent) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write file. Check permissions.']);
        exit;
    }

    $cacheFile = SWF_ROOT . 'cached-' . $pagename . '.html';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }

    echo json_encode(['success' => true, 'message' => 'Page saved successfully.']);
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

    $pagename = ltrim($pagename, '/.');
    if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $pagename)) {
        echo json_encode(['success' => false, 'message' => 'Page name contains invalid characters.']);
        exit;
    }

    if (strpos($pagename, '..') !== false) {
        echo json_encode(['success' => false, 'message' => 'Invalid path.']);
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
    if (empty($_POST['pagelayout'])) { $_POST['pagelayout']  = 'page-html'; }
    if (empty($_POST['pagetype']))   { $_POST['pagetype']    = 'website'; }

    $metaBlock  = build_meta_block($_POST);
    $newContent = $metaBlock . "\n" . trim($content) . "\n";

    if (file_put_contents($filePath, $newContent) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write file. Check permissions.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Page created successfully.',
        'url'     => $pagename
    ]);
    exit;
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────

if ($action === 'logout') {
    editor_logout();
    echo json_encode(['success' => true, 'message' => 'Logged out.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);

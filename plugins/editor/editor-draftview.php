<?php
/*
    editor-draftview.php
    Included by index.php when the requested page has no live file but does
    have a draft (pages/<name>.html.draft) and the editor is enabled. Never
    access this file directly.

    A visitor gets the site's 404, exactly as if the page did not exist. A
    logged-in editor session sees the draft rendered through the theme at
    the page's real address, which is how you check an unpublished page in
    place. Nothing is cached either way (see $swfNoCache in top-cache.php).
*/

if (!isset($requestedPage)) {
    return;
}

if (!defined('SWF_EDITOR')) {
    define('SWF_EDITOR', true);
}
require_once __DIR__ . '/editor-auth.php';

if (!editor_is_authenticated()) {
    return;
}

$editorDraftFile = 'pages/' . strtolower($requestedPage) . '.html.draft';
if (!file_exists($editorDraftFile)) {
    return;
}

$pageContentOverride = file_get_contents($editorDraftFile);
$swfNoCache = true;

<?php
/*
    editor.php
    Included by plugins/plugins.php when $editorEnabled is true.

    The editor itself now lives at /edit/ (plugins/editor/index.php) and no
    longer renders inside the theme. This file exists for one reason: the old
    way in was to append ?editor (or ?editor=yourtoken) to any page, and people
    have that habit and those bookmarks. When we see it, send them to /edit/
    with the page they were looking at already open.

    The token, if one is configured, is passed along for /edit/ to check — a
    wrong or missing token lands on the site's 404 exactly as before.
*/

if (!isset($editorEnabled) || $editorEnabled !== true) {
    return;
}

if (!array_key_exists('editor', $_GET)) {
    return;
}

// The preview iframe loads pages with ?preview, never ?editor, so this cannot
// interfere with the editor's own rendering.

$editorTarget = '/edit/?' . http_build_query(array(
    'page'   => $pagename,
    'editor' => $_GET['editor'],
));

// header.php has already started writing <head> into the output buffer;
// discard it so the redirect header can go out.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Location: ' . $editorTarget);
    exit;
}

echo '<script>window.location.href = ' . json_encode($editorTarget) . ';</script>';
echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($editorTarget) . '"></noscript>';
exit;

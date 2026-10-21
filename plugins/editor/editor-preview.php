<?php
/*
    editor-preview.php
    Included by index.php when ?preview is present in the URL and the editor
    is enabled. Never access this file directly.

    The standalone editor (/edit/) lets you preview a draft before saving it.
    The draft is never written to pages/. Instead editor-save.php stores it in
    the editor's PHP session (action=preview), and this file hands it to the
    normal rendering chain by setting $pageContentOverride. Everything that
    reads page source goes through swf_page_source() in vitalfunctions.php,
    which returns the override when one is set, so the draft renders through
    the real theme exactly as the saved page would.

    Nothing happens unless all of the following hold:
      - the request carries a logged-in editor session
      - that session is holding a draft
      - the draft is for the page being requested

    A visitor adding ?preview to a URL by hand therefore sees the ordinary
    page. Cache is bypassed for ?preview requests (see required/top-cache.php),
    so a draft can never be served to anyone else or written to disk.
*/

if (!isset($requestedPage)) {
    return;
}

if (!defined('SWF_EDITOR')) {
    define('SWF_EDITOR', true);
}
require_once __DIR__ . '/editor-auth.php';

define('SWF_EDITOR_PREVIEW_KEY', 'swf_editor_preview');

if (!editor_is_authenticated()) {
    return;
}

if (empty($_SESSION[SWF_EDITOR_PREVIEW_KEY]) || !is_array($_SESSION[SWF_EDITOR_PREVIEW_KEY])) {
    return;
}

$editorPreviewDraft = $_SESSION[SWF_EDITOR_PREVIEW_KEY];

if (!isset($editorPreviewDraft['pagename'], $editorPreviewDraft['content'])) {
    return;
}

// index.php lowercases the page name before reading it, so compare the same way
if ($editorPreviewDraft['pagename'] !== strtolower($requestedPage)) {
    return;
}

$pageContentOverride = $editorPreviewDraft['content'];

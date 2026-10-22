<?php
/*
    editor-download.php
    Streams a backup archive, or a single page version, to a logged-in
    editor session. This is the only route out of the backup directory,
    which is otherwise denied to the web entirely.

        editor-download.php?archive=swf-example.com-content-20261020-231500-manual.zip
        editor-download.php?page=posts/my-post&version=20261020-231500

    GET is used so the browser treats it as a normal file download. Nothing
    here changes state, so no CSRF token is needed; the session check is the
    gate.
*/

define('SWF_EDITOR', true);

error_reporting(0);
ini_set('display_errors', 0);

define('SWF_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/../../')) . '/');

require_once SWF_ROOT . 'config/config.php';
require_once __DIR__ . '/editor-auth.php';
require_once __DIR__ . '/editor-backup.php';

if (!isset($editorEnabled) || $editorEnabled !== true || !editor_is_authenticated()) {
    http_response_code(404);
    exit;
}

$file = false;
$downloadName = '';

if (isset($_GET['archive'])) {
    $file = backup_archive_file($_GET['archive']);
    $downloadName = basename((string) $_GET['archive']);
} elseif (isset($_GET['page'], $_GET['version'])) {
    $pagename = strtolower(ltrim(trim($_GET['page']), '/.'));
    if (preg_match('/^[a-z0-9\-_\/]+$/', $pagename) && strpos($pagename, '..') === false) {
        $file = backup_version_file($pagename, $_GET['version']);
        $downloadName = str_replace('/', '-', $pagename) . '-' . $_GET['version'] . '.html';
    }
}

if ($file === false) {
    http_response_code(404);
    exit;
}

// A version is shown in the browser (it is just a page file); an archive downloads
$isZip = substr($file, -4) === '.zip';
header('Content-Type: ' . ($isZip ? 'application/zip' : 'text/plain; charset=utf-8'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($isZip ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
while (ob_get_level() > 0) { ob_end_clean(); }
readfile($file);
exit;

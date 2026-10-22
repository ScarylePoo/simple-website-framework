<?php
/*
    editor-files.php
    File uploads and management for the editor: list a folder, upload into
    it, make subfolders, delete to a trash, restore. Included by index.php
    and editor-save.php. Never access this file directly.

    Where files may go
        Only inside the folders named in $uploadDirs (config.php), and
        subfolders of those. The default is the places this framework keeps
        media: images/, pages/images/, pages/posts/images/ and videos/. Code
        folders (plugins/, required/, themes/, config/) and pages/ itself are
        never upload targets, whatever the config says.

    What may be uploaded
        A file passes only if BOTH its extension is on the allow list AND the
        content sniffed by PHP's fileinfo matches a type that extension may
        have. Anything that could execute on the server (.php, .phtml, .phar,
        .cgi, .htaccess …) is refused outright. Each upload folder also gets a
        small .htaccess that stops the web server from executing scripts
        there, so a file that slipped through could not run anyway.

    Keep in mind that the editor already lets a logged-in user write any
    HTML into a page, so these checks are belt and braces against accidents
    and against a hijacked session gaining anything new — not a boundary
    between trusted and untrusted users. The password and two-factor are that
    boundary.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

require_once __DIR__ . '/editor-backup.php';

// ── Settings ──────────────────────────────────────────────────────────────

function files_upload_dirs() {
    $dirs = $GLOBALS['uploadDirs'] ?? array('images', 'pages/images', 'pages/posts/images', 'videos');
    if (!is_array($dirs)) { $dirs = array($dirs); }
    $out = array();
    foreach ($dirs as $d) {
        $d = trim(str_replace('\\', '/', (string) $d), '/');
        if ($d === '' || !preg_match('#^[a-z0-9][a-z0-9_\-/]*$#i', $d) || strpos($d, '..') !== false) { continue; }
        // Never a code folder, never pages/ itself (that would make uploads into pages)
        if (preg_match('#^(plugins|required|includes|config|themes|css|backups)(/|$)#i', $d) || strtolower($d) === 'pages') { continue; }
        $out[] = $d;
    }
    return array_values(array_unique($out));
}

// Bytes. Bounded by what PHP itself will accept, which the UI reports.
function files_max_size() {
    $configured = (int) ($GLOBALS['uploadMaxSize'] ?? 25 * 1048576);
    return min($configured > 0 ? $configured : 25 * 1048576, files_php_limit());
}

function files_ini_bytes($v) {
    $v = trim((string) $v); if ($v === '') { return PHP_INT_MAX; }
    $n = (float) $v; $u = strtolower(substr($v, -1));
    if ($u === 'g') { $n *= 1073741824; } elseif ($u === 'm') { $n *= 1048576; } elseif ($u === 'k') { $n *= 1024; }
    return (int) $n;
}

function files_php_limit() {
    return min(files_ini_bytes(ini_get('upload_max_filesize')), files_ini_bytes(ini_get('post_max_size')));
}

// ── Types ─────────────────────────────────────────────────────────────────

/*
    Extension => acceptable sniffed MIME types. fileinfo is not perfect (a
    .webp may sniff as image/webp or application/octet-stream on an old
    libmagic), so each entry lists what has been seen in practice.
*/
function files_allowed_types() {
    return array(
        'jpg'  => array('image/jpeg'), 'jpeg' => array('image/jpeg'),
        'png'  => array('image/png'),  'gif'  => array('image/gif'),
        'webp' => array('image/webp', 'application/octet-stream'),
        'avif' => array('image/avif', 'application/octet-stream'),
        'svg'  => array('image/svg+xml', 'text/plain', 'text/xml', 'application/xml'),
        'ico'  => array('image/x-icon', 'image/vnd.microsoft.icon'),
        'mp4'  => array('video/mp4', 'application/octet-stream'), 'm4v' => array('video/mp4', 'video/x-m4v'),
        'webm' => array('video/webm'), 'ogv' => array('video/ogg', 'application/ogg'), 'mov' => array('video/quicktime'),
        'mp3'  => array('audio/mpeg', 'application/octet-stream'), 'ogg' => array('audio/ogg', 'application/ogg'),
        'wav'  => array('audio/wav', 'audio/x-wav'), 'm4a' => array('audio/mp4', 'audio/x-m4a'), 'flac' => array('audio/flac', 'audio/x-flac'),
        'pdf'  => array('application/pdf'),
        'txt'  => array('text/plain'), 'md' => array('text/plain', 'text/markdown'), 'csv' => array('text/plain', 'text/csv'),
        'vtt'  => array('text/plain', 'text/vtt'), 'srt' => array('text/plain'), 'json' => array('application/json', 'text/plain'),
        'doc'  => array('application/msword'), 'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
        'xls'  => array('application/vnd.ms-excel'), 'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
        'ppt'  => array('application/vnd.ms-powerpoint'), 'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'),
        'odt'  => array('application/vnd.oasis.opendocument.text', 'application/zip'),
        'zip'  => array('application/zip'),
        'woff' => array('font/woff', 'application/font-woff', 'application/octet-stream'), 'woff2' => array('font/woff2', 'application/octet-stream'),
        'ttf'  => array('font/ttf', 'font/sfnt', 'application/font-sfnt', 'application/octet-stream'), 'otf' => array('font/otf', 'font/sfnt', 'application/octet-stream'),
    );
}

// Refused whatever the allow list says, in case someone adds to it carelessly
function files_forbidden_extension($ext) {
    return (bool) preg_match('/^(php\d*|phtml|phar|phps|pht|inc|cgi|pl|py|rb|sh|bash|exe|dll|so|jsp|asp|aspx|htaccess|htpasswd|ini|js|html?|xhtml|shtml)$/i', $ext);
}

function files_is_image_ext($ext) {
    return in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'), true);
}

// ── Paths ─────────────────────────────────────────────────────────────────

/*
    Turn a browser-supplied folder path into a safe root-relative path, or
    false. The result is always one of the upload roots or a folder beneath
    one, with no traversal, and (if it exists) really inside the site root.
*/
function files_validate_dir($dir) {
    $dir = trim(str_replace('\\', '/', (string) $dir), '/');
    if ($dir === '' || strpos($dir, '..') !== false || !preg_match('#^[a-z0-9][a-z0-9_\-/]*$#i', $dir)) { return false; }
    $ok = false;
    foreach (files_upload_dirs() as $root) {
        if (strcasecmp($dir, $root) === 0 || stripos($dir, $root . '/') === 0) { $ok = true; break; }
    }
    if (!$ok) { return false; }
    $abs = SWF_ROOT . $dir;
    if (file_exists($abs)) {
        if (!is_dir($abs)) { return false; }
        $real = str_replace('\\', '/', realpath($abs));
        if (strpos($real . '/', rtrim(str_replace('\\', '/', realpath(SWF_ROOT)), '/') . '/') !== 0) { return false; }
    }
    return $dir;
}

/*
    Make a file name safe and predictable: lowercase, letters digits dots
    hyphens, no leading dot, one extension. "My Photo (1).JPG" becomes
    "my-photo-1.jpg".
*/
function files_clean_name($name) {
    $name = str_replace('\\', '/', (string) $name);
    $name = basename($name);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-.');
    if ($base === '') { $base = 'file'; }
    return $ext !== '' ? $base . '.' . $ext : $base;
}

/*
    Write the .htaccess that stops scripts executing in an upload folder.
    Rewritten on every upload so it cannot drift. Uses only directives that
    every Apache allows in .htaccess (no php_flag, which can 500 on hosts
    that forbid it).
*/
function files_protect_dir($abs) {
    $ht = "# Uploaded files are served, never executed. Written by the editor on every upload.\n"
        . "<FilesMatch \"\\.(php\\d*|phtml|phar|phps|pht|cgi|pl|py|sh|shtml)$\">\n"
        . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
        . "</FilesMatch>\n"
        . "Options -ExecCGI -Indexes\n"
        . "RemoveHandler .php .phtml .phar .phps .pht .cgi .pl .py .sh\n";
    $f = rtrim($abs, '/') . '/.htaccess';
    if (!file_exists($f) || file_get_contents($f) !== $ht) { @file_put_contents($f, $ht); }
}

// ── Listing ───────────────────────────────────────────────────────────────

function files_list($dir) {
    $abs = SWF_ROOT . $dir;
    $folders = array(); $files = array();
    if (!is_dir($abs)) { return array('folders' => $folders, 'files' => $files, 'exists' => false); }
    foreach (scandir($abs) as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') { continue; }
        $p = $abs . '/' . $e;
        if (is_dir($p)) {
            $n = 0; foreach (scandir($p) as $x) { if ($x !== '.' && $x !== '..' && $x[0] !== '.') { $n++; } }
            $folders[] = array('name' => $e, 'path' => $dir . '/' . $e, 'count' => $n);
        } else {
            $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
            $files[] = array('name' => $e, 'path' => $dir . '/' . $e, 'size' => filesize($p), 'mtime' => filemtime($p), 'ext' => $ext, 'image' => files_is_image_ext($ext));
        }
    }
    usort($folders, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    usort($files, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    return array('folders' => $folders, 'files' => $files, 'exists' => true);
}

// ── Upload ────────────────────────────────────────────────────────────────

function files_upload_error_text($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE:
            return 'bigger than the server allows (' . backup_fmt_size(files_php_limit()) . ').';
        case UPLOAD_ERR_PARTIAL:  return 'only partly uploaded; try again.';
        case UPLOAD_ERR_NO_FILE:  return 'no file received.';
        case UPLOAD_ERR_NO_TMP_DIR: case UPLOAD_ERR_CANT_WRITE:
            return 'the server could not store it (temp folder or permissions).';
        case UPLOAD_ERR_EXTENSION: return 'blocked by a PHP extension on the server.';
    }
    return 'upload failed (code ' . $code . ').';
}

/*
    Store one entry of $_FILES into $dir. Returns ['ok'=>true,'path'=>…,
    'name'=>…,'size'=>…] or ['ok'=>false,'name'=>…,'message'=>…].
*/
function files_store_upload($file, $dir, $replace = false) {
    $orig = (string) ($file['name'] ?? '');
    $name = files_clean_name($orig);
    $fail = function ($m) use ($orig) { return array('ok' => false, 'name' => $orig, 'message' => $m); };

    if (!isset($file['error']) || is_array($file['error'])) { return $fail('bad upload.'); }
    if ($file['error'] !== UPLOAD_ERR_OK) { return $fail(files_upload_error_text($file['error'])); }
    if (!is_uploaded_file($file['tmp_name'])) { return $fail('not an uploaded file.'); }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || files_forbidden_extension($ext)) { return $fail('that type of file cannot be uploaded.'); }
    $types = files_allowed_types();
    if (!isset($types[$ext])) { return $fail('.' . $ext . ' files are not on the allowed list.'); }

    $size = (int) $file['size'];
    if ($size <= 0) { return $fail('the file is empty.'); }
    if ($size > files_max_size()) { return $fail('bigger than the ' . backup_fmt_size(files_max_size()) . ' limit.'); }

    // What is it really?
    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $fi->file($file['tmp_name']);
    }
    if ($mime !== '' && !in_array($mime, $types[$ext], true)) {
        return $fail('the contents (' . $mime . ') do not match a .' . $ext . ' file.');
    }
    // A file claiming to be an image must decode as one (SVG is XML, checked by mime above)
    if (files_is_image_ext($ext) && $ext !== 'svg' && $ext !== 'ico' && @getimagesize($file['tmp_name']) === false) {
        return $fail('this is not a valid image.');
    }
    // Belt and braces: nothing that looks like PHP inside, whatever the type claims
    $head = file_get_contents($file['tmp_name'], false, null, 0, 65536);
    if ($head !== false && preg_match('/<\?php|<\?=|<script[^>]+language\s*=\s*["\']?php/i', $head)) {
        return $fail('the file contains PHP code.');
    }

    $absDir = SWF_ROOT . $dir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) { return $fail('could not create the folder ' . $dir . '.'); }
    if (!is_writable($absDir)) { return $fail('the folder ' . $dir . ' is not writable by the web server.'); }
    files_protect_dir($absDir);

    $dest = $absDir . '/' . $name;
    if (file_exists($dest)) {
        if ($replace) {
            // Keep the old one in the trash so "replace" is not destructive
            files_trash_file($dir . '/' . $name);
        } else {
            // photo.jpg → photo-2.jpg, photo-3.jpg …
            $base = pathinfo($name, PATHINFO_FILENAME); $n = 2;
            while (file_exists($absDir . '/' . $base . '-' . $n . '.' . $ext)) { $n++; }
            $name = $base . '-' . $n . '.' . $ext;
            $dest = $absDir . '/' . $name;
        }
    }
    if (!@move_uploaded_file($file['tmp_name'], $dest)) { return $fail('could not move the file into place.'); }
    @chmod($dest, 0644);
    return array('ok' => true, 'path' => $dir . '/' . $name, 'name' => $name, 'size' => filesize($dest));
}

/*
    $_FILES['files'] with multiple=true arrives as parallel arrays; this
    turns it into one entry per file so files_store_upload can take them
    one at a time.
*/
function files_normalize_uploads($entry) {
    if (!isset($entry['name'])) { return array(); }
    if (!is_array($entry['name'])) { return array($entry); }
    $out = array();
    foreach ($entry['name'] as $i => $n) {
        $out[] = array('name' => $n, 'type' => $entry['type'][$i], 'tmp_name' => $entry['tmp_name'][$i], 'error' => $entry['error'][$i], 'size' => $entry['size'][$i]);
    }
    return $out;
}

// ── Folders ───────────────────────────────────────────────────────────────

function files_make_dir($parent, $name) {
    $parent = files_validate_dir($parent);
    if ($parent === false) { return 'That folder is not an upload location.'; }
    $name = strtolower(trim((string) $name));
    $name = preg_replace('/[^a-z0-9]+/', '-', $name); $name = trim($name, '-');
    if ($name === '') { return 'Give the folder a name (letters, numbers, hyphens).'; }
    $abs = SWF_ROOT . $parent . '/' . $name;
    if (file_exists($abs)) { return 'Something called ' . $name . ' is already there.'; }
    if (!is_dir(SWF_ROOT . $parent) && !@mkdir(SWF_ROOT . $parent, 0755, true)) { return 'Could not create ' . $parent . '.'; }
    if (!@mkdir($abs, 0755)) { return 'Could not create the folder (permissions?).'; }
    files_protect_dir($abs);
    return $parent . '/' . $name;
}

// ── Delete and trash ──────────────────────────────────────────────────────

/*
    Validate a browser-supplied file path: inside an upload folder, a real
    file, not a dotfile.
*/
function files_validate_file($path) {
    $path = trim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '' || strpos($path, '..') !== false || !preg_match('#^[a-z0-9][a-z0-9_\-/]*/[a-z0-9][a-z0-9_.\-]*$#i', $path)) { return false; }
    if (files_validate_dir(dirname($path)) === false) { return false; }
    if (basename($path)[0] === '.') { return false; }
    $abs = SWF_ROOT . $path;
    if (!is_file($abs)) { return false; }
    return $path;
}

/*
    Deleted files go to backups/trash-files/<original path>.<stamp>, so a
    mistaken delete (or a replace) is recoverable from the Backups screen.
*/
function files_trash_file($path) {
    $path = files_validate_file($path);
    if ($path === false) { return false; }
    $root = backup_dir('trash-files');
    if ($root === false) { return false; }
    $dest = $root . $path . '.' . backup_stamp();
    $n = 1; while (file_exists($dest)) { $dest .= '-' . (++$n); }
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) { return false; }
    if (!@rename(SWF_ROOT . $path, $dest)) { return false; }
    return true;
}

function files_list_trash() {
    $root = backup_dir('trash-files');
    if ($root === false || !is_dir($root)) { return array(); }
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root)));
        if (!preg_match('/^(.*)\.(\d{8}-\d{6}(?:-\d+)?)$/', $rel, $m)) { continue; }
        $out[] = array('path' => $m[1], 'stamp' => $m[2], 'time' => backup_stamp_to_time(substr($m[2], 0, 15)), 'size' => $f->getSize(), 'file' => $f->getPathname());
    }
    usort($out, function ($a, $b) { return strcmp($b['stamp'], $a['stamp']); });
    return $out;
}

function files_trash_entry($path, $stamp) {
    if (!backup_valid_stamp($stamp)) { return false; }
    $path = trim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '' || strpos($path, '..') !== false || !preg_match('#^[a-z0-9][a-z0-9_\-/]*/[a-z0-9][a-z0-9_.\-]*$#i', $path)) { return false; }
    $root = backup_dir('trash-files');
    if ($root === false) { return false; }
    $f = $root . $path . '.' . $stamp;
    return is_file($f) ? array('path' => $path, 'file' => $f) : false;
}

function files_untrash($path, $stamp) {
    $e = files_trash_entry($path, $stamp);
    if ($e === false) { return 'That deleted file was not found.'; }
    if (files_validate_dir(dirname($e['path'])) === false) { return 'Its folder is no longer an upload location.'; }
    $dest = SWF_ROOT . $e['path'];
    if (file_exists($dest)) { return 'A file called ' . basename($dest) . ' is there again; delete or rename it first.'; }
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) { return 'Could not recreate its folder.'; }
    if (!@rename($e['file'], $dest)) { return 'Could not move the file back.'; }
    return true;
}

function files_purge($path, $stamp) {
    $e = files_trash_entry($path, $stamp);
    return $e !== false && @unlink($e['file']);
}

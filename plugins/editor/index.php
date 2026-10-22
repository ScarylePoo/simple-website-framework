<?php
/*
    plugins/editor/index.php
    The standalone page editor for Simple Website Framework, served at /edit/
    (the rewrite rule in the site's root .htaccess points /edit/ here).

    Everything the editor does happens on this one screen: log in, browse the
    site's pages and posts, open one to edit, create a new one, preview a draft
    through the live theme, and save. Saving and previewing are AJAX POSTs to
    editor-save.php; previews render through index.php with ?preview and
    never touch pages/ until you save.

    Add to config/config.php (inside the $loadplugins block):
        $editorEnabled        = true;
        $editorToken          = 'your-random-secret-token'; // required — /edit/ is a 404 until it has been presented once per device
        $editorPasswordHash   = password_hash('yourpassword', PASSWORD_BCRYPT);
        $editorTotpSecret     = '';   // optional two-factor; see ?totp=setup
        $editorSessionTimeout = 1800; // seconds (default 30 min)

    Requires nothing from the theme. Uses the site's bundled jQuery and the
    bundled Summernote Lite for rich text.
*/

define('SWF_EDITOR', true);

error_reporting(0);
ini_set('display_errors', 0);

define('SWF_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/../../')) . '/');
define('SWF_EDITOR_URL', '/edit/');

require_once SWF_ROOT . 'config/config.php';
require_once SWF_ROOT . 'required/version.php';

// ── Gate 1: editor switched off → behave exactly like any unknown URL ───────
if (!isset($editorEnabled) || $editorEnabled !== true) {
    header('Location: /404');
    exit;
}

require_once __DIR__ . '/editor-auth.php';
require_once __DIR__ . '/editor-backup.php';
require_once __DIR__ . '/editor-files.php';

/*
    Small helpers used throughout this file.
*/
function swf_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function swf_redirect($url) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . swf_e($url) . '"></noscript>';
    exit;
}

// Build an editor URL from a set of query params
function swf_editor_url($params = array()) {
    $q = http_build_query($params);
    return SWF_EDITOR_URL . ($q !== '' ? '?' . $q : '');
}

// ── Gate 2: configuration must be complete ─────────────────────────────────
/*
    The token is REQUIRED. It is what keeps /edit/ from being a public login
    form: until the token has been presented, /edit/ answers with the site's
    404 page, so scanners looking for admin screens find nothing to catalogue
    and nothing to hammer. Running without one is not offered.

    A missing token is awkward to diagnose, because the symptom is a 404. So
    an incomplete config shows its diagnostic only when ?editor is in the URL
    — which is what the setup docs tell you to type — and 404s otherwise. That
    tells the person following the instructions what is wrong without telling
    a passer-by that an editor exists.
*/
$configError = '';
if (empty($editorToken)) {
    $configError = 'Set <code>$editorToken</code> in <code>config/config.php</code> before using the editor. '
                 . 'It is required: without it <code>/edit/</code> would be a publicly visible login form. '
                 . 'Generate one with <code>php -r "echo bin2hex(random_bytes(16));"</code>.';
} elseif (empty($editorPasswordHash)) {
    $configError = 'Set <code>$editorPasswordHash</code> in <code>config/config.php</code> before using the editor. '
                 . 'See the documentation for how to generate one.';
}

if ($configError !== '' && !array_key_exists('editor', $_GET)) {
    header('Location: /404');
    exit;
}

// ── Gate 3: the token ──────────────────────────────────────────────────────
/*
    /edit/ pretends not to exist until the token has been presented once on
    this device: /edit/?editor=TOKEN. After that a long-lived cookie remembers
    it, so end users bookmark that URL (or just the bare /edit/ once the cookie
    is set) and never think about it again.

    The cookie does not hold the token. It holds an HMAC of the token keyed by
    the password hash, so reading the cookie reveals nothing useful, and
    rotating either the token or the password invalidates every device at once.

    A wrong token is a failed login attempt and counts toward the same per-IP
    lockout as a wrong password, so tokens cannot be enumerated for free. The
    response is a 404 either way; the lockout only decides whether a correct
    token would be accepted.
*/
if ($configError === '') {
    if (array_key_exists('editor', $_GET)) {
        if (!editor_is_locked_out() && editor_check_url_token()) {
            editor_set_token_cookie();
            // Drop the token from the address bar, keep everything else
            $clean = $_GET;
            unset($clean['editor']);
            swf_redirect(swf_editor_url($clean));
        }
        // Same rule as a wrong password: attempts while already locked out
        // are not counted, so a locked-out user cannot extend their own lockout.
        if (!editor_is_locked_out()) {
            editor_record_failed_attempt();
        }
        header('Location: /404');
        exit;
    }

    if (!editor_has_token_cookie()) {
        header('Location: /404');
        exit;
    }
}

// ── Gate 4: diagnostics that would reveal the editor exist sit behind the token
$fatal = '';
if ($configError !== '') {
    $fatal = '<strong>Editor configuration incomplete.</strong> ' . $configError;
} elseif (editor_totp_enabled() && !editor_totp_secret_is_valid($editorTotpSecret)) {
    $fatal = '<strong>Two-factor secret is not valid Base32.</strong> '
           . '<code>$editorTotpSecret</code> must contain only the characters A&ndash;Z and 2&ndash;7 &mdash; a plain passphrase will not work, '
           . 'because authenticator apps decode what you type as Base32 before hashing it. '
           . 'Visit <code>' . swf_e(swf_editor_url(['totp' => 'setup'])) . '</code> to generate a valid one, or set the value back to an empty string to switch two-factor off.';
} else {
    /*
        The editor keeps two pieces of state as flat JSON in its own directory
        (lockouts.json and totp-used.json). A failed write would silently
        disable brute-force limiting and replay protection, so refuse to run
        rather than degrade quietly.
    */
    $unwritable = array();
    if (!is_writable(__DIR__)) {
        $unwritable[] = 'plugins/editor/';
    } else {
        foreach (array('lockouts.json', 'totp-used.json') as $stateFile) {
            $p = __DIR__ . '/' . $stateFile;
            if (file_exists($p) && !is_writable($p)) { $unwritable[] = 'plugins/editor/' . $stateFile; }
        }
    }
    if (!empty($unwritable)) {
        $fatal = '<strong>Editor cannot write its state files.</strong>'
               . '<p>Not writable by the web server: <code>' . swf_e(implode('</code>, <code>', $unwritable)) . '</code></p>'
               . '<p>Without these the editor cannot record failed login attempts, so the 15 minute lockout would not apply and passwords could be guessed without limit. '
               . 'Two-factor codes could also be replayed within their 30 second window. The editor will not run until this is fixed.</p>'
               . '<p>Make the directory writable by the user PHP runs as, for example <code>chmod 755 plugins/editor</code>, and check ownership.</p>';
    }
}

// ── Login POST ─────────────────────────────────────────────────────────────
if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swf_editor_password'])) {
    editor_authenticate($_POST['swf_editor_password'], $_POST['swf_editor_totp'] ?? '');
    // Return to whatever the user was trying to open
    $back = $_GET;
    swf_redirect(swf_editor_url($back));
}

$isAuthed  = $fatal === '' && editor_is_authenticated();
$isLocked  = editor_is_locked_out();
$csrfToken = $isAuthed ? editor_get_csrf_token() : '';
$totpOn    = editor_totp_enabled();

// ── Site facts the views need ──────────────────────────────────────────────
$siteTitle  = $WebsiteTitle ?? 'Simple Website Framework';
$siteAuthor = $WebsiteAuthor ?? '';
$themeName  = $theme ?? 'starter';

/*
    Layout choices come from the active theme's templates, so a theme that adds
    its own page-*.php layouts shows up here automatically, and a page whose
    layout the theme no longer has keeps its value instead of being silently
    rewritten to page-html on save.
*/
function swf_layout_options($themeName, $current = '') {
    $opts = array();
    $dir  = SWF_ROOT . 'themes/' . $themeName . '/';
    foreach (array('page-*.php', 'postarchives*.php') as $pattern) {
        foreach (glob($dir . $pattern) as $f) {
            $name = basename($f, '.php');
            $opts[$name] = $name;
        }
    }
    ksort($opts);
    if ($current !== '' && !isset($opts[$current])) {
        $opts[$current] = $current . ' (not in current theme)';
    }
    return $opts;
}

$typeOptions = array('website', 'article', 'blog', 'profile', 'video', 'music', 'book', 'product');

/*
    Read one page file into [meta => [...], body => '...'].
    The metadata block is the run of <!-- tag: value --> comments at the top of
    the file. Only lines that are KNOWN tags are stripped from the body, so an
    ordinary HTML comment a user left at the top of their content survives.
*/
$swfMetaKeys = array('pagetitle', 'pagelayout', 'pagedate', 'pageimage', 'pageexcerpt', 'pagekeywords', 'pageauthor', 'pagetype', 'pagecategory', 'postcategory');

function swf_read_page($pagename) {
    global $swfMetaKeys;
    $file = SWF_ROOT . 'pages/' . $pagename . '.html';
    $raw  = file_exists($file) ? file_get_contents($file) : '';
    $raw  = str_replace("\r\n", "\n", $raw);

    $meta = array_fill_keys($swfMetaKeys, '');
    foreach ($swfMetaKeys as $key) {
        if (preg_match('/<!--\s*' . $key . '\s*:\s*(.*?)\s*-->/s', $raw, $m)) {
            $meta[$key] = trim($m[1]);
        }
    }

    $lines = explode("\n", $raw);
    $body  = array();
    $pastMeta = false;
    foreach ($lines as $line) {
        if (!$pastMeta) {
            if (trim($line) === '') { continue; }
            if (preg_match('/^\s*<!--\s*(' . implode('|', $swfMetaKeys) . ')\s*:/', $line)) { continue; }
            $pastMeta = true;
        }
        $body[] = $line;
    }

    return array('meta' => $meta, 'body' => trim(implode("\n", $body)), 'mtime' => file_exists($file) ? filemtime($file) : 0);
}

/*
    Walk pages/ and return two lists: posts (anything under pages/posts/) and
    pages (everything else), each entry carrying its title, date and layout.
*/
function swf_list_pages() {
    $pagesDir = SWF_ROOT . 'pages';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS));
    $pages = array();
    $posts = array();
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel  = str_replace('\\', '/', substr($f->getPathname(), strlen($pagesDir) + 1));
        $name = substr($rel, 0, -5);
        $info = swf_read_page($name);
        $entry = array(
            'name'   => $name,
            'title'  => $info['meta']['pagetitle'] !== '' ? $info['meta']['pagetitle'] : $name,
            'date'   => $info['meta']['pagedate'],
            'layout' => $info['meta']['pagelayout'],
            'cats'   => $info['meta']['pagecategory'],
            'filter' => $info['meta']['postcategory'],
            'ts'     => $info['meta']['pagedate'] !== '' ? (strtotime($info['meta']['pagedate']) ?: $info['mtime']) : $info['mtime'],
        );
        if (strpos($name, 'posts/') === 0) { $posts[] = $entry; } else { $pages[] = $entry; }
    }
    usort($pages, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    usort($posts, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return array('pages' => $pages, 'posts' => $posts);
}

/*
    Every category the site knows about, keyed by its normalised name
    (lowercase, trimmed — the same rule the archive templates apply):

        'tutorials' => array('archive' => 'tutorials', 'posts' => 3)

    'archive' is the name of the archive page whose postcategory tag filters
    on it, or null if no such page exists yet. 'posts' is how many posts carry
    the tag. A category comes from either source — an archive page nobody has
    posted to yet still shows up, and so does a tag on a post that has no
    archive page (flagged in the UI so it can be created).
*/
function swf_categories($lists) {
    $cats = array();
    foreach ($lists['pages'] as $p) {
        if (strpos($p['layout'], 'postarchives') !== 0) { continue; }
        $slug = strtolower(trim($p['filter']));
        if ($slug === '') { continue; }
        if (!isset($cats[$slug])) { $cats[$slug] = array('archive' => null, 'posts' => 0); }
        if ($cats[$slug]['archive'] === null) { $cats[$slug]['archive'] = $p['name']; }
    }
    foreach ($lists['posts'] as $p) {
        foreach (explode(',', $p['cats']) as $c) {
            $slug = strtolower(trim($c));
            if ($slug === '') { continue; }
            if (!isset($cats[$slug])) { $cats[$slug] = array('archive' => null, 'posts' => 0); }
            $cats[$slug]['posts']++;
        }
    }
    ksort($cats);
    return $cats;
}

// Normalise a comma separated category string into a unique, ordered list of slugs
function swf_split_categories($value) {
    $out = array();
    foreach (explode(',', (string) $value) as $c) {
        $slug = strtolower(trim($c));
        if ($slug !== '' && !in_array($slug, $out, true)) { $out[] = $slug; }
    }
    return $out;
}

// ── Which view are we showing? ─────────────────────────────────────────────
$view     = 'list';
$editName = '';
$newKind  = '';
if ($isAuthed) {
    if (isset($_GET['totp']) && $_GET['totp'] === 'setup') {
        $view = 'totp';
    } elseif (isset($_GET['backups'])) {
        $view = 'backups';
    } elseif (isset($_GET['files'])) {
        $view = 'files';
        $filesDir = files_validate_dir($_GET['files']);
        if ($filesDir === false) {
            $roots = files_upload_dirs();
            $filesDir = $roots ? $roots[0] : false;
        }
    } elseif (isset($_GET['history'])) {
        $candidate = trim($_GET['history']);
        $path = editor_validate_path($candidate, SWF_ROOT);
        if ($path !== false && file_exists($path)) {
            $view     = 'history';
            $editName = strtolower(ltrim($candidate, '/.'));
        } else {
            $view = 'list';
            $notFound = $candidate;
        }
    } elseif (isset($_GET['new'])) {
        $view    = 'form';
        $newKind = $_GET['new'] === 'post' ? 'post' : 'page';
    } elseif (isset($_GET['page'])) {
        $candidate = trim($_GET['page']);
        $path = editor_validate_path($candidate, SWF_ROOT);
        if ($path !== false && file_exists($path)) {
            $view     = 'form';
            $editName = ltrim($candidate, '/.');
        } else {
            $view = 'list';
            $notFound = $candidate;
        }
    }
}

// Data for the form view
$isNew = ($view === 'form' && $editName === '');
if ($view === 'form') {
    if ($isNew) {
        $page = array('meta' => array_fill_keys($swfMetaKeys, ''), 'body' => '');
        $page['meta']['pagelayout'] = 'page-html';
        $page['meta']['pagetype']   = $newKind === 'post' ? 'article' : 'website';
        $page['meta']['pagedate']   = date('n/j/Y');
        $page['meta']['pageauthor'] = $siteAuthor;

        /*
            "Create archive page for X" from the category picker lands here as
            ?new=page&archive=X. Pre-fill a styled archive filtered on X so the
            whole job is one click and a Create button.
        */
        $newArchiveFor = '';
        if (isset($_GET['archive'])) {
            $slug = strtolower(trim($_GET['archive']));
            if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9 \-]*$/', $slug)) {
                $newArchiveFor = $slug;
                $page['meta']['postcategory'] = $slug;
                $page['meta']['pagetitle']    = ucwords($slug);
                $page['meta']['pageexcerpt']  = 'All posts in ' . $slug . '.';
                $archiveLayouts = swf_layout_options($themeName);
                $page['meta']['pagelayout']   = isset($archiveLayouts['postarchives-styled']) ? 'postarchives-styled' : 'postarchives';
            }
        }
    } else {
        $page = swf_read_page($editName);
        if ($page['meta']['pageauthor'] === '') { $page['meta']['pageauthor'] = $siteAuthor; }
    }
    $isPost = $isNew ? ($newKind === 'post') : (strpos($editName, 'posts/') === 0);
    $layoutOptions = swf_layout_options($themeName, $page['meta']['pagelayout']);
    $categories    = swf_categories(swf_list_pages());
    $pageCats      = swf_split_categories($page['meta']['pagecategory']);
    // A category on this post that nothing else knows about still needs a chip
    foreach ($pageCats as $slug) {
        if (!isset($categories[$slug])) { $categories[$slug] = array('archive' => null, 'posts' => 0); }
    }
    ksort($categories);
    $archiveNewName = $isNew ? '' : $editName;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php /* Page content uses site-root-relative paths (pages/posts/images/…), exactly as the themes do, so resolve them from / rather than from /edit/ */ ?>
<base href="/">
<title>Editor &middot; <?php echo swf_e($siteTitle); ?></title>
<?php if ($isAuthed): ?>
<link rel="stylesheet" href="/plugins/editor/summernote-lite.css">
<?php endif; ?>
<style>
:root {
    --bg: #f4f4f2; --panel: #fff; --ink: #1c1c1c; --muted: #6b6b6b; --line: #dedcd6;
    --accent: #1c1c1c; --accent-ink: #fff; --ok-bg: #e9f6ea; --ok-ink: #1f5e24; --ok-line: #b9dfbc;
    --err-bg: #fdecea; --err-ink: #8b0000; --err-line: #f3c2bd; --warn-bg: #fff8e5; --warn-ink: #7a5600;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: inherit; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.9em; background: rgba(0,0,0,.05); padding: 1px 4px; border-radius: 3px; }

.topbar { display: flex; align-items: center; gap: 14px; padding: 10px 20px; background: var(--panel); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 10; }
.topbar .brand { font-weight: 650; text-decoration: none; }
.topbar .brand small { color: var(--muted); font-weight: 400; margin-left: 8px; }
.topbar .spacer { flex: 1; }
.topbar a.quiet, .topbar button.quiet { color: var(--muted); text-decoration: none; font-size: 0.88rem; background: none; border: 1px solid var(--line); border-radius: 5px; padding: 6px 12px; cursor: pointer; font-family: inherit; }

.wrap { max-width: 1180px; margin: 0 auto; padding: 24px 20px 60px; }
.wrap.narrow { max-width: 440px; padding-top: 70px; text-align: center; }

.btn { display: inline-block; padding: 8px 18px; border-radius: 5px; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink); font: inherit; font-size: 0.92rem; cursor: pointer; text-decoration: none; line-height: 1.4; }
.btn.secondary { background: var(--panel); color: var(--ink); border-color: var(--line); }
.btn.small { padding: 5px 12px; font-size: 0.85rem; }
.btn:disabled { opacity: .5; cursor: default; }

.notice { padding: 12px 16px; border-radius: 5px; margin-bottom: 18px; font-size: 0.92rem; border: 1px solid var(--line); background: var(--panel); }
.notice.ok { background: var(--ok-bg); color: var(--ok-ink); border-color: var(--ok-line); }
.notice.err { background: var(--err-bg); color: var(--err-ink); border-color: var(--err-line); }
.notice.warn { background: var(--warn-bg); color: var(--warn-ink); }
.notice p { margin: 6px 0 0; }

/* login */
.login h1 { font-size: 1.15rem; margin: 0 0 18px; }
.login form { display: flex; gap: 8px; }
.login input { flex: 1; min-width: 0; padding: 9px 12px; border: 1px solid var(--line); border-radius: 5px; font: inherit; }
.login input.code { flex: 0 0 110px; text-align: center; letter-spacing: .12em; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

/* list */
.lists { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.panel { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; }
.panel header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); }
.panel header h2 { font-size: 1rem; margin: 0; flex: 1; }
.panel header .count { color: var(--muted); font-size: 0.85rem; }
.panel ul { list-style: none; margin: 0; padding: 0; max-height: 70vh; overflow: auto; }
.panel li { border-bottom: 1px solid var(--line); }
.panel li:last-child { border-bottom: 0; }
.panel li a { display: block; padding: 10px 16px; text-decoration: none; }
.panel li a:hover { background: var(--bg); }
.panel .t { font-weight: 550; }
.panel .m { color: var(--muted); font-size: 0.82rem; display: flex; gap: 12px; flex-wrap: wrap; }
.panel .m code { background: none; padding: 0; }
.panel .empty { padding: 20px 16px; color: var(--muted); font-size: 0.9rem; }
.panel input.filter { width: 100%; padding: 8px 16px; border: 0; border-bottom: 1px solid var(--line); font: inherit; font-size: 0.9rem; outline: none; }

/* form */
.formhead { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.formhead h1 { font-size: 1.15rem; margin: 0; flex: 1; }
.formhead .path { color: var(--muted); font-size: 0.88rem; }
.editor-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
.editor-grid.previewing { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; background: var(--panel); border: 1px solid var(--line); border-radius: 6px; padding: 16px; margin-bottom: 16px; }
.meta .full { grid-column: span 2; }
.meta > div > label { display: block; font-size: 0.82rem; color: var(--muted); margin-bottom: 4px; }
.meta label small { color: #999; }
.meta input, .meta select { width: 100%; padding: 7px 10px; border: 1px solid var(--line); border-radius: 4px; font: inherit; font-size: 0.92rem; background: var(--panel); }
.meta .hidden { display: none; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip { display: inline-flex; flex-direction: row; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid var(--line); border-radius: 999px; background: var(--panel); font-size: 0.88rem; cursor: pointer; user-select: none; margin: 0; }
.chip:has(input:checked) { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
.chip input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
.chip::before { content: ''; width: 8px; height: 8px; border-radius: 50%; border: 1px solid currentColor; flex: none; }
.chip:has(input:checked)::before { background: currentColor; }
.chip .n { opacity: .6; font-size: 0.8em; }
.chip.orphan { border-style: dashed; }
.chips .add { display: inline-flex; gap: 6px; }
.chips .add input { width: 160px; padding: 5px 10px; border-radius: 999px; }
.chips .add button { padding: 4px 12px; border-radius: 999px; }
.cat-note { font-size: 0.82rem; color: var(--muted); margin-top: 8px; }
.cat-note a { color: var(--ink); }
.cat-note:empty { display: none; }
.content-wrap { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; padding: 16px; }
.content-wrap textarea { width: 100%; min-height: 420px; padding: 10px; border: 1px solid var(--line); border-radius: 4px; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; resize: vertical; }
.content-wrap .mode { font-size: 0.82rem; color: var(--muted); margin-bottom: 8px; }
.actions { display: flex; gap: 10px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
.actions .status { font-size: 0.88rem; margin-left: 4px; }
.actions .status.ok { color: var(--ok-ink); }
.actions .status.err { color: var(--err-ink); }
.preview { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; overflow: hidden; position: sticky; top: 64px; height: calc(100vh - 88px); display: none; flex-direction: column; }
.preview.on { display: flex; }
.preview .bar { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--line); font-size: 0.85rem; color: var(--muted); }
.preview .bar .spacer { flex: 1; }
.preview iframe { flex: 1; width: 100%; border: 0; background: #fff; }
.note-editor.note-frame { border-color: var(--line); }

/* totp */
.totp-wrap { max-width: 680px; margin: 0 auto; }

/* history + backups */
.vlist { list-style: none; margin: 0; padding: 0; }
.vlist li { border-bottom: 1px solid var(--line); padding: 12px 16px; }
.vlist li:last-child { border-bottom: 0; }
.vlist .vhead { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.vlist .vhead .when { font-weight: 550; }
.vlist .vhead .vmeta { color: var(--muted); font-size: 0.85rem; }
.vlist .vhead .spacer { flex: 1; }
.vlist details { margin-top: 8px; }
.vlist summary { cursor: pointer; color: var(--muted); font-size: 0.88rem; }
.diff { margin: 8px 0 0; padding: 10px 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 5px; font: 12.5px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-x: auto; white-space: pre; }
.diff span { display: block; }
.diff-add { background: var(--ok-bg); color: var(--ok-ink); }
.diff-del { background: var(--err-bg); color: var(--err-ink); }
.diff-ctx { color: var(--muted); }
.diff-skip { color: var(--muted); text-align: center; font-style: italic; }
.diff-note { color: var(--muted); font-size: 0.88rem; }
.btn.danger { background: var(--panel); color: var(--err-ink); border-color: var(--err-line); }
.btn.danger:hover { background: var(--err-bg); }
.stack > .panel { margin-bottom: 24px; }
.panel .pad { padding: 16px; }
.panel .pad p { margin: 0 0 10px; }
.panel .pad p:last-child { margin-bottom: 0; }
.kv { color: var(--muted); font-size: 0.88rem; }

/* files */
.crumbs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 0.92rem; }
.crumbs a { text-decoration: none; color: var(--ink); padding: 3px 8px; border-radius: 4px; background: var(--panel); border: 1px solid var(--line); }
.crumbs a.on { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
.crumbs .sep { color: var(--muted); }
.drop { border: 2px dashed var(--line); border-radius: 6px; padding: 22px; text-align: center; color: var(--muted); background: var(--panel); transition: background .15s, border-color .15s; }
.drop.over { border-color: var(--accent); background: var(--bg); color: var(--ink); }
.drop input[type=file] { display: none; }
.drop .btn { margin-top: 10px; }
.drop .opts { margin-top: 10px; font-size: 0.85rem; }
.drop .opts label { display: inline-flex; gap: 6px; align-items: center; }
.filegrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px; padding: 16px; }
.tile { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
.tile .thumb { aspect-ratio: 4/3; background: var(--bg) repeating-conic-gradient(#e9e8e4 0 25%, transparent 0 50%) 0 0/16px 16px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.tile .thumb img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.tile .thumb .ext { font: 600 0.9rem system-ui, sans-serif; color: var(--muted); text-transform: uppercase; background: var(--panel); padding: 6px 10px; border-radius: 4px; border: 1px solid var(--line); }
.tile.folder .thumb { background: var(--bg); }
.tile .info { padding: 8px 10px; font-size: 0.82rem; min-width: 0; }
.tile .info .n { font-weight: 550; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile .info .m { color: var(--muted); }
.tile .acts { display: flex; gap: 6px; padding: 0 10px 10px; }
.tile .acts button { flex: 1; padding: 4px 6px; font-size: 0.78rem; }
a.tile.open, .tile a.open { text-decoration: none; color: inherit; }
.uplog { padding: 0 16px 12px; font-size: 0.85rem; }
.uplog div { padding: 3px 0; }
.uplog .bad { color: var(--err-ink); }
.uplog .good { color: var(--ok-ink); }
.mkdir { display: flex; gap: 8px; align-items: center; }
.mkdir input { padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font: inherit; font-size: 0.88rem; width: 200px; }

/* file picker dialog */
.picker-back { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; }
.picker-back.on { display: flex; }
.picker { background: var(--bg); border-radius: 8px; width: min(960px, 100%); max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
.picker .bar { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--panel); border-radius: 8px 8px 0 0; flex-wrap: wrap; }
.picker .bar h2 { font-size: 1rem; margin: 0; }
.picker .bar .spacer { flex: 1; }
.picker .crumbs { padding: 10px 16px 0; }
.picker .body { overflow: auto; flex: 1; }
.picker .filegrid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
.picker .tile { cursor: pointer; }
.picker .tile:hover { border-color: var(--accent); }
.picker .tile .info { padding: 6px 8px; }
.picker .foot { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-top: 1px solid var(--line); background: var(--panel); border-radius: 0 0 8px 8px; font-size: 0.85rem; color: var(--muted); flex-wrap: wrap; }
.picker .foot input[type=file] { display: none; }
.picker .empty { padding: 30px; text-align: center; color: var(--muted); }
.with-choose { display: flex; gap: 8px; }
.with-choose input { flex: 1; min-width: 0; }
.with-choose .prefix { display: inline-flex; align-items: center; padding: 0 10px; border: 1px solid var(--line); border-right: 0; border-radius: 4px 0 0 4px; background: var(--bg); color: var(--muted); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.9rem; }
.with-choose .prefix + input { border-radius: 0 4px 4px 0; }
.note-btn.swf-files-btn { font-size: 0.8rem; }

@media (max-width: 860px) {
    .lists, .editor-grid.previewing { grid-template-columns: 1fr; }
    .meta { grid-template-columns: 1fr; }
    .meta .full { grid-column: auto; }
    .preview { position: static; height: 70vh; }
}
</style>
</head>
<body>

<?php if ($fatal !== ''): ?>
    <div class="wrap"><div class="notice err"><?php echo $fatal; ?></div></div>

<?php elseif (!$isAuthed): ?>
    <div class="wrap narrow login">
        <h1>
        <?php
            if ($isLocked)  { echo 'Too many failed attempts. Try again in 15 minutes.'; }
            elseif ($totpOn) { echo swf_e($siteTitle) . ' &mdash; editor<br><small style="font-weight:400;color:var(--muted);font-size:0.9rem;">Enter your password and authenticator code</small>'; }
            else             { echo swf_e($siteTitle) . ' &mdash; editor<br><small style="font-weight:400;color:var(--muted);font-size:0.9rem;">Enter your password</small>'; }
        ?>
        </h1>
        <?php if (!$isLocked): ?>
        <form method="POST">
            <input type="password" name="swf_editor_password" placeholder="Password" autofocus autocomplete="current-password">
            <?php if ($totpOn): ?>
            <input class="code" type="text" name="swf_editor_totp" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" aria-label="Authentication code">
            <?php endif; ?>
            <button class="btn" type="submit">Enter</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="topbar">
        <a class="brand" href="<?php echo swf_e(swf_editor_url()); ?>">Editor <small><?php echo swf_e($siteTitle); ?></small></a>
        <span class="kv" title="Simple Website Framework version">SWF <?php echo swf_e(SWF_VERSION); ?></span>
        <span class="spacer"></span>
        <a class="quiet" href="<?php echo swf_e(swf_editor_url(['files' => 1])); ?>">Files</a>
        <a class="quiet" href="<?php echo swf_e(swf_editor_url(['backups' => 1])); ?>">Backups</a>
        <a class="quiet" href="/" target="_blank" rel="noopener">View site &#8599;</a>
        <a class="quiet" href="<?php echo swf_e(swf_editor_url(['totp' => 'setup'])); ?>"><?php echo $totpOn ? 'Two-factor: on' : 'Two-factor: off'; ?></a>
        <button class="quiet" type="button" onclick="swfLogout()">Log out</button>
    </div>

    <?php if ($view === 'totp'): ?>
        <?php require_once __DIR__ . '/editor-totp-setup.php'; ?>
        <div class="wrap totp-wrap">
            <?php echo editor_totp_setup_panel(swf_editor_url(), $siteTitle, $WebsiteURL ?? '', swf_editor_url()); ?>
        </div>

    <?php elseif ($view === 'history'): ?>
        <?php
            $versions = backup_list_versions($editName);
            $current  = file_get_contents(SWF_ROOT . 'pages/' . $editName . '.html');
            $curMeta  = swf_read_page($editName);
        ?>
        <div class="wrap">
            <div class="formhead">
                <a class="btn small secondary" href="<?php echo swf_e(swf_editor_url(['page' => $editName])); ?>">&larr; Back to editing</a>
                <h1>History: <?php echo swf_e($curMeta['meta']['pagetitle'] !== '' ? $curMeta['meta']['pagetitle'] : $editName); ?></h1>
                <span class="path"><code>pages/<?php echo swf_e($editName); ?>.html</code></span>
            </div>
            <div class="notice">
                A copy of the page is kept every time it is saved with changes; the newest <?php echo backup_keep_versions(); ?> are listed here.
                Each entry shows what changed <em>between that version and the page as it is now</em>. <strong>Restore</strong> puts a version back and keeps the current one in the history, so a restore can always be undone.
            </div>
            <div class="panel">
                <?php if (!$versions): ?>
                    <div class="pad kv">No earlier versions yet. One will appear the next time this page is saved.</div>
                <?php endif; ?>
                <ul class="vlist">
                <?php foreach ($versions as $v): $old = file_get_contents($v['file']); $diff = backup_diff_html($old, $current); ?>
                    <li>
                        <div class="vhead">
                            <span class="when"><?php echo date('D j M Y, g:i a', $v['time']); ?></span>
                            <span class="vmeta"><?php echo backup_fmt_size($v['size']); ?><?php echo $diff === '' ? ' &middot; identical to the current page' : ''; ?></span>
                            <span class="spacer"></span>
                            <a class="btn small secondary" href="/plugins/editor/editor-download.php?page=<?php echo urlencode($editName); ?>&amp;version=<?php echo urlencode($v['stamp']); ?>" target="_blank" rel="noopener">View</a>
                            <button class="btn small" type="button" onclick="swfRestore(<?php echo swf_e(json_encode($v['stamp'])); ?>, this)"<?php echo $diff === '' ? ' disabled' : ''; ?>>Restore</button>
                        </div>
                        <?php if ($diff !== ''): ?>
                        <details><summary>What changed</summary><?php echo $diff; ?></details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
            <p class="kv" style="margin-top:12px;"><span class="status" id="status"></span></p>
        </div>

    <?php elseif ($view === 'files'): ?>
        <?php
            $roots   = files_upload_dirs();
            $listing = $filesDir !== false ? files_list($filesDir) : array('folders' => array(), 'files' => array(), 'exists' => false);
            $crumbs  = array();
            if ($filesDir !== false) {
                $parts = explode('/', $filesDir); $acc = '';
                foreach ($parts as $i => $part) {
                    $acc = $acc === '' ? $part : $acc . '/' . $part;
                    // Only crumbs that are themselves valid upload locations are links
                    $crumbs[] = array('label' => $part, 'path' => files_validate_dir($acc) !== false ? $acc : null);
                }
            }
            $maxSize = files_max_size();
        ?>
        <div class="wrap stack">
            <div class="formhead">
                <a class="btn small secondary" href="<?php echo swf_e(swf_editor_url()); ?>">&larr; All pages</a>
                <h1>Files</h1>
                <span class="kv">Images and other files your pages use. Paste a file's path into a page or its Image field.</span>
                <span class="status" id="status"></span>
            </div>

            <?php if (!$roots): ?>
                <div class="notice err">No upload folders are configured. Set <code>$uploadDirs</code> in <code>config/config.php</code>.</div>
            <?php else: ?>
            <div class="crumbs">
                <?php foreach ($roots as $r): ?>
                    <a href="<?php echo swf_e(swf_editor_url(['files' => $r])); ?>" class="<?php echo $filesDir === $r || strpos($filesDir, $r . '/') === 0 ? 'on' : ''; ?>"><?php echo swf_e($r); ?>/</a>
                <?php endforeach; ?>
                <?php if (count($crumbs) > 1): ?>
                    <span class="sep">&rsaquo;</span>
                    <?php foreach (array_slice($crumbs, 1) as $i => $c): ?>
                        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
                        <?php if ($c['path'] !== null): ?><a href="<?php echo swf_e(swf_editor_url(['files' => $c['path']])); ?>" class="<?php echo $c['path'] === $filesDir ? 'on' : ''; ?>"><?php echo swf_e($c['label']); ?></a><?php else: ?><span><?php echo swf_e($c['label']); ?></span><?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <header>
                    <h2><?php echo swf_e($filesDir); ?>/</h2>
                    <span class="count"><?php echo count($listing['files']); ?> file<?php echo count($listing['files']) === 1 ? '' : 's'; ?><?php echo $listing['exists'] ? '' : ' &middot; folder will be created on first upload'; ?></span>
                    <form class="mkdir" onsubmit="swfMkdir(this); return false;">
                        <input type="text" name="name" placeholder="new subfolder" aria-label="New subfolder name">
                        <button class="btn small secondary" type="submit">Create</button>
                    </form>
                </header>
                <div class="pad">
                    <div class="drop" id="drop" ondragover="event.preventDefault(); this.classList.add('over')" ondragleave="this.classList.remove('over')" ondrop="swfDrop(event)">
                        <div>Drop files here, or</div>
                        <label class="btn small" for="file-input">Choose files</label>
                        <input type="file" id="file-input" multiple onchange="swfUploadFiles(this.files)">
                        <div class="opts">
                            <label><input type="checkbox" id="replace-existing"> replace a file that has the same name (the old one goes to the trash)</label>
                        </div>
                        <div class="kv" style="margin-top:8px;">Images, video, audio, PDF, documents, text, fonts and zips, up to <?php echo backup_fmt_size($maxSize); ?> each<?php echo files_php_limit() < (int) ($uploadMaxSize ?? 25 * 1048576) ? ' (the server\'s own limit)' : ''; ?>. Names are made lowercase with hyphens.</div>
                    </div>
                    <div class="uplog" id="uplog"></div>
                </div>
                <?php if ($listing['folders'] || $listing['files']): ?>
                <div class="filegrid">
                    <?php foreach ($listing['folders'] as $f): ?>
                        <a class="tile folder open" href="<?php echo swf_e(swf_editor_url(['files' => $f['path']])); ?>">
                            <div class="thumb"><span class="ext">folder</span></div>
                            <div class="info"><div class="n"><?php echo swf_e($f['name']); ?>/</div><div class="m"><?php echo $f['count']; ?> item<?php echo $f['count'] === 1 ? '' : 's'; ?></div></div>
                        </a>
                    <?php endforeach; ?>
                    <?php foreach ($listing['files'] as $f): ?>
                        <div class="tile" data-path="<?php echo swf_e($f['path']); ?>">
                            <a class="open" href="/<?php echo swf_e($f['path']); ?>" target="_blank" rel="noopener">
                                <div class="thumb"><?php if ($f['image']): ?><img src="/<?php echo swf_e($f['path']); ?>?v=<?php echo $f['mtime']; ?>" alt="" loading="lazy"><?php else: ?><span class="ext"><?php echo swf_e($f['ext']); ?></span><?php endif; ?></div>
                            </a>
                            <div class="info"><div class="n" title="<?php echo swf_e($f['name']); ?>"><?php echo swf_e($f['name']); ?></div><div class="m"><?php echo backup_fmt_size($f['size']); ?> &middot; <?php echo date('j M Y', $f['mtime']); ?></div></div>
                            <div class="acts">
                                <button class="btn small secondary" type="button" onclick="swfCopyPath(<?php echo swf_e(json_encode($f['path'])); ?>, this)">Copy path</button>
                                <button class="btn small danger" type="button" onclick="swfDeleteFile(<?php echo swf_e(json_encode($f['path'])); ?>, this)">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($listing['exists']): ?>
                    <div class="pad kv">Nothing here yet.</div>
                <?php endif; ?>
            </div>
            <p class="kv">Paths are relative to the site root, which is how pages refer to them: <code>&lt;img src="<?php echo swf_e($filesDir); ?>/photo.jpg"&gt;</code>, or <code><?php echo swf_e($filesDir); ?>/photo.jpg</code> in a page's Image field. Files you delete go to the trash on the <a href="<?php echo swf_e(swf_editor_url(['backups' => 1])); ?>">Backups</a> screen.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($view === 'backups'): ?>
        <?php
            $archives  = backup_list_archives();
            $trash     = backup_list_trash();
            $ftrash    = files_list_trash();
            $bdirOk    = backup_dir() !== false;
            $autoDays  = backup_auto_days();
            $latestC   = backup_latest_archive_time('content');
        ?>
        <div class="wrap stack">
            <div class="formhead">
                <a class="btn small secondary" href="<?php echo swf_e(swf_editor_url()); ?>">&larr; All pages</a>
                <h1>Backups</h1>
            </div>

            <?php if (!$bdirOk): ?>
                <div class="notice err"><strong>The backup folder cannot be written.</strong> <code><?php echo swf_e(backup_dir_label()); ?></code> must exist and be writable by the web server, or set <code>$backupDir</code> in <code>config/config.php</code> to a folder that is. Until then nothing below works and the editor will refuse to save, because it cannot keep the previous version.</div>
            <?php elseif (!backup_dir_is_inside_webroot()): ?>
                <div class="notice ok">Backups are stored outside the web root at <code><?php echo swf_e(backup_dir_label()); ?></code>, which is the safest arrangement.</div>
            <?php else: ?>
                <div class="notice">Backups are stored in <code><?php echo swf_e(backup_dir_label()); ?></code> inside the site. That folder carries an <code>.htaccess</code> that denies all web access and an empty <code>index.html</code>; the only way to fetch anything from it is through this screen, logged in. If your server is not Apache, set <code>$backupDir</code> in <code>config/config.php</code> to a folder outside the web root instead.</div>
            <?php endif; ?>

            <div class="panel">
                <header><h2>Make a backup</h2></header>
                <div class="pad">
                    <p><strong>Content backup</strong> &mdash; everything that is yours: <code>pages/</code>, <code>images/</code>, your theme, <code>config.php</code>, <code>robots.txt</code>, <code>.htaccess</code>. No framework code. This is the one to keep: it restores onto any framework version by unzipping it over a working install.</p>
                    <p><strong>Full backup</strong> &mdash; the complete site including the framework. Larger, and the framework part can always be re-downloaded, but it is a single file that is the whole site.</p>
                    <p class="kv">Both include <code>config/config.php</code>, which holds the editor password hash and two-factor secret. Treat a downloaded backup as you would the site itself.</p>
                    <div class="actions" style="margin-top:12px;">
                        <button class="btn" type="button" onclick="swfBackup('content', this)">Make content backup</button>
                        <button class="btn secondary" type="button" onclick="swfBackup('full', this)">Make full backup</button>
                        <span class="status" id="status"></span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <header>
                    <h2>Automatic backups</h2>
                </header>
                <div class="pad">
                    <?php if ($autoDays > 0): ?>
                        <p>Whenever a page is saved, the editor checks for a content backup newer than <?php echo $autoDays; ?> day<?php echo $autoDays === 1 ? '' : 's'; ?> and makes one if there isn't.
                        <?php if ($latestC): ?>The newest content backup is from <strong><?php echo date('D j M Y, g:i a', $latestC); ?></strong><?php echo (time() - $latestC) > $autoDays * 86400 ? ', so the next save will make one' : ''; ?>.<?php else: ?>There is none yet, so the next save will make one.<?php endif; ?></p>
                    <?php else: ?>
                        <p>Automatic backups on save are switched off (<code>$backupAutoDays = 0</code>).</p>
                    <?php endif; ?>
                    <p class="kv">Automatic and scheduled backups are rotated: the newest <?php echo backup_keep_archives(); ?> of each kind are kept. Backups you make with the buttons above are never removed automatically. For backups on a timetable, run <code>php plugins/editor/backup-cli.php content</code> from your host's cron; see the documentation.</p>
                </div>
            </div>

            <div class="panel">
                <header><h2>Stored backups</h2><span class="count"><?php echo count($archives); ?></span></header>
                <?php if (!$archives): ?><div class="pad kv">No backups stored yet.</div><?php endif; ?>
                <ul class="vlist">
                <?php foreach ($archives as $a): ?>
                    <li class="vhead">
                        <span class="when"><?php echo $a['kind'] === 'full' ? 'Full site' : 'Content'; ?></span>
                        <span class="vmeta"><?php echo date('D j M Y, g:i a', $a['time']); ?> &middot; <?php echo backup_fmt_size($a['size']); ?> &middot; <?php echo $a['trigger'] === 'manual' ? 'made by hand' : ($a['trigger'] === 'cron' ? 'scheduled' : 'automatic'); ?></span>
                        <span class="spacer"></span>
                        <a class="btn small" href="/plugins/editor/editor-download.php?archive=<?php echo urlencode($a['name']); ?>">Download</a>
                        <button class="btn small danger" type="button" onclick="swfDeleteBackup(<?php echo swf_e(json_encode($a['name'])); ?>, this)">Delete</button>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>

            <div class="panel">
                <header><h2>Deleted pages</h2><span class="count"><?php echo count($trash); ?></span></header>
                <?php if (!$trash): ?><div class="pad kv">Nothing in the trash. Pages deleted from the editor land here, with their history, until you restore or permanently delete them.</div><?php endif; ?>
                <ul class="vlist">
                <?php foreach ($trash as $t): ?>
                    <li class="vhead">
                        <span class="when"><?php echo swf_e($t['title']); ?></span>
                        <span class="vmeta"><code>/<?php echo swf_e($t['page']); ?></code> &middot; deleted <?php echo date('D j M Y, g:i a', $t['time']); ?></span>
                        <span class="spacer"></span>
                        <button class="btn small" type="button" onclick="swfUntrash(<?php echo swf_e(json_encode($t['page'])); ?>, <?php echo swf_e(json_encode($t['stamp'])); ?>, this)">Restore</button>
                        <button class="btn small danger" type="button" onclick="swfPurge(<?php echo swf_e(json_encode($t['page'])); ?>, <?php echo swf_e(json_encode($t['stamp'])); ?>, this)">Delete permanently</button>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>

            <div class="panel">
                <header><h2>Deleted files</h2><span class="count"><?php echo count($ftrash); ?></span></header>
                <?php if (!$ftrash): ?><div class="pad kv">No deleted files. Files deleted or replaced on the Files screen land here until you restore or permanently delete them.</div><?php endif; ?>
                <ul class="vlist">
                <?php foreach ($ftrash as $t): ?>
                    <li class="vhead">
                        <span class="when"><?php echo swf_e(basename($t['path'])); ?></span>
                        <span class="vmeta"><code><?php echo swf_e(dirname($t['path'])); ?>/</code> &middot; <?php echo backup_fmt_size($t['size']); ?> &middot; deleted <?php echo date('D j M Y, g:i a', $t['time']); ?></span>
                        <span class="spacer"></span>
                        <button class="btn small" type="button" onclick="swfUntrashFile(<?php echo swf_e(json_encode($t['path'])); ?>, <?php echo swf_e(json_encode($t['stamp'])); ?>, this)">Restore</button>
                        <button class="btn small danger" type="button" onclick="swfPurgeFile(<?php echo swf_e(json_encode($t['path'])); ?>, <?php echo swf_e(json_encode($t['stamp'])); ?>, this)">Delete permanently</button>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>

    <?php elseif ($view === 'list'): ?>
        <?php $lists = swf_list_pages(); ?>
        <div class="wrap">
            <?php if (!empty($notFound)): ?>
                <div class="notice err">No page called <code><?php echo swf_e($notFound); ?></code> exists.</div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice ok">Moved <code>/<?php echo swf_e($_GET['deleted']); ?></code> to the trash. You can bring it back from <a href="<?php echo swf_e(swf_editor_url(['backups' => 1])); ?>">Backups</a>.</div>
            <?php endif; ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="notice ok">Created <code><?php echo swf_e($_GET['saved']); ?></code>.</div>
            <?php endif; ?>
            <div class="lists">
                <div class="panel">
                    <header>
                        <h2>Pages</h2>
                        <span class="count"><?php echo count($lists['pages']); ?></span>
                        <a class="btn small" href="<?php echo swf_e(swf_editor_url(['new' => 'page'])); ?>">+ New page</a>
                    </header>
                    <input class="filter" type="search" placeholder="Filter pages…" oninput="swfFilter(this, 'list-pages')">
                    <ul id="list-pages">
                        <?php if (empty($lists['pages'])): ?><li class="empty">No pages yet.</li><?php endif; ?>
                        <?php foreach ($lists['pages'] as $p): ?>
                        <li data-f="<?php echo swf_e(strtolower($p['title'] . ' ' . $p['name'])); ?>">
                            <a href="<?php echo swf_e(swf_editor_url(['page' => $p['name']])); ?>">
                                <div class="t"><?php echo swf_e($p['title']); ?></div>
                                <div class="m"><code>/<?php echo swf_e($p['name']); ?></code>
                                    <?php if (strpos($p['layout'], 'postarchives') === 0): ?>
                                        <span><?php echo $p['filter'] === '' ? 'Lists all posts' : 'Lists posts in <em>' . swf_e($p['filter']) . '</em>'; ?></span>
                                    <?php elseif ($p['layout'] !== ''): ?>
                                        <span><?php echo swf_e($p['layout']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="panel">
                    <header>
                        <h2>Posts</h2>
                        <span class="count"><?php echo count($lists['posts']); ?></span>
                        <a class="btn small" href="<?php echo swf_e(swf_editor_url(['new' => 'post'])); ?>">+ New post</a>
                    </header>
                    <input class="filter" type="search" placeholder="Filter posts…" oninput="swfFilter(this, 'list-posts')">
                    <ul id="list-posts">
                        <?php if (empty($lists['posts'])): ?><li class="empty">No posts yet. Posts live in <code>pages/posts/</code>.</li><?php endif; ?>
                        <?php foreach ($lists['posts'] as $p): ?>
                        <li data-f="<?php echo swf_e(strtolower($p['title'] . ' ' . $p['name'] . ' ' . $p['cats'])); ?>">
                            <a href="<?php echo swf_e(swf_editor_url(['page' => $p['name']])); ?>">
                                <div class="t"><?php echo swf_e($p['title']); ?></div>
                                <div class="m">
                                    <?php if ($p['date'] !== ''): ?><span><?php echo swf_e($p['date']); ?></span><?php endif; ?>
                                    <code>/<?php echo swf_e($p['name']); ?></code>
                                    <?php if ($p['cats'] !== ''): ?><span><?php echo swf_e($p['cats']); ?></span><?php endif; ?>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

    <?php else: /* form view: edit or new */ ?>
        <?php $m = $page['meta']; ?>
        <div class="wrap">
            <div class="formhead">
                <a class="btn small secondary" href="<?php echo swf_e(swf_editor_url()); ?>">&larr; All pages</a>
                <h1><?php echo $isNew ? ($isPost ? 'New post' : 'New page') : swf_e($m['pagetitle'] !== '' ? $m['pagetitle'] : $editName); ?></h1>
                <?php if (!$isNew): ?><span class="path"><code>pages/<?php echo swf_e($editName); ?>.html</code></span><?php endif; ?>
            </div>

            <?php if ($isNew && $newArchiveFor !== ''): ?>
                <div class="notice">
                    <strong>This is a category page.</strong> It will list every post tagged <em><?php echo swf_e($newArchiveFor); ?></em>, newest first,
                    and visitors will reach it at <code>/<?php echo swf_e(preg_replace('/[^a-z0-9]+/', '-', $newArchiveFor)); ?></code>.
                    The posts themselves are written and edited from the <a href="<?php echo swf_e(swf_editor_url()); ?>">Posts list</a>.
                    Anything you type in the content box below appears above the list of posts.
                </div>
            <?php elseif (!$isNew && strpos($m['pagelayout'], 'postarchives') === 0): ?>
                <div class="notice">
                    <strong>This is a category page.</strong>
                    <?php if (trim($m['postcategory']) === ''): ?>
                        It lists every post on the site, newest first.
                    <?php else: ?>
                        It lists every post tagged <em><?php echo swf_e($m['postcategory']); ?></em>, newest first.
                    <?php endif; ?>
                    The posts themselves are written and edited from the <a href="<?php echo swf_e(swf_editor_url()); ?>">Posts list</a>.
                    Anything in the content box below appears above the list.
                </div>
            <?php endif; ?>

            <div class="editor-grid" id="editor-grid">
            <div>
                <div class="meta">
                    <?php if ($isNew): ?>
                    <div class="full">
                        <label>Address <small>(<?php echo $isPost ? 'posts always live under posts/; ' : ''; ?>lowercase letters, numbers and hyphens<?php echo $isPost ? '' : '; use / for folders'; ?>)</small></label>
                        <div class="with-choose">
                            <?php if ($isPost): ?><span class="prefix">posts/</span><?php endif; ?>
                            <input type="text" id="f-pagename" placeholder="<?php echo $isPost ? 'my-first-post' : 'about-us  or  docs/getting-started'; ?>" value="<?php echo !$isPost && $newArchiveFor !== '' ? swf_e(preg_replace('/[^a-z0-9]+/', '-', $newArchiveFor)) : ''; ?>" oninput="swfAddressTouched = true" autofocus>
                        </div>
                        <div class="kv" id="address-hint" style="margin-top:4px;"></div>
                    </div>
                    <?php endif; ?>
                    <div class="full">
                        <label>Title</label>
                        <input type="text" id="f-pagetitle" value="<?php echo swf_e($m['pagetitle']); ?>"<?php echo $isNew ? ' oninput="swfAddressFromTitle()"' : ''; ?>>
                    </div>
                    <div>
                        <label>Layout</label>
                        <select id="f-pagelayout" onchange="swfLayoutChanged()">
                            <?php foreach ($layoutOptions as $val => $label): ?>
                            <option value="<?php echo swf_e($val); ?>"<?php echo $val === $m['pagelayout'] ? ' selected' : ''; ?>><?php echo swf_e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Type</label>
                        <select id="f-pagetype">
                            <?php foreach ($typeOptions as $val): ?>
                            <option value="<?php echo $val; ?>"<?php echo $val === $m['pagetype'] ? ' selected' : ''; ?>><?php echo $val; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Date</label>
                        <input type="text" id="f-pagedate" value="<?php echo swf_e($m['pagedate']); ?>">
                    </div>
                    <div>
                        <label>Author</label>
                        <input type="text" id="f-pageauthor" value="<?php echo swf_e($m['pageauthor']); ?>">
                    </div>
                    <div class="full">
                        <label>Image <small>(path relative to the site root)</small></label>
                        <div class="with-choose">
                            <input type="text" id="f-pageimage" value="<?php echo swf_e($m['pageimage']); ?>" placeholder="pages/posts/images/my-image.webp">
                            <button class="btn small secondary" type="button" onclick="swfPickerOpen(function (p) { document.getElementById('f-pageimage').value = p; }, true)">Choose…</button>
                        </div>
                    </div>
                    <div class="full">
                        <label>Excerpt <small>(about 160 characters; posts need one to appear in archives)</small></label>
                        <input type="text" id="f-pageexcerpt" value="<?php echo swf_e($m['pageexcerpt']); ?>">
                    </div>
                    <div class="full">
                        <label>Keywords <small>(comma separated)</small></label>
                        <input type="text" id="f-pagekeywords" value="<?php echo swf_e($m['pagekeywords']); ?>">
                    </div>
                    <div class="full<?php echo $isPost ? '' : ' hidden'; ?>" id="row-pagecategory">
                        <label>Categories <small>(tick any that apply; none means uncategorized)</small></label>
                        <div class="chips" id="cat-chips">
                            <?php foreach ($categories as $slug => $c): if ($slug === 'uncategorized') { continue; } // "no category" is the absence of chips, not a chip ?>
                            <label class="chip<?php echo $c['archive'] === null ? ' orphan' : ''; ?>" data-archive="<?php echo swf_e($c['archive'] ?? ''); ?>">
                                <input type="checkbox" value="<?php echo swf_e($slug); ?>"<?php echo in_array($slug, $pageCats, true) ? ' checked' : ''; ?> onchange="swfCatsChanged()">
                                <?php echo swf_e($slug); ?>
                                <span class="n" title="<?php echo (int) $c['posts']; ?> post(s) in this category"><?php echo (int) $c['posts']; ?></span>
                            </label>
                            <?php endforeach; ?>
                            <span class="add">
                                <input type="text" id="cat-new" placeholder="new category" onkeydown="if(event.key==='Enter'){event.preventDefault();swfCatAdd();}">
                                <button class="btn small secondary" type="button" onclick="swfCatAdd()">Add</button>
                            </span>
                        </div>
                        <div class="cat-note" id="cat-note"></div>
                        <input type="hidden" id="f-pagecategory" value="<?php echo swf_e(implode(', ', $pageCats)); ?>">
                    </div>
                    <div class="full<?php echo strpos($m['pagelayout'], 'postarchives') === 0 ? '' : ' hidden'; ?>" id="row-postcategory">
                        <label>Which posts this page lists <small>(pick a category, or show all)</small></label>
                        <?php
                            $filterNow = strtolower(trim($m['postcategory']));
                            $filterKnown = $filterNow === '' || $filterNow === 'uncategorized' || isset($categories[$filterNow]);
                        ?>
                        <select id="f-postcategory-select" onchange="swfFilterChanged()">
                            <option value=""<?php echo $filterNow === '' ? ' selected' : ''; ?>>(all posts)</option>
                            <option value="uncategorized"<?php echo $filterNow === 'uncategorized' ? ' selected' : ''; ?>>uncategorized — posts with no category</option>
                            <?php foreach ($categories as $slug => $c): if ($slug === 'uncategorized') { continue; } ?>
                            <option value="<?php echo swf_e($slug); ?>"<?php echo $filterNow === $slug ? ' selected' : ''; ?>><?php echo swf_e($slug); ?> (<?php echo (int) $c['posts']; ?> post<?php echo $c['posts'] === 1 ? '' : 's'; ?>)</option>
                            <?php endforeach; ?>
                            <option value="__other"<?php echo $filterKnown ? '' : ' selected'; ?>>Other…</option>
                        </select>
                        <input type="text" id="f-postcategory-other" placeholder="category name" value="<?php echo $filterKnown ? '' : swf_e($m['postcategory']); ?>" style="margin-top:8px;<?php echo $filterKnown ? 'display:none;' : ''; ?>" oninput="swfFilterChanged()">
                        <input type="hidden" id="f-postcategory" value="<?php echo swf_e($m['postcategory']); ?>">
                    </div>
                </div>

                <div class="content-wrap">
                    <div class="mode" id="mode-label"></div>
                    <div id="rich"></div>
                    <textarea id="plain" style="display:none;"></textarea>
                </div>

                <div class="actions">
                    <button class="btn" type="button" onclick="swfSave()"><?php echo $isNew ? ($isPost ? 'Create post' : 'Create page') : 'Save'; ?></button>
                    <button class="btn secondary" type="button" onclick="swfPreview()">Preview</button>
                    <?php if (!$isNew): ?>
                        <a class="btn secondary" href="/<?php echo swf_e($editName); ?>" target="_blank" rel="noopener">Open live &#8599;</a>
                        <?php $vcount = count(backup_list_versions($editName)); ?>
                        <a class="btn secondary" href="<?php echo swf_e(swf_editor_url(['history' => $editName])); ?>">History<?php echo $vcount ? ' (' . $vcount . ')' : ''; ?></a>
                        <?php if (!in_array($editName, array('home', '404', 'navigation'), true) && strpos($editName, 'footer/') !== 0): ?>
                        <button class="btn danger" type="button" onclick="swfDelete()" style="margin-left:auto;">Delete</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span class="status" id="status"></span>
                </div>
            </div>

            <div class="picker-back" id="picker" onclick="if (event.target === this) swfPickerClose()">
                <div class="picker" role="dialog" aria-modal="true" aria-labelledby="picker-title">
                    <div class="bar">
                        <h2 id="picker-title">Choose a file</h2>
                        <label style="font-size:0.85rem;color:var(--muted);display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" id="picker-all" onchange="swfPickerLoad(swfPickerDir)"> show all files, not just images</label>
                        <span class="spacer"></span>
                        <button class="btn small secondary" type="button" onclick="swfPickerClose()">Close</button>
                    </div>
                    <div class="crumbs" id="picker-crumbs"></div>
                    <div class="body" id="picker-body"><div class="empty">Loading…</div></div>
                    <div class="foot">
                        <label class="btn small" for="picker-upload">Upload new…</label>
                        <input type="file" id="picker-upload" multiple onchange="swfPickerUpload(this.files)">
                        <span id="picker-status">Click a file to insert it. Uploads go into the folder you are looking at.</span>
                        <span class="spacer"></span>
                        <a href="<?php echo swf_e(swf_editor_url(['files' => 1])); ?>" target="_blank" rel="noopener">Open the Files screen &#8599;</a>
                    </div>
                </div>
            </div>

            <div class="preview" id="preview">
                <div class="bar">
                    <span>Preview &mdash; unsaved draft rendered through the <code><?php echo swf_e($themeName); ?></code> theme</span>
                    <span class="spacer"></span>
                    <button class="btn small secondary" type="button" onclick="swfPreview()">Refresh</button>
                    <button class="btn small secondary" type="button" onclick="swfPreviewClose()">Close</button>
                </div>
                <iframe id="preview-frame" title="Preview"></iframe>
            </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($isAuthed): ?>
<script src="/includes/jquery-3.7.1.min.js"></script>
<script src="/plugins/editor/summernote-lite.min.js"></script>
<script>
var SWF_CSRF   = <?php echo json_encode($csrfToken); ?>;
var SWF_SAVE   = '/plugins/editor/editor-save.php';
var SWF_EDITOR = <?php echo json_encode(SWF_EDITOR_URL); ?>;

function swfFilter(input, listId) {
    var q = input.value.toLowerCase();
    document.querySelectorAll('#' + listId + ' li[data-f]').forEach(function (li) {
        li.style.display = li.getAttribute('data-f').indexOf(q) !== -1 ? '' : 'none';
    });
}

function swfGeneric(data, btn, done) {
    if (btn) { btn.disabled = true; }
    data.csrf_token = SWF_CSRF;
    var el = document.getElementById('status');
    if (el) { el.textContent = 'Working…'; el.className = 'status'; }
    jQuery.post(SWF_SAVE, data, function (r) {
        if (el) { el.textContent = r.message || ''; el.className = 'status ' + (r.success ? 'ok' : 'err'); }
        if (btn) { btn.disabled = false; }
        if (r.success && done) { done(r); }
    }, 'json').fail(function (x) {
        var msg = 'Request failed.'; try { msg = JSON.parse(x.responseText).message || msg; } catch (e) {}
        if (el) { el.textContent = msg; el.className = 'status err'; }
        if (btn) { btn.disabled = false; }
    });
}

function swfRestore(stamp, btn) {
    if (!confirm('Restore this version? The current page is kept in the history, so you can come back to it.')) { return; }
    swfGeneric({ action: 'restore', pagename: <?php echo json_encode($editName); ?>, stamp: stamp }, btn, function () { setTimeout(function () { window.location.reload(); }, 600); });
}

function swfDelete() {
    if (!confirm('Move this page to the trash? It can be restored from the Backups screen.')) { return; }
    swfGeneric({ action: 'delete', pagename: <?php echo json_encode($editName); ?> }, null, function () {
        swfSavedSnapshot = null;
        window.location.href = SWF_EDITOR + '?deleted=' + encodeURIComponent(<?php echo json_encode($editName); ?>);
    });
}

function swfBackup(kind, btn) {
    var el = document.getElementById('status'); if (el) { el.textContent = 'Building the ' + kind + ' backup… this can take a little while on a large site.'; el.className = 'status'; }
    swfGeneric({ action: 'backup', kind: kind }, btn, function (r) {
        window.location.href = '/plugins/editor/editor-download.php?archive=' + encodeURIComponent(r.name);
        setTimeout(function () { window.location.reload(); }, 1500);
    });
}

function swfDeleteBackup(name, btn) {
    if (!confirm('Delete this backup?')) { return; }
    swfGeneric({ action: 'deletebackup', name: name }, btn, function () { window.location.reload(); });
}

function swfUntrash(page, stamp, btn) {
    swfGeneric({ action: 'untrash', pagename: page, stamp: stamp }, btn, function (r) { window.location.href = SWF_EDITOR + '?page=' + encodeURIComponent(r.pagename); });
}

function swfPurge(page, stamp, btn) {
    if (!confirm('Permanently delete this page and its history? This cannot be undone.')) { return; }
    swfGeneric({ action: 'purge', pagename: page, stamp: stamp }, btn, function () { window.location.reload(); });
}

function swfEsc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

/* Files screen */
var SWF_FILES_DIR = <?php echo json_encode($view === 'files' ? $filesDir : ''); ?>;

function swfUploadTo(dir, fileList, replace, done) {
    var fd = new FormData();
    fd.append('action', 'upload'); fd.append('csrf_token', SWF_CSRF); fd.append('dir', dir);
    if (replace) { fd.append('replace', '1'); }
    for (var i = 0; i < fileList.length; i++) { fd.append('files[]', fileList[i]); }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', SWF_SAVE);
    xhr.onload = function () {
        var r; try { r = JSON.parse(xhr.responseText); } catch (e) { r = { success: false, message: xhr.status === 413 ? 'The server refused the upload as too large.' : 'Upload failed (server error).' }; }
        done(r);
    };
    xhr.onerror = function () { done({ success: false, message: 'Upload failed (network).' }); };
    xhr.send(fd);
}

function swfUploadFiles(fileList) {
    if (!fileList || !fileList.length) { return; }
    var log = document.getElementById('uplog');
    log.innerHTML = '<div>Uploading ' + fileList.length + ' file' + (fileList.length === 1 ? '' : 's') + '…</div>';
    swfUploadTo(SWF_FILES_DIR, fileList, document.getElementById('replace-existing').checked, function (r) {
        var html = '';
        (r.results || []).forEach(function (x) {
            html += x.ok ? '<div class="good">&#10003; ' + swfEsc(x.name) + '</div>' : '<div class="bad">&#10007; ' + swfEsc(x.name) + ' &mdash; ' + swfEsc(x.message) + '</div>';
        });
        if (!r.results) { html = '<div class="bad">' + swfEsc(r.message) + '</div>'; }
        var allOk = r.results && r.results.length && r.results.every(function (x) { return x.ok; });
        if (allOk) {
            log.innerHTML = html;
            setTimeout(function () { window.location.reload(); }, 700);
        } else {
            // Leave the reasons on screen; the uploaded ones appear on refresh
            if (r.success) { html += '<div><a href="#" onclick="window.location.reload(); return false;">Refresh to see the uploaded files</a></div>'; }
            log.innerHTML = html;
        }
    });
}

function swfDrop(ev) {
    ev.preventDefault(); document.getElementById('drop').classList.remove('over');
    swfUploadFiles(ev.dataTransfer.files);
}

function swfMkdir(form) {
    swfGeneric({ action: 'mkdir', dir: SWF_FILES_DIR, name: form.name.value }, null, function (r) { window.location.href = SWF_EDITOR + '?files=' + encodeURIComponent(r.dir); });
}

function swfCopyPath(path, btn) {
    var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(path).then(done, function () { window.prompt('Copy this path:', path); }); }
    else { window.prompt('Copy this path:', path); }
}

function swfDeleteFile(path, btn) {
    if (!confirm('Move ' + path + ' to the trash? Pages that use it will show a broken image until it is restored.')) { return; }
    swfGeneric({ action: 'deletefile', path: path }, btn, function () { window.location.reload(); });
}

function swfUntrashFile(path, stamp, btn) { swfGeneric({ action: 'untrashfile', path: path, stamp: stamp }, btn, function () { window.location.reload(); }); }
function swfPurgeFile(path, stamp, btn) { if (!confirm('Permanently delete this file? This cannot be undone.')) { return; } swfGeneric({ action: 'purgefile', path: path, stamp: stamp }, btn, function () { window.location.reload(); }); }

function swfLogout() {
    jQuery.post(SWF_SAVE, { action: 'logout', csrf_token: SWF_CSRF }).always(function () {
        window.location.href = SWF_EDITOR;
    });
}

<?php if ($view === 'form'): ?>
var SWF_IS_NEW   = <?php echo $isNew ? 'true' : 'false'; ?>;

/*
    The address follows the title until the address is edited by hand.
    "Shields That React" -> posts/shields-that-react. The server does the same
    derivation if the address still arrives empty or as a bare folder, so a
    post can never again be saved as a page called /posts.
*/
var swfAddressTouched = false;
var SWF_ADDRESS_PREFIX = <?php echo json_encode($isNew && $isPost ? 'posts/' : ''); ?>;
function swfSlug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80).replace(/-+$/, ''); }
function swfAddressFromTitle() {
    if (!SWF_IS_NEW) { return; }
    var addr = document.getElementById('f-pagename');
    var cur = addr.value.trim();
    // Only while the field is untouched or empty
    if (swfAddressTouched && cur !== '') { return; }
    var slug = swfSlug(document.getElementById('f-pagetitle').value);
    addr.value = slug;
    swfAddressTouched = false;
    document.getElementById('address-hint').textContent = slug ? 'Made from the title; edit it if you want something else.' : '';
}
var SWF_UPLOAD_DIR = <?php echo json_encode($isPost && files_validate_dir('pages/posts/images') !== false ? 'pages/posts/images' : (files_validate_dir('pages/images') !== false ? 'pages/images' : (files_upload_dirs() ? files_upload_dirs()[0] : ''))); ?>;
var SWF_PAGENAME = <?php echo json_encode($editName); ?>;
var SWF_BODY     = <?php echo json_encode($page['body']); ?>;
var swfMode      = null;   // 'rich' or 'plain'
var swfRichReady = false;

function swfIsMarkdownLayout() {
    return /^page-md/.test(document.getElementById('f-pagelayout').value);
}

/*
    Markdown layouts (page-md*) are edited as plain text, because a WYSIWYG
    editor would turn the markdown into HTML. Everything else gets Summernote.
    Switching layouts moves the content across untouched.
*/
/*
    File picker. One dialog, two users: the Files toolbar button in the
    content editor and the Choose… button beside the Image field. Each
    passes a callback that receives the chosen path.
*/
var swfPickerCb = null, swfPickerDir = SWF_UPLOAD_DIR, swfPickerImagesOnly = true;

function swfPickerOpen(cb, imagesOnly) {
    swfPickerCb = cb; swfPickerImagesOnly = !!imagesOnly;
    jQuery('.note-tooltip').hide(); // the dialog steals the mouse-leave that would hide it
    document.getElementById('picker-all').checked = !imagesOnly;
    document.getElementById('picker').classList.add('on');
    swfPickerLoad(swfPickerDir);
}

function swfPickerClose() {
    document.getElementById('picker').classList.remove('on');
    swfPickerCb = null;
}

document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.getElementById('picker').classList.contains('on')) { swfPickerClose(); } });

function swfPickerChoose(path, name) {
    var cb = swfPickerCb; swfPickerClose();
    if (cb) { cb(path, name); }
}

function swfPickerLoad(dir) {
    var body = document.getElementById('picker-body');
    body.innerHTML = '<div class="empty">Loading…</div>';
    jQuery.post(SWF_SAVE, { action: 'listfiles', csrf_token: SWF_CSRF, dir: dir }, function (r) {
        if (!r.success) { body.innerHTML = '<div class="empty">' + swfEsc(r.message) + '</div>'; return; }
        swfPickerDir = r.dir;
        var showAll = document.getElementById('picker-all').checked;
        // crumbs: the roots, then the path inside the current root
        var c = '';
        r.roots.forEach(function (root) {
            var on = r.dir === root || r.dir.indexOf(root + '/') === 0;
            c += '<a href="#" class="' + (on ? 'on' : '') + '" onclick="swfPickerLoad(' + swfEsc(JSON.stringify(root)).replace(/&quot;/g, '&quot;') + ');return false;">' + swfEsc(root) + '/</a>';
        });
        if (r.parent !== '' && r.roots.indexOf(r.dir) === -1) {
            c += '<span class="sep">&rsaquo;</span><a href="#" class="on" onclick="swfPickerLoad(' + swfEsc(JSON.stringify(r.parent)) + ');return false;">&larr; up</a><span class="sep">' + swfEsc(r.dir) + '/</span>';
        }
        document.getElementById('picker-crumbs').innerHTML = c;
        var h = '', n = 0;
        r.folders.forEach(function (f) {
            h += '<div class="tile folder" onclick="swfPickerLoad(' + swfEsc(JSON.stringify(f.path)) + ')"><div class="thumb"><span class="ext">folder</span></div><div class="info"><div class="n">' + swfEsc(f.name) + '/</div><div class="m">' + f.count + ' item' + (f.count === 1 ? '' : 's') + '</div></div></div>';
        });
        r.files.forEach(function (f) {
            if (!showAll && !f.image) { return; }
            n++;
            var thumb = f.image ? '<img src="/' + swfEsc(f.path) + '?v=' + f.mtime + '" alt="" loading="lazy">' : '<span class="ext">' + swfEsc(f.ext) + '</span>';
            h += '<div class="tile" title="' + swfEsc(f.path) + '" onclick="swfPickerChoose(' + swfEsc(JSON.stringify(f.path)) + ', ' + swfEsc(JSON.stringify(f.name)) + ')"><div class="thumb">' + thumb + '</div><div class="info"><div class="n">' + swfEsc(f.name) + '</div></div></div>';
        });
        body.innerHTML = h ? '<div class="filegrid">' + h + '</div>' : '<div class="empty">' + (showAll ? 'Nothing in this folder yet.' : 'No images in this folder yet.') + ' Use <strong>Upload new…</strong> below.</div>';
    }, 'json').fail(function () { body.innerHTML = '<div class="empty">Could not load the folder.</div>'; });
}

function swfPickerUpload(fileList) {
    if (!fileList || !fileList.length) { return; }
    var st = document.getElementById('picker-status'); st.textContent = 'Uploading…';
    swfUploadTo(swfPickerDir, fileList, false, function (r) {
        var bad = (r.results || []).filter(function (x) { return !x.ok; });
        st.textContent = r.success ? ((r.results.length - bad.length) + ' uploaded.' + (bad.length ? ' ' + bad.map(function (x) { return x.name + ': ' + x.message; }).join(' ') : '')) : (r.message || 'Upload failed.');
        document.getElementById('picker-upload').value = '';
        // One image chosen for an image field: insert it straight away
        if (r.success && r.results.length === 1 && r.results[0].ok && swfPickerCb) { swfPickerChoose(r.results[0].path, r.results[0].name); return; }
        swfPickerLoad(swfPickerDir);
    });
}

function swfSetMode(mode) {
    if (mode === swfMode) { return; }
    var current = swfGetContent();
    var rich = jQuery('#rich'), plain = document.getElementById('plain');
    if (mode === 'rich') {
        if (!swfRichReady) {
            rich.summernote({
                placeholder: 'Start writing…',
                tabsize: 4,
                height: 420,
                toolbar: [
                    ['style',  ['style']],
                    ['font',   ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                    ['para',   ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture', 'swffiles', 'hr', 'table']],
                    ['misc',   ['codeview', 'fullscreen']]
                ],
                buttons: {
                    /*
                        "Files": insert an image that is already on the server.
                        Opens the same picker as the Image field; the chosen
                        path is inserted where the cursor was.
                    */
                    swffiles: function (context) {
                        return jQuery.summernote.ui.button({
                            className: 'swf-files-btn',
                            contents: '<i class="note-icon-picture"></i> Files',
                            tooltip: 'Insert an image already uploaded to the site',
                            // Summernote Lite positions a button's tooltip relative to this;
                            // built-in buttons get it automatically, custom ones must pass it
                            container: context.options.container,
                            click: function () {
                                context.invoke('editor.saveRange');
                                swfPickerOpen(function (path, name) {
                                    context.invoke('editor.restoreRange');
                                    context.invoke('editor.focus');
                                    context.invoke('editor.insertImage', path, name);
                                }, true);
                            }
                        }).render();
                    }
                },
                callbacks: {
                    /*
                        Summernote's default is to embed a picked image as a
                        base64 blob inside the page, which makes the file
                        huge and the image uncacheable. Upload it instead and
                        insert its path: posts get pages/posts/images/, pages
                        get pages/images/.
                    */
                    onImageUpload: function (files) {
                        var dir = SWF_UPLOAD_DIR;
                        swfStatus('Uploading image…');
                        swfUploadTo(dir, files, false, function (r) {
                            if (!r.success) { swfStatus(r.message || 'Upload failed.', 'err'); return; }
                            r.results.forEach(function (x) {
                                if (x.ok) { jQuery('#rich').summernote('insertImage', x.path, x.name); }
                                else { swfStatus(x.name + ': ' + x.message, 'err'); }
                            });
                            if (r.results.every(function (x) { return x.ok; })) { swfStatus('Image uploaded to ' + dir + '/.', 'ok'); }
                        });
                    }
                }
            });
            swfRichReady = true;
        }
        rich.summernote('code', current);
        jQuery('.note-editor').show();
        plain.style.display = 'none';
        document.getElementById('mode-label').textContent = 'Rich text. Use the </> button for raw HTML.';
    } else {
        plain.value = current;
        jQuery('.note-editor').hide();
        plain.style.display = 'block';
        document.getElementById('mode-label').textContent = 'Markdown source.';
    }
    swfMode = mode;
}

function swfGetContent() {
    if (swfMode === 'rich') { return jQuery('#rich').summernote('code'); }
    if (swfMode === 'plain') { return document.getElementById('plain').value; }
    return SWF_BODY;
}

function swfLayoutChanged() {
    swfSetMode(swfIsMarkdownLayout() ? 'plain' : 'rich');
    var isArchive = /^postarchives/.test(document.getElementById('f-pagelayout').value);
    document.getElementById('row-postcategory').classList.toggle('hidden', !isArchive);
}

/*
    Category picker. The chips are the source of truth; the hidden
    #f-pagecategory is rebuilt from them whenever anything changes so the
    save/preview code keeps sending one comma separated string, exactly as
    it would from a text box.
*/
var SWF_ARCHIVE_LAYOUT = <?php echo json_encode(isset($layoutOptions['postarchives-styled']) ? 'postarchives-styled' : 'postarchives'); ?>;
var SWF_SITE_AUTHOR    = <?php echo json_encode($siteAuthor); ?>;
var swfJustCreated     = {};   // slug -> pagename, for the "created" message

function swfTitleCase(s) { return s.replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }

function swfCatsChanged() {
    var checked = [], orphans = [], created = [];
    document.querySelectorAll('#cat-chips .chip').forEach(function (chip) {
        var cb = chip.querySelector('input');
        if (!cb.checked) { return; }
        checked.push(cb.value);
        if (chip.getAttribute('data-archive') === '') { orphans.push(cb.value); }
        else if (swfJustCreated[cb.value]) { created.push(cb.value); }
    });
    document.getElementById('f-pagecategory').value = checked.join(', ');
    var note = document.getElementById('cat-note');
    var lines = [];
    orphans.forEach(function (slug) {
        lines.push('Visitors can\'t browse <strong>' + swfEsc(slug) + '</strong> yet &mdash; there\'s no page that lists posts in it. '
                 + '<a href="#" data-slug="' + swfEsc(slug) + '" onclick="swfCreateArchive(this);return false;">Create a &ldquo;' + swfEsc(swfTitleCase(slug)) + '&rdquo; page</a>');
    });
    created.forEach(function (slug) {
        var name = swfJustCreated[slug];
        lines.push('Created <strong>/' + swfEsc(name) + '</strong> &mdash; it lists every post tagged <em>' + swfEsc(slug) + '</em>. '
                 + '<a href="' + SWF_EDITOR + '?page=' + encodeURIComponent(name) + '" target="_blank" rel="noopener">Edit that page</a> if you want to change its title or text.');
    });
    note.innerHTML = lines.join('<br>');
}

/*
    Create the category page right here, without leaving the post. The page
    is the same one the New page form would have made from ?new=page&archive=…
    — a styled archive filtered on the category — so there is nothing for the
    user to decide, and no reason to send them to a different form.
*/
function swfCreateArchive(link) {
    var slug = link.getAttribute('data-slug');
    var name = slug.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    link.textContent = 'Creating…';
    jQuery.post(SWF_SAVE, {
        action: 'new', csrf_token: SWF_CSRF,
        pagename: name,
        pagetitle: swfTitleCase(slug),
        pagelayout: SWF_ARCHIVE_LAYOUT,
        pagetype: 'website',
        pagedate: '', pageauthor: SWF_SITE_AUTHOR, pageimage: '',
        pageexcerpt: 'All posts in ' + slug + '.',
        pagekeywords: slug,
        postcategory: slug,
        content: ''
    }, function (r) {
        if (!r.success) { swfStatus(r.message, 'err'); swfCatsChanged(); return; }
        swfJustCreated[slug] = r.pagename;
        document.querySelectorAll('#cat-chips .chip').forEach(function (chip) {
            if (chip.querySelector('input').value === slug) { chip.setAttribute('data-archive', r.pagename); chip.classList.remove('orphan'); }
        });
        swfCatsChanged();
    }, 'json').fail(function () { swfStatus('Could not create the page.', 'err'); swfCatsChanged(); });
}

function swfCatAdd() {
    var input = document.getElementById('cat-new');
    var slug = input.value.toLowerCase().replace(/,/g, ' ').replace(/\s+/g, ' ').trim();
    if (!slug) { return; }
    var existing = null;
    document.querySelectorAll('#cat-chips .chip input').forEach(function (cb) { if (cb.value === slug) { existing = cb; } });
    if (existing) { existing.checked = true; }
    else {
        var chip = document.createElement('label');
        chip.className = 'chip orphan'; chip.setAttribute('data-archive', '');
        chip.innerHTML = '<input type="checkbox" checked onchange="swfCatsChanged()"> <span class="n">0</span>';
        chip.querySelector('input').value = slug;
        chip.insertBefore(document.createTextNode(slug + ' '), chip.querySelector('.n'));
        document.getElementById('cat-chips').insertBefore(chip, document.querySelector('#cat-chips .add'));
    }
    input.value = '';
    swfCatsChanged();
}

function swfFilterChanged() {
    var sel = document.getElementById('f-postcategory-select');
    var other = document.getElementById('f-postcategory-other');
    var isOther = sel.value === '__other';
    other.style.display = isOther ? '' : 'none';
    document.getElementById('f-postcategory').value = isOther ? other.value.trim() : sel.value;
}

function swfFields() {
    var f = {
        csrf_token:   SWF_CSRF,
        // For a new post the field holds only the post's own name; posts/ is fixed in front of it
        pagename:     SWF_IS_NEW ? (function () { var v = document.getElementById('f-pagename').value.trim().replace(/^\/+/, ''); return v === '' ? '' : SWF_ADDRESS_PREFIX + v; })() : SWF_PAGENAME,
        pagetitle:    document.getElementById('f-pagetitle').value,
        pagelayout:   document.getElementById('f-pagelayout').value,
        pagetype:     document.getElementById('f-pagetype').value,
        pagedate:     document.getElementById('f-pagedate').value,
        pageauthor:   document.getElementById('f-pageauthor').value,
        pageimage:    document.getElementById('f-pageimage').value,
        pageexcerpt:  document.getElementById('f-pageexcerpt').value,
        pagekeywords: document.getElementById('f-pagekeywords').value,
        pagecategory: document.getElementById('f-pagecategory').value,
        postcategory: document.getElementById('f-postcategory').value,
        content:      swfGetContent()
    };
    if (SWF_IS_NEW) { f.is_new = 1; if (SWF_ADDRESS_PREFIX) { f.is_post = 1; } }
    return f;
}

function swfStatus(msg, cls) {
    var el = document.getElementById('status');
    el.textContent = msg;
    el.className = 'status ' + (cls || '');
}

function swfSave() {
    var f = swfFields();
    if (SWF_IS_NEW && !f.pagename && !f.pagetitle.trim()) { swfStatus('Give the page a title or an address first.', 'err'); return; }
    f.action = SWF_IS_NEW ? 'new' : 'save';
    swfStatus('Saving…');
    jQuery.post(SWF_SAVE, f, function (r) {
        if (!r.success) {
            swfStatus(r.message, 'err');
            if (r.existing) {
                // Offer to open the page that is in the way
                var el = document.getElementById('status');
                var a = document.createElement('a'); a.href = SWF_EDITOR + '?page=' + encodeURIComponent(r.existing); a.textContent = 'Open it'; a.style.marginLeft = '8px';
                el.appendChild(document.createTextNode(' ')); el.appendChild(a);
            }
            return;
        }
        if (SWF_IS_NEW) {
            // Reopen the page in edit mode so the next save is a plain save
            window.location.href = SWF_EDITOR + '?page=' + encodeURIComponent(r.pagename);
            return;
        }
        swfStatus(r.message || 'Saved.', 'ok');
        swfSavedSnapshot = JSON.stringify(swfFields());
        if (document.getElementById('preview').classList.contains('on')) { swfPreview(); }
    }, 'json').fail(function (x) {
        var msg = 'Save failed.';
        try { msg = JSON.parse(x.responseText).message || msg; } catch (e) {}
        swfStatus(msg, 'err');
    });
}

function swfPreview() {
    var f = swfFields();
    f.action = 'preview';
    if (SWF_IS_NEW && !f.pagename && !f.pagetitle.trim()) { swfStatus('Give the page a title or an address before previewing.', 'err'); return; }
    swfStatus('Rendering preview…');
    jQuery.post(SWF_SAVE, f, function (r) {
        if (!r.success) { swfStatus(r.message, 'err'); return; }
        document.getElementById('preview').classList.add('on');
        document.getElementById('editor-grid').classList.add('previewing');
        document.getElementById('preview-frame').src = r.url;
        swfStatus('');
    }, 'json').fail(function () { swfStatus('Preview failed.', 'err'); });
}

function swfPreviewClose() {
    document.getElementById('preview').classList.remove('on');
    document.getElementById('editor-grid').classList.remove('previewing');
    document.getElementById('preview-frame').src = 'about:blank';
}

// Warn before leaving with unsaved changes
var swfSavedSnapshot = null;
window.addEventListener('beforeunload', function (e) {
    if (swfSavedSnapshot !== null && JSON.stringify(swfFields()) !== swfSavedSnapshot) {
        e.preventDefault(); e.returnValue = '';
    }
});

jQuery(function () {
    swfSetMode(swfIsMarkdownLayout() ? 'plain' : 'rich');
    swfCatsChanged();
    swfFilterChanged();
    swfSavedSnapshot = JSON.stringify(swfFields());
});
<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>

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

// ── Gate 1: editor switched off → behave exactly like any unknown URL ───────
if (!isset($editorEnabled) || $editorEnabled !== true) {
    header('Location: /404');
    exit;
}

require_once __DIR__ . '/editor-auth.php';

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
            'ts'     => $info['meta']['pagedate'] !== '' ? (strtotime($info['meta']['pagedate']) ?: $info['mtime']) : $info['mtime'],
        );
        if (strpos($name, 'posts/') === 0) { $posts[] = $entry; } else { $pages[] = $entry; }
    }
    usort($pages, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    usort($posts, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return array('pages' => $pages, 'posts' => $posts);
}

// ── Which view are we showing? ─────────────────────────────────────────────
$view     = 'list';
$editName = '';
$newKind  = '';
if ($isAuthed) {
    if (isset($_GET['totp']) && $_GET['totp'] === 'setup') {
        $view = 'totp';
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
    } else {
        $page = swf_read_page($editName);
        if ($page['meta']['pageauthor'] === '') { $page['meta']['pageauthor'] = $siteAuthor; }
    }
    $isPost = $isNew ? ($newKind === 'post') : (strpos($editName, 'posts/') === 0);
    $layoutOptions = swf_layout_options($themeName, $page['meta']['pagelayout']);
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
.meta label { display: block; font-size: 0.82rem; color: var(--muted); margin-bottom: 4px; }
.meta label small { color: #999; }
.meta input, .meta select { width: 100%; padding: 7px 10px; border: 1px solid var(--line); border-radius: 4px; font: inherit; font-size: 0.92rem; background: var(--panel); }
.meta .hidden { display: none; }
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
        <span class="spacer"></span>
        <a class="quiet" href="/" target="_blank" rel="noopener">View site &#8599;</a>
        <a class="quiet" href="<?php echo swf_e(swf_editor_url(['totp' => 'setup'])); ?>"><?php echo $totpOn ? 'Two-factor: on' : 'Two-factor: off'; ?></a>
        <button class="quiet" type="button" onclick="swfLogout()">Log out</button>
    </div>

    <?php if ($view === 'totp'): ?>
        <?php require_once __DIR__ . '/editor-totp-setup.php'; ?>
        <div class="wrap totp-wrap">
            <?php echo editor_totp_setup_panel(swf_editor_url(), $siteTitle, $WebsiteURL ?? '', swf_editor_url()); ?>
        </div>

    <?php elseif ($view === 'list'): ?>
        <?php $lists = swf_list_pages(); ?>
        <div class="wrap">
            <?php if (!empty($notFound)): ?>
                <div class="notice err">No page called <code><?php echo swf_e($notFound); ?></code> exists.</div>
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
                                <div class="m"><code>/<?php echo swf_e($p['name']); ?></code><?php if ($p['layout'] !== ''): ?><span><?php echo swf_e($p['layout']); ?></span><?php endif; ?></div>
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

            <div class="editor-grid" id="editor-grid">
            <div>
                <div class="meta">
                    <?php if ($isNew): ?>
                    <div class="full">
                        <label>Address <small>(lowercase letters, numbers and hyphens; use / for folders)</small></label>
                        <input type="text" id="f-pagename" placeholder="<?php echo $isPost ? 'posts/my-first-post' : 'about-us  or  docs/getting-started'; ?>" value="<?php echo $isPost ? 'posts/' : ''; ?>" autofocus>
                    </div>
                    <?php endif; ?>
                    <div class="full">
                        <label>Title</label>
                        <input type="text" id="f-pagetitle" value="<?php echo swf_e($m['pagetitle']); ?>">
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
                        <input type="text" id="f-pageimage" value="<?php echo swf_e($m['pageimage']); ?>" placeholder="pages/posts/images/my-image.webp">
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
                        <label>Categories <small>(comma separated; leave blank for uncategorized)</small></label>
                        <input type="text" id="f-pagecategory" value="<?php echo swf_e($m['pagecategory']); ?>">
                    </div>
                    <div class="full<?php echo strpos($m['pagelayout'], 'postarchives') === 0 ? '' : ' hidden'; ?>" id="row-postcategory">
                        <label>Archive filter <small>(only list posts in this category; blank lists everything)</small></label>
                        <input type="text" id="f-postcategory" value="<?php echo swf_e($m['postcategory']); ?>">
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
                    <?php if (!$isNew): ?><a class="btn secondary" href="/<?php echo swf_e($editName); ?>" target="_blank" rel="noopener">Open live &#8599;</a><?php endif; ?>
                    <span class="status" id="status"></span>
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

function swfLogout() {
    jQuery.post(SWF_SAVE, { action: 'logout', csrf_token: SWF_CSRF }).always(function () {
        window.location.href = SWF_EDITOR;
    });
}

<?php if ($view === 'form'): ?>
var SWF_IS_NEW   = <?php echo $isNew ? 'true' : 'false'; ?>;
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
                    ['insert', ['link', 'picture', 'hr', 'table']],
                    ['misc',   ['codeview', 'fullscreen']]
                ]
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

function swfFields() {
    var f = {
        csrf_token:   SWF_CSRF,
        pagename:     SWF_IS_NEW ? document.getElementById('f-pagename').value.trim() : SWF_PAGENAME,
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
    if (SWF_IS_NEW) { f.is_new = 1; }
    return f;
}

function swfStatus(msg, cls) {
    var el = document.getElementById('status');
    el.textContent = msg;
    el.className = 'status ' + (cls || '');
}

function swfSave() {
    var f = swfFields();
    if (SWF_IS_NEW && !f.pagename) { swfStatus('Enter an address for the page first.', 'err'); return; }
    f.action = SWF_IS_NEW ? 'new' : 'save';
    swfStatus('Saving…');
    jQuery.post(SWF_SAVE, f, function (r) {
        if (!r.success) { swfStatus(r.message, 'err'); return; }
        if (SWF_IS_NEW) {
            // Reopen the page in edit mode so the next save is a plain save
            window.location.href = SWF_EDITOR + '?page=' + encodeURIComponent(r.pagename);
            return;
        }
        swfStatus('Saved.', 'ok');
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
    if (SWF_IS_NEW && !f.pagename) { swfStatus('Enter an address for the page before previewing.', 'err'); return; }
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
    swfSavedSnapshot = JSON.stringify(swfFields());
});
<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>

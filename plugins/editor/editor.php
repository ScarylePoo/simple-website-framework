<?php
/*
    editor.php
    Page editor plugin for Simple Website Framework.
    Included by plugins/plugins.php when $editorEnabled is true.

    Activates when ?editor or ?editor=yourtoken is present in the URL.

    Add to config.php:
        $editorEnabled      = true;
        $editorToken        = 'your-random-secret-token'; // optional but recommended
        $editorPasswordHash = password_hash('yourpassword', PASSWORD_BCRYPT);
        $editorSessionTimeout = 1800; // seconds (default 30 min)
*/

if (!isset($editorEnabled) || $editorEnabled !== true) {
    return;
}

if (!array_key_exists('editor', $_GET)) {
    return;
}

define('SWF_EDITOR', true);
require_once 'plugins/editor/editor-auth.php';

// Refuse to run if config is incomplete — prevents silent failures
if (empty($editorToken) || empty($editorPasswordHash)) {
    $pluginCalledBelowContent .= '
    <div style="margin-top:60px;border-top:2px solid #c00;padding:20px;background:#fdecea;font-family:system-ui,sans-serif;color:#8b0000;">
        <strong>Editor configuration incomplete.</strong>
        Set <code>$editorToken</code> and <code>$editorPasswordHash</code> in <code>config/config.php</code> before using the editor.
    </div>';
    return;
}

// Check URL token — returns silently if token is configured and doesn't match
if (!editor_check_url_token()) {
    // Silently do nothing — don't reveal the editor exists
    return;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swf_editor_password'])) {
    // Drain any buffered output BEFORE authenticating. editor.php runs partway
    // through header.php, so by this point <head> content may already be
    // sitting in the output buffer. editor_authenticate() needs a clean slate
    // to send its own Set-Cookie header (session_regenerate_id), and the
    // header('Location: ...') redirect below needs the same.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    editor_authenticate($_POST['swf_editor_password']);
    $redirect = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(['editor' => $_GET['editor'] ?? '']);

    if (!headers_sent()) {
        header('Location: ' . $redirect);
        exit;
    }

    // Fallback if headers were genuinely already sent (e.g. output_buffering
    // is Off at the server level) — JS/meta-refresh instead of a blank page.
    echo '<script>window.location.href = ' . json_encode($redirect) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect) . '"></noscript>';
    exit;
}

$isAuthed = editor_is_authenticated();
$isLocked = editor_is_locked_out();
$csrfToken = $isAuthed ? editor_get_csrf_token() : '';

// Read and parse the current page metadata + content when authenticated
$pageMeta       = [];
$initialContent = '';

if ($isAuthed) {
    $pageFile   = 'pages/' . $pagename . '.html';
    $rawContent = file_exists($pageFile) ? file_get_contents($pageFile) : '';
    $lines      = explode("\n", str_replace("\r\n", "\n", $rawContent));

    $metaPatterns = [
        'pagetitle'    => '/<!--\s*pagetitle\s*:\s*(.*?)\s*-->/',
        'pagelayout'   => '/<!--\s*pagelayout\s*:\s*(.*?)\s*-->/',
        'pagedate'     => '/<!--\s*pagedate\s*:\s*(.*?)\s*-->/',
        'pageimage'    => '/<!--\s*pageimage\s*:\s*(.*?)\s*-->/',
        'pageexcerpt'  => '/<!--\s*pageexcerpt\s*:\s*(.*?)\s*-->/',
        'pagekeywords' => '/<!--\s*pagekeywords\s*:\s*(.*?)\s*-->/',
        'pageauthor'   => '/<!--\s*pageauthor\s*:\s*(.*?)\s*-->/',
        'pagetype'     => '/<!--\s*pagetype\s*:\s*(.*?)\s*-->/',
    ];

    foreach ($metaPatterns as $key => $pattern) {
        $pageMeta[$key] = preg_match($pattern, $rawContent, $m) ? trim($m[1]) : '';
    }

    $pastMeta     = false;
    $contentLines = [];
    foreach ($lines as $line) {
        if (!$pastMeta && preg_match('/^\s*<!--/', $line)) { continue; }
        $pastMeta       = true;
        $contentLines[] = $line;
    }
    $initialContent = trim(implode("\n", $contentLines));
}

function swf_select($id, $options, $selected) {
    echo '<select id="' . $id . '" style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:0.9rem;box-sizing:border-box;">';
    foreach ($options as $val => $label) {
        $sel = ($selected === $val) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    echo '</select>';
}

$layoutOptions = [
    'page-html'           => 'page-html (HTML with title)',
    'page-html-notitle'   => 'page-html-notitle (HTML, no title)',
    'page-md'             => 'page-md (Markdown with title)',
    'page-md-notitle'     => 'page-md-notitle (Markdown, no title)',
    'postarchives-styled' => 'postarchives-styled',
];

$typeOptions = [
    'website' => 'website',
    'article' => 'article',
    'blog'    => 'blog',
    'profile' => 'profile',
    'video'   => 'video',
    'music'   => 'music',
    'book'    => 'book',
    'product' => 'product',
];

$fs = 'width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:0.9rem;box-sizing:border-box;';
$ls = 'display:block;font-size:0.85rem;color:#555;margin-bottom:4px;';

ob_start();
?>

<div id="swf-editor-container" style="margin-top:60px;border-top:2px solid #333;padding:30px 20px;background:#f9f9f9;font-family:system-ui,sans-serif;">

<?php if (!$isAuthed): ?>

    <div id="swf-editor-auth" style="max-width:400px;margin:0 auto;text-align:center;">
        <h2 style="font-size:1.2rem;margin-bottom:20px;color:#111;">
            <?php echo $isLocked ? 'Too many failed attempts. Try again in 15 minutes.' : 'Editor — enter password to continue'; ?>
        </h2>
        <?php if (!$isLocked): ?>
        <form method="POST" style="display:flex;gap:10px;justify-content:center;">
            <input type="password" name="swf_editor_password" placeholder="Password" autofocus
                style="padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:1rem;flex:1;">
            <button type="submit" style="padding:8px 18px;background:#111;color:#fff;border:none;border-radius:4px;font-size:1rem;cursor:pointer;">Enter</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div style="display:flex;gap:6px;margin-bottom:20px;align-items:center;">
        <button onclick="swfShowTab('edit')" id="swf-tab-edit" style="padding:7px 18px;border-radius:4px;border:1px solid #333;background:#111;color:#fff;cursor:pointer;font-size:0.9rem;">Edit this page</button>
        <button onclick="swfShowTab('new')"  id="swf-tab-new"  style="padding:7px 18px;border-radius:4px;border:1px solid #ccc;background:#fff;color:#333;cursor:pointer;font-size:0.9rem;">New page</button>
        <span style="flex:1;"></span>
        <button onclick="swfLogout()" style="padding:7px 14px;border-radius:4px;border:1px solid #ccc;background:#fff;color:#888;cursor:pointer;font-size:0.85rem;">Log out</button>
    </div>

    <div id="swf-status" style="display:none;padding:10px 16px;border-radius:4px;margin-bottom:16px;font-size:0.9rem;"></div>

    <!-- ── EDIT TAB ── -->
    <div id="swf-panel-edit">
        <p style="color:#555;font-size:0.9rem;margin-bottom:20px;">
            Editing: <strong><?php echo htmlspecialchars($pagename); ?></strong>
            &nbsp;·&nbsp; <code>pages/<?php echo htmlspecialchars($pagename); ?>.html</code>
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
            <div>
                <label style="<?php echo $ls; ?>">Page title</label>
                <input type="text" id="swf-edit-pagetitle" value="<?php echo htmlspecialchars($pageMeta['pagetitle']); ?>" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Layout</label>
                <?php swf_select('swf-edit-pagelayout', $layoutOptions, $pageMeta['pagelayout']); ?>
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Page type</label>
                <?php swf_select('swf-edit-pagetype', $typeOptions, $pageMeta['pagetype']); ?>
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Date</label>
                <input type="text" id="swf-edit-pagedate" value="<?php echo htmlspecialchars($pageMeta['pagedate']); ?>" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Author</label>
                <input type="text" id="swf-edit-pageauthor" value="<?php echo htmlspecialchars($pageMeta['pageauthor'] ?: $WebsiteAuthor); ?>" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Image path <small style="color:#888;">(relative to site root)</small></label>
                <input type="text" id="swf-edit-pageimage" value="<?php echo htmlspecialchars($pageMeta['pageimage']); ?>" style="<?php echo $fs; ?>">
            </div>
            <div style="grid-column:span 2;">
                <label style="<?php echo $ls; ?>">Excerpt <small style="color:#888;">(~160 chars)</small></label>
                <input type="text" id="swf-edit-pageexcerpt" value="<?php echo htmlspecialchars($pageMeta['pageexcerpt']); ?>" style="<?php echo $fs; ?>">
            </div>
            <div style="grid-column:span 2;">
                <label style="<?php echo $ls; ?>">Keywords <small style="color:#888;">(comma separated)</small></label>
                <input type="text" id="swf-edit-pagekeywords" value="<?php echo htmlspecialchars($pageMeta['pagekeywords']); ?>" style="<?php echo $fs; ?>">
            </div>
        </div>
        <label style="<?php echo $ls; ?>margin-bottom:8px;">Content</label>
        <div id="swf-editor-edit"></div>
        <div style="margin-top:16px;display:flex;gap:10px;">
            <button onclick="swfSave()" style="padding:9px 24px;background:#111;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.95rem;">Save page</button>
            <a href="<?php echo htmlspecialchars($pagename); ?>" style="padding:9px 18px;border:1px solid #ccc;border-radius:4px;color:#555;text-decoration:none;font-size:0.9rem;line-height:1.8;">View page</a>
        </div>
    </div>

    <!-- ── NEW PAGE TAB ── -->
    <div id="swf-panel-new" style="display:none;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
            <div>
                <label style="<?php echo $ls; ?>">Page name / path <span style="color:#c00;">*</span></label>
                <input type="text" id="swf-new-pagename" placeholder="e.g. about  or  posts/my-post" style="<?php echo $fs; ?>">
                <small style="color:#888;">Letters, numbers, hyphens, underscores, forward slashes. No extension.</small>
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Page title</label>
                <input type="text" id="swf-new-pagetitle" placeholder="My New Page" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Layout</label>
                <?php swf_select('swf-new-pagelayout', $layoutOptions, 'page-html'); ?>
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Page type</label>
                <?php swf_select('swf-new-pagetype', $typeOptions, 'website'); ?>
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Date</label>
                <input type="text" id="swf-new-pagedate" value="<?php echo date('n/j/Y'); ?>" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Author</label>
                <input type="text" id="swf-new-pageauthor" value="<?php echo htmlspecialchars($WebsiteAuthor); ?>" style="<?php echo $fs; ?>">
            </div>
            <div style="grid-column:span 2;">
                <label style="<?php echo $ls; ?>">Excerpt <small style="color:#888;">(~160 chars)</small></label>
                <input type="text" id="swf-new-pageexcerpt" placeholder="A short description of this page." style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Keywords <small style="color:#888;">(comma separated)</small></label>
                <input type="text" id="swf-new-pagekeywords" placeholder="keyword one, keyword two" style="<?php echo $fs; ?>">
            </div>
            <div>
                <label style="<?php echo $ls; ?>">Image path <small style="color:#888;">(relative to site root)</small></label>
                <input type="text" id="swf-new-pageimage" placeholder="pages/images/my-image.webp" style="<?php echo $fs; ?>">
            </div>
        </div>
        <label style="<?php echo $ls; ?>margin-bottom:8px;">Content</label>
        <div id="swf-editor-new"></div>
        <div style="margin-top:16px;">
            <button onclick="swfCreatePage()" style="padding:9px 24px;background:#111;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.95rem;">Create page</button>
        </div>
    </div>

<?php endif; ?>

</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css" rel="stylesheet">

<?php
$editorScript = ob_get_clean();
$pluginCalledBelowContent = $pluginCalledBelowContent . $editorScript;

ob_start();
if ($isAuthed):
    $initialContentJson = json_encode($initialContent);
    $pagenameJson       = json_encode($pagename);
    $csrfJson           = json_encode($csrfToken);
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>
<script>
jQuery(document).ready(function($) {
    var cfg = {
        placeholder: 'Start writing...',
        tabsize: 4,
        height: 400,
        toolbar: [
            ['style',  ['style']],
            ['font',   ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['para',   ['ul', 'ol', 'paragraph']],
            ['insert', ['link', 'picture', 'hr', 'table']],
            ['misc',   ['codeview', 'fullscreen']]
        ]
    };
    $('#swf-editor-edit').summernote($.extend({}, cfg));
    $('#swf-editor-edit').summernote('code', <?php echo $initialContentJson; ?>);
    $('#swf-editor-new').summernote($.extend({}, cfg));

    // Scroll to the editor after Summernote has finished rendering
    setTimeout(function() {
        var editor = document.getElementById('swf-editor-container');
        if (editor) {
            editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 150);
});

var SWF_CSRF = <?php echo $csrfJson; ?>;

function swfShowTab(tab) {
    document.getElementById('swf-panel-edit').style.display = tab === 'edit' ? 'block' : 'none';
    document.getElementById('swf-panel-new').style.display  = tab === 'new'  ? 'block' : 'none';
    document.getElementById('swf-tab-edit').style.background = tab === 'edit' ? '#111' : '#fff';
    document.getElementById('swf-tab-edit').style.color      = tab === 'edit' ? '#fff' : '#333';
    document.getElementById('swf-tab-new').style.background  = tab === 'new'  ? '#111' : '#fff';
    document.getElementById('swf-tab-new').style.color       = tab === 'new'  ? '#fff' : '#333';
}

function swfStatus(msg, isError) {
    var el = document.getElementById('swf-status');
    el.textContent   = msg;
    el.style.display = 'block';
    el.style.background = isError ? '#fdecea' : '#eaf7ea';
    el.style.color      = isError ? '#8b0000' : '#1a5c1a';
    el.style.border     = isError ? '1px solid #f5c6c6' : '1px solid #b2dfb2';
}

function swfSave() {
    jQuery.post('plugins/editor/editor-save.php', {
        action:        'save',
        csrf_token:    SWF_CSRF,
        pagename:      <?php echo $pagenameJson; ?>,
        pagetitle:     document.getElementById('swf-edit-pagetitle').value,
        pagelayout:    document.getElementById('swf-edit-pagelayout').value,
        pagetype:      document.getElementById('swf-edit-pagetype').value,
        pagedate:      document.getElementById('swf-edit-pagedate').value,
        pageauthor:    document.getElementById('swf-edit-pageauthor').value,
        pageimage:     document.getElementById('swf-edit-pageimage').value,
        pageexcerpt:   document.getElementById('swf-edit-pageexcerpt').value,
        pagekeywords:  document.getElementById('swf-edit-pagekeywords').value,
        content:       jQuery('#swf-editor-edit').summernote('code')
    }, function(r) {
        swfStatus(r.success ? 'Saved successfully.' : 'Error: ' + r.message, !r.success);
    }, 'json').fail(function() { swfStatus('Save failed — server error.', true); });
}

function swfCreatePage() {
    var pagename = document.getElementById('swf-new-pagename').value.trim();
    if (!pagename) { swfStatus('Page name is required.', true); swfShowTab('new'); return; }
    jQuery.post('plugins/editor/editor-save.php', {
        action:       'new',
        csrf_token:   SWF_CSRF,
        pagename:     pagename,
        pagetitle:    document.getElementById('swf-new-pagetitle').value,
        pagelayout:   document.getElementById('swf-new-pagelayout').value,
        pagetype:     document.getElementById('swf-new-pagetype').value,
        pagedate:     document.getElementById('swf-new-pagedate').value,
        pageauthor:   document.getElementById('swf-new-pageauthor').value,
        pageexcerpt:  document.getElementById('swf-new-pageexcerpt').value,
        pagekeywords: document.getElementById('swf-new-pagekeywords').value,
        pageimage:    document.getElementById('swf-new-pageimage').value,
        content:      jQuery('#swf-editor-new').summernote('code')
    }, function(r) {
        if (r.success) {
            swfStatus('Page created! Opening...', false);
            setTimeout(function() { window.location.href = r.url + '?editor'; }, 1200);
        } else {
            swfStatus('Error: ' + r.message, true);
            swfShowTab('new');
        }
    }, 'json').fail(function() { swfStatus('Create failed — server error.', true); });
}

function swfLogout() {
    jQuery.post('plugins/editor/editor-save.php', { action: 'logout', csrf_token: SWF_CSRF }, function() {
        window.location.reload();
    }, 'json');
}
</script>
<?php endif; ?>
<?php
$jsBlock = ob_get_clean();
$pluginCalledBelowContent = $pluginCalledBelowContent . $jsBlock;

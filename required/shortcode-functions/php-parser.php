<?php
/*
    [php] ... [/php] — run PHP code written inside a page.

    OFF unless $allowPhpShortcode = true in config/config.php.

    This shortcode turns "can write page content" into "can run code on the
    server", so it is a liability on any site where page content could be
    changed by someone other than its owner — through the browser editor,
    a shared FTP login, or a bug in a theme. With it off, a [php] block in a
    page renders as nothing but an HTML comment. The editor also refuses to
    save content containing [php] while it is off, so that a mistake or an
    injected save cannot park code in a page to wait for the flag.

    Turn it on only on a site whose pages are edited exclusively by you, and
    turn it off again when you no longer need it.
*/
function shortcode_php($attributes, $content) {
    if (empty($GLOBALS['allowPhpShortcode'])) {
        return '<!-- [php] shortcode is disabled; set $allowPhpShortcode = true in config/config.php to use it -->';
    }

    if (empty($content)) {
        return '<p>Error: No PHP code provided.</p>';
    }

    ob_start();
    eval($content);
    return ob_get_clean();
}
?>

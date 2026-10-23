<?php
/*
    The framework's version. This is the one place it is recorded.

    Bump it as part of cutting a release: the release workflow refuses to
    build a tag whose number does not match this constant, so the version a
    site reports and the version on the release page can never disagree.

    It is shown only where the site owner looks — the editor, the ?meta=yes
    overlay, and backup-cli.php — never in a public meta tag or file, so a
    scanner cannot read a site's version off the front page.
*/
if (!defined('SWF_VERSION')) {
    define('SWF_VERSION', '1.1.5'); //v1.1.5 -- Leave this comment here for easy copy paste and a reminder of what to put in github.
}

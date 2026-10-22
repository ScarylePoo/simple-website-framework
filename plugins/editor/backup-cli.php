<?php
/*
    backup-cli.php
    Make a site backup from the command line, for cron.

        php plugins/editor/backup-cli.php            content backup
        php plugins/editor/backup-cli.php full       full-site backup
        php plugins/editor/backup-cli.php list       show what is stored
        php plugins/editor/backup-cli.php version    print the framework version
        php plugins/editor/backup-cli.php check-update
                                                     ask GitHub whether a newer version exists
                                                     (exit 0 up to date, 10 update available, 1 no answer)

    Archives go to the backup directory ($backupDir in config.php, default
    backups/) and are rotated: the newest $backupKeepArchives of each kind
    made by cron or the weekly automatic run are kept; ones you made by hand
    in the editor are never removed automatically.

    check-update is cron-friendly too — a weekly email when a release is out:

        0 9 * * 1 php /path/to/site/plugins/editor/backup-cli.php check-update | grep -q available && echo "SWF update" | mail -s "SWF update available" you@example.com

    A nightly content backup, kept for a week, is one crontab line:

        15 3 * * * php /path/to/your/site/plugins/editor/backup-cli.php content

    Most hosting control panels have a "cron jobs" page that takes the same
    command. Run it from the site root or anywhere; the script finds the
    site from its own location.

    This file only runs from the command line. Requested over the web it
    answers 404, so leaving it in place is safe.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SWF_EDITOR', true);
define('SWF_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/../../')) . '/');

require_once SWF_ROOT . 'required/version.php';

$cmd = strtolower($argv[1] ?? 'content');

if ($cmd === 'version') {
    echo "Simple Website Framework " . SWF_VERSION . "\n";
    exit(0);
}

if (!file_exists(SWF_ROOT . 'config/config.php')) {
    fwrite(STDERR, "config/config.php not found. Copy config-example.php to config.php first.\n");
    exit(1);
}
require_once SWF_ROOT . 'config/config.php';
require_once __DIR__ . '/editor-backup.php';

if ($cmd === 'check-update') {
    require_once __DIR__ . '/editor-update.php';
    if (!update_check_enabled()) { echo "Update checks are off (\$editorUpdateCheck = false in config.php).\n"; exit(1); }
    $state = update_check(true);
    if (empty($state['latest']['version']) || !empty($state['failed'])) {
        fwrite(STDERR, "Could not reach GitHub. Releases: " . SWF_UPDATE_RELEASES . "\n");
        exit(1);
    }
    $latest = $state['latest'];
    echo "This site:      " . SWF_VERSION . "\n";
    echo "Newest release: " . $latest['version'] . ($latest['published'] ? '  (' . date('Y-m-d', $latest['published']) . ')' : '') . "\n";
    if (version_compare($latest['version'], SWF_VERSION, '>')) {
        echo "Update available: " . $latest['url'] . "\n";
        if ($latest['zip'] !== '') { echo "Upgrade zip:      " . $latest['zip'] . "\n"; }
        exit(10);
    }
    echo "Up to date.\n";
    exit(0);
}

if ($cmd === 'list') {
    echo "Simple Website Framework " . SWF_VERSION . "\n";
    $list = backup_list_archives();
    if (!$list) { echo "No archives in " . backup_dir_label() . "\n"; exit(0); }
    foreach ($list as $a) {
        printf("%-48s %8s  %s\n", $a['name'], backup_fmt_size($a['size']), date('Y-m-d H:i', $a['time']));
    }
    exit(0);
}

if (!in_array($cmd, array('content', 'full'), true)) {
    fwrite(STDERR, "Usage: php backup-cli.php [content|full|list|version]\n");
    exit(2);
}

$r = backup_make_archive($cmd, 'cron');
if (!is_array($r)) {
    fwrite(STDERR, "Backup failed: " . $r . "\n");
    exit(1);
}
echo "Wrote " . $r['file'] . " (" . $r['count'] . " files, " . backup_fmt_size($r['size']) . ")\n";
exit(0);

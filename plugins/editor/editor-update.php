<?php
/*
    editor-update.php
    Tells the site owner when a newer framework version has been released.

    The source is the GitHub release the framework is published from: GitHub
    exposes the newest one as JSON at
        https://api.github.com/repos/ScarylePoo/simple-website-framework/releases/latest
    so there is nothing to run or maintain on the framework's side — making a
    release IS the announcement.

    When it runs: once a day at most, and only when someone who has logged in
    opens the editor (or runs backup-cli.php check-update). The result is kept
    in plugins/editor/update-check.json, which the editor's .htaccess already
    refuses to serve, so a site nobody edits never contacts anyone, and a
    site that is edited contacts GitHub once a day. If the request fails —
    the host blocks outbound HTTP, GitHub is down — nothing is shown and the
    next try is an hour later.

    Off switch: $editorUpdateCheck = false in config/config.php.

    What it does NOT do is install anything. The notice links to the release
    page and the upgrade zip; the upgrade itself is the usual unzip-over-
    the-site. Letting the editor overwrite required/ and index.php from the
    network would turn any editor bug into "replace the framework", so it
    is deliberately notify-only.
*/

if (!defined('SWF_VERSION')) { require_once dirname(__DIR__, 2) . '/required/version.php'; }

const SWF_UPDATE_API      = 'https://api.github.com/repos/ScarylePoo/simple-website-framework/releases/latest';
const SWF_UPDATE_RELEASES = 'https://github.com/ScarylePoo/simple-website-framework/releases';
const SWF_UPDATE_INTERVAL = 86400;   // a day between successful checks
const SWF_UPDATE_RETRY    = 3600;    // an hour before retrying a failed one

function update_check_enabled() {
    return !isset($GLOBALS['editorUpdateCheck']) || $GLOBALS['editorUpdateCheck'] !== false;
}

function update_state_file() {
    return __DIR__ . '/update-check.json';
}

function update_read_state() {
    $f = update_state_file();
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function update_write_state($state) {
    @file_put_contents(update_state_file(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/*
    Fetch a URL with a short timeout. curl when PHP has it, otherwise the
    stream wrapper (allow_url_fopen). GitHub's API insists on a User-Agent.
    Returns the body, or null.
*/
function update_http_get($url) {
    $ua = 'SimpleWebsiteFramework/' . SWF_VERSION . ' (update check)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => array('Accept: application/vnd.github+json'),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : null;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 5, 'ignore_errors' => true,
            'header'  => "User-Agent: $ua\r\nAccept: application/vnd.github+json\r\n",
        )));
        $body = @file_get_contents($url, false, $ctx);
        $ok = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
        return ($body !== false && $ok) ? $body : null;
    }
    return null;
}

// Ask GitHub for the newest release. Returns the parts we keep, or null.
function update_fetch_latest() {
    $body = update_http_get(SWF_UPDATE_API);
    if ($body === null) { return null; }
    $r = json_decode($body, true);
    if (!is_array($r) || empty($r['tag_name'])) { return null; }

    $version = ltrim((string) $r['tag_name'], 'vV');
    if (!preg_match('/^\d+(\.\d+){1,3}$/', $version)) { return null; }   // only a plain x.y.z tag counts

    $upgradeZip = '';
    foreach ((array) ($r['assets'] ?? array()) as $a) {
        if (!empty($a['name']) && strpos($a['name'], 'swf-upgrade-') === 0 && !empty($a['browser_download_url'])) {
            $upgradeZip = (string) $a['browser_download_url'];
        }
    }

    // A short plain-text version of the notes for the banner. The release
    // page has the real thing.
    $notes = (string) ($r['body'] ?? '');
    $cut = strpos($notes, '**New site:**');   // the download boilerplate the release workflow appends
    if ($cut !== false) { $notes = substr($notes, 0, $cut); }
    $notes = trim(strip_tags(str_replace(array('**', '`', '#'), '', $notes)));
    $notes = preg_replace('/\s+/', ' ', $notes);
    if (strlen($notes) > 400) { $notes = substr($notes, 0, 397) . '…'; }

    return array(
        'version'   => $version,
        'url'       => (string) ($r['html_url'] ?? SWF_UPDATE_RELEASES),
        'zip'       => $upgradeZip,
        'published' => !empty($r['published_at']) ? strtotime($r['published_at']) : 0,
        'notes'     => $notes,
    );
}

/*
    The check itself. Returns the state array:
        latest     the newest release (version, url, zip, published, notes), or absent if never fetched
        checked    when the last successful fetch happened
        failed     when the last failed fetch happened
        dismissed  a version the owner chose to hide the notice for
*/
function update_check($force = false) {
    $state = update_read_state();
    if (!update_check_enabled()) { return $state; }

    $now = time();
    if (!$force) {
        if (!empty($state['checked']) && $now - $state['checked'] < SWF_UPDATE_INTERVAL) { return $state; }
        if (!empty($state['failed'])  && $now - $state['failed']  < SWF_UPDATE_RETRY)    { return $state; }
    }

    $latest = update_fetch_latest();
    if ($latest === null) {
        $state['failed'] = $now;
    } else {
        $state['latest']  = $latest;
        $state['checked'] = $now;
        unset($state['failed']);
    }
    update_write_state($state);
    return $state;
}

// The newest release if it is newer than this site and not dismissed, else null
function update_available($state = null) {
    if ($state === null) { $state = update_read_state(); }
    if (empty($state['latest']['version'])) { return null; }
    $v = $state['latest']['version'];
    if (version_compare($v, SWF_VERSION, '<=')) { return null; }
    if (!empty($state['dismissed']) && $state['dismissed'] === $v) { return null; }
    return $state['latest'];
}

function update_dismiss($version) {
    $state = update_read_state();
    $state['dismissed'] = (string) $version;
    update_write_state($state);
}

// "checked 3 hours ago", for the version chip's tooltip
function update_status_text($state) {
    if (!update_check_enabled()) { return 'Update checks are off ($editorUpdateCheck)'; }
    if (!empty($state['checked'])) {
        $age = time() - $state['checked'];
        $ago = $age < 3600 ? max(1, (int) ($age / 60)) . ' min ago' : ($age < 86400 ? (int) ($age / 3600) . ' h ago' : (int) ($age / 86400) . ' d ago');
        $up  = !empty($state['latest']['version']) && version_compare($state['latest']['version'], SWF_VERSION, '<=');
        return ($up ? 'Up to date' : 'Newer version available') . ' — checked ' . $ago . '. Click to check now.';
    }
    if (!empty($state['failed'])) { return 'Could not reach GitHub to check for updates. Click to try again.'; }
    return 'Click to check for a newer version.';
}

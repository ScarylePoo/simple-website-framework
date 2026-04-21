<?php
/*
    editor-auth.php
    Shared authentication logic for the page editor plugin.
    Included by editor.php and editor-save.php.
    Never access this file directly.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

// Suppress PHP error output — errors are logged server-side, never shown to visitors
error_reporting(0);
ini_set('display_errors', 0);

// Secure session cookie flags — must be set before session_start()
session_set_cookie_params([
    'lifetime' => 0,
    'secure'   => true,   // Cookie only sent over HTTPS
    'httponly' => true,   // Cookie inaccessible to JavaScript
    'samesite' => 'Strict'
]);

session_start();

define('SWF_EDITOR_SESSION_KEY',  'swf_editor_authed');
define('SWF_EDITOR_CSRF_KEY',     'swf_editor_csrf');
define('SWF_EDITOR_ATTEMPTS_KEY', 'swf_editor_attempts');
define('SWF_EDITOR_LOCKOUT_KEY',  'swf_editor_lockout');
define('SWF_MAX_ATTEMPTS',        5);
define('SWF_LOCKOUT_SECONDS',     900);  // 15 minutes

/*
    Brute-force lockout.
    Attempts are tracked in a flat file keyed by IP address so they
    persist across sessions and cookie resets.
*/
function editor_get_lockout_file() {
    // Store alongside editor files — never inside pages/
    return __DIR__ . '/lockouts.json';
}

function editor_get_lockout_data() {
    $file = editor_get_lockout_file();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function editor_save_lockout_data($data) {
    file_put_contents(editor_get_lockout_file(), json_encode($data));
}

function editor_get_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function editor_is_locked_out() {
    $ip   = editor_get_ip();
    $data = editor_get_lockout_data();

    if (!isset($data[$ip])) {
        return false;
    }

    $entry = $data[$ip];

    if ($entry['attempts'] < SWF_MAX_ATTEMPTS) {
        return false;
    }

    if (time() - $entry['last_attempt'] < SWF_LOCKOUT_SECONDS) {
        return true;
    }

    // Lockout expired — clear it
    unset($data[$ip]);
    editor_save_lockout_data($data);
    return false;
}

function editor_record_failed_attempt() {
    $ip   = editor_get_ip();
    $data = editor_get_lockout_data();

    if (!isset($data[$ip])) {
        $data[$ip] = ['attempts' => 0, 'last_attempt' => 0];
    }

    $data[$ip]['attempts']++;
    $data[$ip]['last_attempt'] = time();
    editor_save_lockout_data($data);
}

function editor_clear_lockout() {
    $ip   = editor_get_ip();
    $data = editor_get_lockout_data();
    unset($data[$ip]);
    editor_save_lockout_data($data);
}

/*
    CSRF token generation and validation.
    A token is generated on login and embedded as a hidden field in the editor UI.
    The save handler verifies it before processing any action.
*/
function editor_get_csrf_token() {
    if (empty($_SESSION[SWF_EDITOR_CSRF_KEY])) {
        $_SESSION[SWF_EDITOR_CSRF_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[SWF_EDITOR_CSRF_KEY];
}

function editor_verify_csrf($token) {
    if (empty($_SESSION[SWF_EDITOR_CSRF_KEY])) {
        return false;
    }
    return hash_equals($_SESSION[SWF_EDITOR_CSRF_KEY], $token);
}

/*
    Session management.
*/
function editor_is_authenticated() {
    if (!isset($_SESSION[SWF_EDITOR_SESSION_KEY])) {
        return false;
    }
    global $editorSessionTimeout;
    $timeout = $editorSessionTimeout ?? 1800;
    if (time() - $_SESSION[SWF_EDITOR_SESSION_KEY] > $timeout) {
        unset($_SESSION[SWF_EDITOR_SESSION_KEY]);
        unset($_SESSION[SWF_EDITOR_CSRF_KEY]);
        return false;
    }
    $_SESSION[SWF_EDITOR_SESSION_KEY] = time();
    return true;
}

function editor_authenticate($password) {
    global $editorPasswordHash;

    if (editor_is_locked_out()) {
        return false;
    }

    if (password_verify($password, $editorPasswordHash)) {
        // Regenerate session ID on login to prevent session fixation
        session_regenerate_id(true);
        $_SESSION[SWF_EDITOR_SESSION_KEY] = time();
        editor_clear_lockout();
        // Generate a fresh CSRF token for this session
        editor_get_csrf_token();
        return true;
    }

    editor_record_failed_attempt();
    return false;
}

function editor_logout() {
    unset($_SESSION[SWF_EDITOR_SESSION_KEY]);
    unset($_SESSION[SWF_EDITOR_CSRF_KEY]);
}

/*
    URL token check.
    Returns true if the ?editor= value matches $editorToken from config.
    If $editorToken is empty or not set, this check is skipped (backwards compatible).
*/
function editor_check_url_token() {
    global $editorToken;
    if (empty($editorToken)) {
        return true; // No token configured — allow through
    }
    $supplied = $_GET['editor'] ?? '';
    return hash_equals($editorToken, $supplied);
}

/*
    Path validation.
*/
function editor_validate_path($pagename, $siteRoot = null) {
    if ($siteRoot === null) {
        $siteRoot = str_replace('\\', '/', getcwd()) . '/';
    }

    $pagename = ltrim($pagename, '/.');

    if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $pagename)) {
        return false;
    }

    if (strpos($pagename, '..') !== false) {
        return false;
    }

    $filePath = $siteRoot . 'pages/' . $pagename . '.html';
    $pagesDir = str_replace('\\', '/', realpath($siteRoot . 'pages'));

    if (file_exists($filePath)) {
        $target = str_replace('\\', '/', realpath($filePath));
        if (strpos($target, $pagesDir . '/') !== 0) {
            return false;
        }
        return $filePath;
    }

    return $filePath;
}

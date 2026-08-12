<?php
/*
    editor-totp.php
    Optional TOTP (RFC 6238) second factor for the page editor plugin.
    Included by editor-auth.php. Never access this file directly.

    Entirely self-contained: no third-party service, no network calls, no
    libraries. The authenticator app and this file each hold the same secret
    and each compute the same code from the current time. Nothing is exchanged.

    TOTP is OPTIONAL. If $editorTotpSecret is empty or unset in config.php,
    every function here is inert and the editor behaves exactly as it did
    before this file existed.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

define('SWF_TOTP_PERIOD',    30);  // seconds per code — the universal default
define('SWF_TOTP_DIGITS',    6);   // code length — the universal default
define('SWF_TOTP_SKEW',      1);   // accept N steps either side of now (±30s)
define('SWF_TOTP_SECRET_LEN', 20); // bytes of entropy — matches RFC 4226 recommendation

/*
    Is TOTP switched on?
    This is the single gate every other part of the editor asks. An empty or
    absent $editorTotpSecret means "off", so existing installs upgrade with
    no change in behaviour and no config edit required.
*/
function editor_totp_enabled() {
    global $editorTotpSecret;
    return !empty($editorTotpSecret);
}

/*
    Base32 (RFC 4648) decode.
    Authenticator apps treat the secret string you type as Base32 and decode it
    to raw bytes before hashing. We must do exactly the same or the codes will
    never agree. Padding, spaces and lowercase are all tolerated.
*/
function editor_totp_base32_decode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input    = strtoupper($input);
    $input    = preg_replace('/[^A-Z2-7]/', '', $input);

    if ($input === '') {
        return '';
    }

    $bits = '';
    for ($i = 0, $len = strlen($input); $i < $len; $i++) {
        $bits .= str_pad(decbin(strpos($alphabet, $input[$i])), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

/*
    Base32 encode — used only when generating a new secret for enrolment.
*/
function editor_totp_base32_encode($bytes) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $bits = '';
    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    // Pad to a multiple of 8 characters
    while (strlen($out) % 8 !== 0) {
        $out .= '=';
    }
    return $out;
}

/*
    Is this string usable as a Base32 secret?
    Guards against someone pasting a plain passphrase into config — that would
    silently decode to different bytes than the app produces, and every code
    would be rejected with no clue why.
*/
function editor_totp_secret_is_valid($secret) {
    $stripped = preg_replace('/[^A-Za-z2-7]/', '', $secret);
    if ($stripped === '') {
        return false;
    }
    // Reject anything containing characters outside the Base32 alphabet
    if (preg_match('/[^A-Za-z2-7= ]/', $secret)) {
        return false;
    }
    return strlen(editor_totp_base32_decode($secret)) >= 10;
}

/*
    Generate the code for one time step. This is HOTP (RFC 4226) with a
    time-derived counter, which is all TOTP actually is.
*/
function editor_totp_code_at($secret, $counter) {
    $key = editor_totp_base32_decode($secret);
    if ($key === '') {
        return null;
    }

    // 8-byte big-endian counter. pack('N*', 0, $counter) gives the high word
    // as zero, which is correct until the year 5.8 billion or so.
    $binary = pack('N*', 0, $counter);
    $hash   = hash_hmac('sha1', $binary, $key, true);

    // Dynamic truncation: the low nibble of the last byte picks the offset
    $offset = ord($hash[19]) & 0x0f;
    $value  = ((ord($hash[$offset])     & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            |  (ord($hash[$offset + 3]) & 0xff);

    $code = $value % (10 ** SWF_TOTP_DIGITS);
    return str_pad((string) $code, SWF_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/*
    Current time step.
*/
function editor_totp_counter($timestamp = null) {
    $timestamp = $timestamp ?? time();
    return intdiv($timestamp, SWF_TOTP_PERIOD);
}

/*
    Seconds remaining before the on-screen code rolls over. Used by the
    enrolment screen only.
*/
function editor_totp_seconds_remaining() {
    return SWF_TOTP_PERIOD - (time() % SWF_TOTP_PERIOD);
}

/*
    Replay protection.
    A code stays valid for its whole window, so the same six digits would
    otherwise work twice inside 30 seconds. We record the highest counter that
    has been accepted and refuse anything at or below it.
*/
function editor_totp_replay_file() {
    return __DIR__ . '/totp-used.json';
}

function editor_totp_last_counter() {
    $file = editor_totp_replay_file();
    if (!file_exists($file)) {
        return 0;
    }
    $data = json_decode(file_get_contents($file), true);
    return (is_array($data) && isset($data['last_counter'])) ? (int) $data['last_counter'] : 0;
}

function editor_totp_record_counter($counter) {
    file_put_contents(
        editor_totp_replay_file(),
        json_encode(['last_counter' => (int) $counter]),
        LOCK_EX
    );
}

/*
    Verify a submitted code.

    Walks the acceptance window oldest-first so that the recorded counter always
    moves forward. Comparison uses hash_equals to avoid leaking, through response
    timing, how many leading digits were correct.

    $recordUse = false lets the enrolment screen test a code without burning it.
*/
function editor_totp_verify($secret, $submitted, $recordUse = true) {
    if (empty($secret)) {
        return false;
    }

    $submitted = preg_replace('/\s+/', '', (string) $submitted);
    if (!preg_match('/^[0-9]{' . SWF_TOTP_DIGITS . '}$/', $submitted)) {
        return false;
    }

    $now  = editor_totp_counter();
    $last = $recordUse ? editor_totp_last_counter() : -1;

    for ($offset = -SWF_TOTP_SKEW; $offset <= SWF_TOTP_SKEW; $offset++) {
        $counter = $now + $offset;

        // Already used — a replay of a code still inside its window
        if ($counter <= $last) {
            continue;
        }

        $expected = editor_totp_code_at($secret, $counter);
        if ($expected !== null && hash_equals($expected, $submitted)) {
            if ($recordUse) {
                editor_totp_record_counter($counter);
            }
            return true;
        }
    }

    return false;
}

/*
    Enrolment helpers.
*/
function editor_totp_generate_secret() {
    return editor_totp_base32_encode(random_bytes(SWF_TOTP_SECRET_LEN));
}

/*
    Build the otpauth:// URI an authenticator app expects.
    The label is what shows in the app's list; the issuer groups entries.
*/
function editor_totp_provisioning_uri($secret, $label, $issuer) {
    $label  = str_replace([':', '/'], '', $label);
    $issuer = str_replace([':', '/'], '', $issuer);

    $path = rawurlencode($issuer) . ':' . rawurlencode($label);

    $query = http_build_query([
        'secret'    => rtrim($secret, '='),   // apps dislike padding in the URI
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => SWF_TOTP_DIGITS,
        'period'    => SWF_TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);

    return 'otpauth://totp/' . $path . '?' . $query;
}

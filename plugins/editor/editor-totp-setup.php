<?php
/*
    editor-totp-setup.php
    TOTP enrolment screen for the page editor plugin.
    Included by editor.php only when the user is already authenticated and
    ?totp=setup is present in the URL. Never access this file directly.

    Nothing here writes to config.php. The screen generates a secret, shows it
    to you, and lets you confirm your authenticator agrees before you commit —
    you paste the line into config/config.php yourself. That keeps the editor
    from ever needing write access to your configuration.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

/*
    Break the secret into groups of four for legibility when typing it in by
    hand. Authenticator apps ignore the spaces.
*/
function editor_totp_format_secret($secret) {
    return trim(implode(' ', str_split(rtrim($secret, '='), 4)));
}

/*
    Render the enrolment panel. Returns HTML.
*/
function editor_totp_setup_panel($editorTokenValue, $siteTitle, $siteURL) {
    global $editorTotpSecret;

    $out = '';

    // A fresh secret is generated once per enrolment session and held in the
    // session so it survives the "verify" round trip without ever going into
    // a URL or a hidden form field.
    if (isset($_POST['swf_editor_totp_regenerate']) || empty($_SESSION[SWF_EDITOR_TOTP_PENDING])) {
        $_SESSION[SWF_EDITOR_TOTP_PENDING] = editor_totp_generate_secret();
    }
    $pending = $_SESSION[SWF_EDITOR_TOTP_PENDING];

    // Verify a test code against the pending secret. recordUse is false so
    // that testing does not consume the code for the real login.
    $testResult = null;
    if (isset($_POST['swf_editor_totp_test']) && $_POST['swf_editor_totp_test'] !== '') {
        $testResult = editor_totp_verify($pending, $_POST['swf_editor_totp_test'], false);
    }

    // index.php rewrites $WebsiteURL to include the scheme before plugins load,
    // so strip it back off — otherwise the label reads "editor@httpsexample.com"
    // once the colon and slashes are removed below.
    $host  = rtrim(preg_replace('#^https?://#i', '', $siteURL), '/');
    $label = 'editor@' . ($host !== '' ? $host : 'website');
    $issuer = $siteTitle !== '' ? $siteTitle : 'Simple Website Framework';
    $uri    = editor_totp_provisioning_uri($pending, $label, $issuer);

    // The QR encoder is optional — if the file has been deleted, fall back to
    // manual entry, which every authenticator app supports.
    $qrSvg = '';
    if (file_exists(__DIR__ . '/editor-qr.php')) {
        require_once __DIR__ . '/editor-qr.php';
        $qrSvg = editor_qr_svg($uri, 5);
    }

    $box   = 'background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:18px;';
    $mono  = 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;';
    $small = 'font-size:0.85rem;color:#555;line-height:1.5;';

    $out .= '<div style="max-width:620px;margin:0 auto;">';
    $out .= '<h2 style="font-size:1.2rem;margin:0 0 6px;color:#111;">Set up two-factor authentication</h2>';
    $out .= '<p style="' . $small . 'margin:0 0 20px;">Optional. Nothing changes until you paste the line from step 3 into your config file.</p>';

    if (editor_totp_enabled()) {
        $out .= '<div style="' . $box . 'border-color:#e0a800;background:#fff8e5;">';
        $out .= '<strong style="color:#8a6100;">A secret is already configured.</strong>';
        $out .= '<p style="' . $small . 'margin:6px 0 0;">Completing this screen replaces it. Your existing authenticator entry will stop working the moment you update <code>config/config.php</code>, so remove it from your app and add the new one.</p>';
        $out .= '</div>';
    }

    // Step 1 — add to the app
    $out .= '<div style="' . $box . '">';
    $out .= '<h3 style="font-size:1rem;margin:0 0 12px;color:#111;">1. Add it to your authenticator</h3>';

    if ($qrSvg !== '') {
        $out .= '<div style="text-align:center;margin-bottom:16px;">' . $qrSvg . '</div>';
        $out .= '<p style="' . $small . 'text-align:center;margin:0 0 16px;">Scan with Google Authenticator, Aegis, 1Password, Ente, or any other TOTP app.</p>';
    }

    $out .= '<p style="' . $small . 'margin:0 0 6px;">Or enter this key by hand:</p>';
    $out .= '<div style="' . $mono . 'font-size:1.05rem;letter-spacing:0.06em;background:#f4f4f4;border:1px solid #e0e0e0;border-radius:4px;padding:12px 14px;word-break:break-all;text-align:center;">';
    $out .= htmlspecialchars(editor_totp_format_secret($pending));
    $out .= '</div>';
    $out .= '<p style="' . $small . 'margin:10px 0 0;">Account <strong>' . htmlspecialchars($label) . '</strong> &middot; time based &middot; 6 digits &middot; 30 seconds &middot; SHA1. Those are the defaults in every app, so you should not need to change anything.</p>';
    $out .= '</div>';

    // Step 2 — verify
    $out .= '<div style="' . $box . '">';
    $out .= '<h3 style="font-size:1rem;margin:0 0 12px;color:#111;">2. Check that it works</h3>';
    $out .= '<p style="' . $small . 'margin:0 0 12px;">Type the six digits your app is showing right now. This only tests the code — it will not be used up.</p>';
    $out .= '<form method="POST" style="display:flex;gap:10px;align-items:center;">';
    $out .= '<input type="text" name="swf_editor_totp_test" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="000000" ';
    $out .= 'style="' . $mono . 'padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:1.1rem;letter-spacing:0.15em;width:130px;text-align:center;">';
    $out .= '<button type="submit" style="padding:8px 18px;background:#111;color:#fff;border:none;border-radius:4px;font-size:0.95rem;cursor:pointer;">Test code</button>';

    if ($testResult === true) {
        $out .= '<span style="color:#1a7f37;font-weight:600;font-size:0.9rem;">&#10003; Matches</span>';
    } elseif ($testResult === false) {
        $out .= '<span style="color:#b00020;font-weight:600;font-size:0.9rem;">&#10007; No match</span>';
    }
    $out .= '</form>';

    if ($testResult === false) {
        $out .= '<p style="' . $small . 'margin:12px 0 0;">If it keeps failing, the usual cause is your server clock drifting. Codes are accepted within 30 seconds either side of the server\'s current time, so anything beyond that will be rejected no matter how carefully you type.</p>';
    }
    $out .= '</div>';

    // Step 3 — commit
    $out .= '<div style="' . $box . '">';
    $out .= '<h3 style="font-size:1rem;margin:0 0 12px;color:#111;">3. Add this line to config/config.php</h3>';
    $out .= '<div style="' . $mono . 'font-size:0.9rem;background:#1e1e1e;color:#e8e8e8;border-radius:4px;padding:14px;overflow-x:auto;">';
    $out .= '$editorTotpSecret = \'' . htmlspecialchars(rtrim($pending, '=')) . '\';';
    $out .= '</div>';
    $out .= '<p style="' . $small . 'margin:12px 0 0;">Once saved, log out and back in — you will be asked for a code from then on. To turn two-factor off again, set the value back to an empty string.</p>';
    $out .= '</div>';

    // Warnings and escape hatch
    $out .= '<div style="' . $box . 'border-color:#d0d0d0;background:#f7f7f7;">';
    $out .= '<h3 style="font-size:0.95rem;margin:0 0 8px;color:#111;">Before you commit</h3>';
    $out .= '<p style="' . $small . 'margin:0 0 8px;">This secret cannot be hashed. Unlike your password, the server has to keep the real value in order to recompute codes, so it sits in <code>config/config.php</code> as plain text. Make sure that directory is not web readable.</p>';
    $out .= '<p style="' . $small . 'margin:0;">Keep a copy of the key somewhere safe. If you lose your phone and have no backup, the only way back in is to edit <code>config/config.php</code> over FTP or SSH and blank the value.</p>';
    $out .= '</div>';

    $backURL = '?editor=' . urlencode($editorTokenValue);
    $out .= '<div style="display:flex;gap:10px;align-items:center;">';
    $out .= '<a href="' . htmlspecialchars($backURL) . '" style="padding:8px 16px;background:#fff;border:1px solid #ccc;border-radius:4px;color:#333;text-decoration:none;font-size:0.9rem;">Back to the editor</a>';
    $out .= '<form method="POST" style="margin:0;">';
    $out .= '<button type="submit" name="swf_editor_totp_regenerate" value="1" style="padding:8px 16px;background:#fff;border:1px solid #ccc;border-radius:4px;color:#888;font-size:0.9rem;cursor:pointer;">Generate a different secret</button>';
    $out .= '</form>';
    $out .= '</div>';

    $out .= '</div>';

    return $out;
}

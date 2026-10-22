<?php
	/* Don't forget to turn on caching if you're deploying to production! */
    $enableHTMLCacheServe = false;
	$SSL = false; /* Are we using http or https? */
	$ForceWWW = true;
	$WebsiteURL = "skeleton.localhost"; /* Put your website DOMAIN name without the http(s) bit here */	
	$WebsiteTitle = "Simple Website Framework";
	$WebsiteLanguage = "en"; /* Use Language Codes */
	$WebsiteLanguageCountry = "US"; /* Use country codes */
	$WebsiteLanguageLocale = "en_US"; /* Use Country Code plus locale */
	$WebsiteImage = "images/laptop-computer-writing-technology-web-internet.webp"; /* This image will be used as a default thumbnail any time the page image is not defined */
	$WebsiteDescription = "Creating websites shouldn't be a daunting task. With Simple Website Framework, simplicity and functionality merge seamlessly, offering you a hassle-free experience in website development."; /* Set a default description/excerpt for all pages */
	$WebsiteAuthor = "Scary le Poo"; /* Set a default page author */
	$WebsiteKeywords = "skeleton,framework,development,website,simplicity,security,ease,customize,flexibility"; /* Set default Keywords for site pages */
	
    /* RSS feed (yoursite.com/?rss, and ?rss=category). Both optional. */
	$rssFullContent = true; /* Put the whole post in the feed, not just the excerpt. false = excerpt only */
	$rssMaxItems    = 50;   /* Newest posts included */

    /* The [php] shortcode runs PHP code written inside a page. It is OFF by default because it turns
       "can edit a page" into "can run code on the server". Only switch it on if you need it and you
       are the only person who can change page content. While it is off, the editor refuses to save
       a page containing [php] and a [php] block in an existing page renders as an HTML comment. */
    $allowPhpShortcode = false;

    /* Select a Theme */
	$theme = "skeleton";
	
	/* Display page name on home page? */
	$showhomepagetitle = true;

    /* Do we want any plugins? */
	$loadplugins = true;

    if ($loadplugins == true) {
        /* Choose what plugins you want to load here */
		
		/* SETTING THIS TO FALSE WILL BREAK ANYTHING THAT RELIES ON JQUERY */
		/* Do we want to load jQuery? The anser to this is almost always going to be yes. */
		$jQuery = true;
		
		/* SETTING THIS TO FALSE WILL BREAK SMOOTHSCROLL COMPLETELY */
		/* Add a class automatically to anchor links (Typically used for setting scroll-margin-top properties so that navigation bars don't cover the content */
		$anchorLinkAutoClass = true;
		
		/* SETTING THIS TO FALSE WILL BREAK ANCHOR LINKS COMPLETELY */
		/* Rewrite anchor link target urls so that they target the current url */
		/* This script will dynamically update all anchor links that start with "#" to include the base URL of the current page. This way, relative URLs will remain intact, and only the anchor links will be modified to include the current page's path. */
		$anchorLinkCurrentURLRewrite = true;
		
		/* If meta box is active, do we want to rewrite all urls so that we can stay in meta box mode? */
		$metaInfoBoxRewriteURL = true;
            
        /* FontAwesome */
        $fontAwesome = false;
		
		/* yBox (Lightbox) */
        $yBox = true;
		
        /* ── Page Editor ────────────────────────────────────────────────────────
           Enables the browser-based editor at yoursite.com/edit/ — a list of
           every page and post, an edit form, and a preview through your theme.
           Appending ?editor=yourtoken to any page still works and opens that
           page in the editor.

           SETUP:
           1. Generate a password hash — create a temp PHP file containing:
                  <?php echo password_hash('yourpassword', PASSWORD_BCRYPT); ?>
              Visit it once, copy the output, delete the file.
              Paste the result into $editorPasswordHash below.

           2. Set $editorToken to a long random string. REQUIRED. Until it
              has been presented once on a device, /edit/ answers with the
              site's 404 page instead of a login form, so there is no public
              login page for bots to find. Bookmark this and you are set:
                  yoursite.com/edit/?editor=yourtoken
              A cookie then remembers the device for 30 days.
              Generate one with: php -r "echo bin2hex(random_bytes(16));"
              Changing it later logs out every remembered device.

           3. Set $editorEnabled = true.

           4. Session cookies are always HttpOnly and SameSite=Strict. They are
              also flagged Secure when $SSL is true or the request came over
              HTTPS, so local HTTP development works without any change here.

           Set $editorEnabled = false to completely disable the editor.
           When false, zero editor code runs anywhere on the site.

           TWO-FACTOR (OPTIONAL):
           $editorTotpSecret adds a six-digit code from an authenticator app on
           top of the password. It is OFF by default — leave it as an empty
           string and the editor behaves exactly as it always has, with no code
           field on the login form and nothing extra to configure.

           To switch it on, **log into the editor as normal** and **then** visit:
                  yoursite.com/about?editor=yourtoken&totp=setup
           That screen generates a secret, shows a QR code to scan, lets you
           confirm your app agrees before you commit, and prints the line to
           paste back here. Set it back to '' to switch two-factor off again.

           No third-party service is involved. Your authenticator app and this
           file each hold the same secret and each compute the same code from
           the current time — nothing is exchanged, and neither side needs an
           internet connection.

           The value must be Base32 (the letters A-Z and the digits 2-7 only).
           A plain passphrase will not work: authenticator apps decode what you
           type as Base32 before hashing it, so the bytes would not match.

           IMPORTANT: unlike the password, this secret cannot be hashed. The
           server needs the real value in order to recompute codes, so it sits
           here in plain text. Keep config/.htaccess in place, and keep a copy
           of the secret somewhere safe — if you lose your phone, the only way
           back in is to blank this value over FTP or SSH.
        ── */
        $editorEnabled      = false;
        $editorToken        = ''; /* Required: a long random string, e.g. bin2hex(random_bytes(16)) */
        $editorPasswordHash = ''; /* Paste your bcrypt hash here */
        $editorTotpSecret   = ''; /* Optional 2FA. Base32 only (A-Z, 2-7). Empty = off. See notes above. */
        $editorSessionTimeout = 1800; /* Session lifetime in seconds. Default: 1800 (30 minutes) */

        /* ── Backups ────────────────────────────────────────────────────────────
           The editor keeps a copy of every page before it saves over it, moves
           deleted pages to a trash instead of removing them, and can build a
           zip of the site (from the Backups screen, from cron, or by itself
           once a week when you save). All of it is stored in $backupDir.

           $backupDir is "backups" in the site root by default. The folder is
           created with an .htaccess that denies all web access and an empty
           index.html, and the site's own .htaccess refuses the path too. If
           your server is not Apache, set this to a folder OUTSIDE the web
           root (an absolute path) — that is the safest arrangement anywhere.

           These settings are optional; leave them out and the defaults apply.
        ── */
        $backupDir          = 'backups'; /* Relative to the site root, or an absolute path */
        $backupKeepVersions = 20;        /* Previous versions kept per page */
        $backupKeepArchives = 7;         /* Automatic/scheduled site backups kept per kind. Ones you make by hand are never rotated out */
        $backupAutoDays     = 7;         /* On save, make a content backup if the newest is older than this many days. 0 = off */

        /* ── File uploads ───────────────────────────────────────────────────────
           The editor's Files screen (and the picture button in the content
           editor) can upload into these folders and their subfolders, and
           nowhere else. Code folders and pages/ itself are never allowed,
           whatever is listed here. Images, video, audio, PDF, documents,
           text, fonts and zips are accepted; anything that could run on the
           server is refused, and each folder gets an .htaccess that stops
           scripts executing there regardless.

           Both settings are optional; leave them out and the defaults apply.
           The size limit can never exceed PHP's own upload_max_filesize /
           post_max_size, which the Files screen reports.
        ── */
        $uploadDirs    = array('images', 'pages/images', 'pages/posts/images', 'videos');
        $uploadMaxSize = 25 * 1048576; /* Bytes per file. Default 25 MB */
    }
?>
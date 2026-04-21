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
           Enables the per-page editor. Access any page with ?editor or
           ?editor=yourtoken appended to the URL.

           SETUP:
           1. Generate a password hash — create a temp PHP file containing:
                  <?php echo password_hash('yourpassword', PASSWORD_BCRYPT); ?>
              Visit it once, copy the output, delete the file.
              Paste the result into $editorPasswordHash below.

           2. Set $editorToken to a long random string. This must be included
              in the URL to even reach the login form:
                  yoursite.com/about?editor=yourtoken
              Generate one with: php -r "echo bin2hex(random_bytes(16));"
              Leave empty ('') to disable the URL token check (not recommended).

           3. Set $editorEnabled = true.

           4. On production (HTTPS), session cookies are automatically flagged
              Secure and HttpOnly. On local HTTP development this is fine as-is.

           Set $editorEnabled = false to completely disable the editor.
           When false, zero editor code runs anywhere on the site.
        ── */
        $editorEnabled      = false;
        $editorToken        = ''; /* Recommended: a long random string, e.g. bin2hex(random_bytes(16)) */
        $editorPasswordHash = ''; /* Paste your bcrypt hash here */
        $editorSessionTimeout = 1800; /* Session lifetime in seconds. Default: 1800 (30 minutes) */
    }
?>
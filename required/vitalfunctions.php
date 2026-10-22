<?php
	/*
		swf_page_source() returns the raw source of a page (metadata comments and
		body together), exactly as it sits in pages/<pagename>.html.

		Every place that needs the page source — the metadata extraction below,
		the page-*.php layout templates in every theme, and header.php — must go
		through this function rather than calling file_get_contents() directly.

		The reason is the editor's preview mode. When an editor session is
		previewing an unsaved draft, index.php sets $pageContentOverride to the
		draft text before any of this runs. This function hands that back instead
		of reading the disk, so the draft renders through the real theme without
		ever being written to pages/. With no preview in progress the override is
		null and this is a plain file read.
	*/
	function swf_page_source($pagename) {
		global $pageContentOverride;
		if (isset($pageContentOverride) && $pageContentOverride !== null) {
			return $pageContentOverride;
		}
		$file = "pages/" . $pagename . ".html";
		return file_exists($file) ? file_get_contents($file) : '';
	}

	// This function helps extract special values from a file based on a given pattern
	function extractValueFromPattern($filename, $pattern) {
		// Read the content of the page file (or the editor's preview draft)
		$markdownContent = swf_page_source($filename);
		// Check if the pattern matches any part of the content
		if (preg_match($pattern, $markdownContent, $matches)) {
			// If there's a match, return the extracted value (trimmed to remove any extra spaces)
			return trim($matches[1]);
		}
		// If no match is found, initialize the variable with an empty string. We do this so that the page doesn't die due to errors.
		return null;
	}

	// Define an array of patterns and their corresponding variable names
	$patterns = [
		'pagetitle' => '/<!--\s+pagetitle:(.*?)\s+-->/s',         // Pattern to extract page title
		'pagelayout' => '/<!--\s+pagelayout:(.*?)\s+-->/s',                // Pattern to extract layout
		'pagedate' => '/<!--\s+pagedate:(.*?)\s+-->/s',                // Pattern to extract date
		'pageimage' => '/<!--\s+pageimage:(.*?)\s+-->/s',      // Pattern to extract image
		'pageexcerpt' => '/<!--\s+pageexcerpt:(.*?)\s+-->/s',          // Pattern to extract excerpt
		'pagekeywords' => '/<!--\s+pagekeywords:(.*?)\s+-->/s',        // Pattern to extract keywords
		'pageauthor' => '/<!--\s+pageauthor:(.*?)\s+-->/s',            // Pattern to extract author
		'pagetype' => '/<!--\s+pagetype:(.*?)\s+-->/s',                // Pattern to extract page content type (E.G. website, article, blog, profile, video, music, book, product)
		'pagecategory' => '/<!--\s+pagecategory:(.*?)\s+-->/s',        // Pattern to extract the categories a POST belongs to (comma-separated). Optional — posts without it are treated as "uncategorized" by the archive templates.
		'postcategory' => '/<!--\s+postcategory:(.*?)\s+-->/s',        // Pattern to extract the category filter on an ARCHIVE page. When set, the postarchives layouts only list posts whose pagecategory contains this value. "uncategorized" lists all untagged posts.
		
		/* Here is a nice copy pastable list of tags for posts and pages */
		/*
			<!-- pagetitle: -->
			<!-- pagelayout: -->
			<!-- pagedate: -->
			<!-- pageimage: -->
			<!-- pageexcerpt: -->
			<!-- pagekeywords: -->
			<!-- pageauthor: -->
			<!-- pagetype: -->
			<!-- pagecategory: -->
		*/
		/* And this one is only for post archive (category) pages */
		/*
			<!-- postcategory: -->
		*/

		// You can add more patterns here as needed
	];

	// Loop through each pattern and extract its value
	foreach ($patterns as $variableName => $pattern) {
		// Use the extractValueFromPattern function to extract value based on each pattern
		// Store the extracted value in a variable with the name specified in $variableName
		${$variableName} = extractValueFromPattern($pagename, $pattern);
	}

	/*
		pagelayout names a template file in the theme folder, which every theme
		then includes. Restrict it to a plain file name — letters, digits and
		hyphens, no slashes or dots — and to a file that actually exists in the
		theme, so that nothing a page's metadata says can make the theme include
		a file from anywhere else. Anything else falls back to the default layout.
	*/
	$pagelayout = swf_layout_name($pagelayout ?? '', $theme);
	function swf_layout_name($layout, $theme) {
		$layout = trim((string) $layout);
		if ($layout === '') { return ''; }   // themes substitute their own default for an empty value
		if (!preg_match('/^[A-Za-z0-9\-]+$/', $layout) || !is_file('themes/' . $theme . '/' . $layout . '.php')) {
			return 'page-md';
		}
		return $layout;
	}
?>
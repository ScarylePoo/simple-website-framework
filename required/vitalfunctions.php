<?php
	// This function helps extract special values from a file based on a given pattern
	function extractValueFromPattern($filename, $pattern) {
		// Read the content of the Markdown file
		$markdownContent = file_get_contents("pages/" . $filename . ".html");
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
?>
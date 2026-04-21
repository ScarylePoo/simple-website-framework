<?php
// Function to remove unwanted query strings from URL
function removeUnwantedQueryString($url) {
    // Define the list of query string parameters to preserve
    $preserveParams = array('meta', 'page', 'editor');

    // Parse the URL
    $urlParts = parse_url($url);

    // Initialize an array to hold the preserved query string parameters
    $preservedQueryParams = array();

    // If query string exists
    if(isset($urlParts['query'])) {
        // Parse the query string
        parse_str($urlParts['query'], $queryParams);

        // Iterate through query parameters
        foreach($queryParams as $key => $value) {
            // Check if the parameter should be preserved
            if(in_array($key, $preserveParams)) {
                // Preserve the parameter
                $preservedQueryParams[$key] = $value;
            }
        }
    }

    // Rebuild the query string with preserved parameters
    $preservedQueryString = http_build_query($preservedQueryParams);

    // Reconstruct the URL with preserved query string
    $urlWithoutUnwantedQueryStrings = isset($urlParts['path']) ? $urlParts['path'] : '';
    if (!empty($preservedQueryString)) {
        $urlWithoutUnwantedQueryStrings .= '?' . $preservedQueryString;
    }

    return $urlWithoutUnwantedQueryStrings;
}

$url = $_SERVER["REQUEST_URI"];
$urlWithoutUnwantedQueryStrings = removeUnwantedQueryString($url);

$break = explode('/', $urlWithoutUnwantedQueryStrings);
$file = $break[count($break) - 1];

// Check if $file contains a file extension
if (strpos($file, '.') !== false) {
    $cachefile = 'cached-' . substr_replace($file, "", -4) . '.html';
} else {
    $cachefile = 'cached-' . $file . '.html';
}

$cachetime = 18000; // Seconds, 18000 is 5 hours
?>
<!-- This is a cached version! -->
<?php
// Serve from the cache if it is younger than $cachetime
if (file_exists($cachefile) && time() - $cachetime < filemtime($cachefile)) {
    echo "<!-- The URI for this page is: ".$_SERVER["REQUEST_URI"]." -->\n";
    echo "<!-- Cached copy, generated ".date('H:i', filemtime($cachefile))." -->\n";
    readfile($cachefile);
    exit;
}
ob_start(); // Start the output buffer
?>

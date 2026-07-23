<?php
// Params that must NEVER be served from or written to cache — always live.
// (editor= and meta= gate the page editor / meta-info overlay, which must
// reflect real-time state, not a stale disk snapshot.)
$noCacheParams = array('meta', 'editor');

$url = $_SERVER["REQUEST_URI"];
$urlParts = parse_url($url);
$requestQueryParams = array();
if (isset($urlParts['query'])) {
    parse_str($urlParts['query'], $requestQueryParams);
}

$skipCache = false;
foreach ($noCacheParams as $p) {
    if (isset($requestQueryParams[$p])) {
        $skipCache = true;
        break;
    }
}

if (!$skipCache) {
    // Function to remove unwanted query strings from URL
    // Only 'page' is preserved into the cache key now — meta/editor are
    // handled above via $skipCache instead of being folded into the filename.
    function removeUnwantedQueryString($url) {
        $preserveParams = array('page');

        $urlParts = parse_url($url);
        $preservedQueryParams = array();

        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
            foreach ($queryParams as $key => $value) {
                if (in_array($key, $preserveParams)) {
                    $preservedQueryParams[$key] = $value;
                }
            }
        }

        $preservedQueryString = http_build_query($preservedQueryParams);

        $urlWithoutUnwantedQueryStrings = isset($urlParts['path']) ? $urlParts['path'] : '';
        if (!empty($preservedQueryString)) {
            $urlWithoutUnwantedQueryStrings .= '?' . $preservedQueryString;
        }

        return $urlWithoutUnwantedQueryStrings;
    }

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
}
?>
<!-- This is a cached version! -->
<?php
// Serve from the cache if it is younger than $cachetime
if (!$skipCache && file_exists($cachefile) && time() - $cachetime < filemtime($cachefile)) {
    echo "<!-- The URI for this page is: ".$_SERVER["REQUEST_URI"]." -->\n";
    echo "<!-- Cached copy, generated ".date('H:i', filemtime($cachefile))." -->\n";
    readfile($cachefile);
    exit;
}
ob_start(); // Start the output buffer
?>

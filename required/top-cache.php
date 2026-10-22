<?php
// Params that must NEVER be served from or written to cache — always live.
// (editor= and meta= gate the page editor / meta-info overlay, and preview=
// renders an unsaved editor draft; all three must reflect real-time state,
// not a stale disk snapshot, and a draft must never be written to disk.)
$noCacheParams = array('meta', 'editor', 'preview');

$url = $_SERVER["REQUEST_URI"];
$urlParts = parse_url($url);
$requestQueryParams = array();
if (isset($urlParts['query'])) {
    parse_str($urlParts['query'], $requestQueryParams);
}

$skipCache = !empty($swfNoCache); // index.php sets this for pages that must never be cached (an editor viewing a draft)
foreach ($noCacheParams as $p) {
    if (isset($requestQueryParams[$p])) {
        $skipCache = true;
        break;
    }
}

/*
    Which query parameters may vary the cached copy. Only ?page=N, the
    archive pagination, and only when N is a whole number from 1 up:
    anything else in the query string is ignored, so the cache cannot be
    made to write one file per made-up value (?page=1x, ?page=1y, …) and a
    non-numeric value cannot reach the templates.
*/
if (!$skipCache) {
    $cachePage = 0;
    if (isset($requestQueryParams['page'])) {
        $p = (string) $requestQueryParams['page'];
        if (!ctype_digit($p) || (int) $p < 1 || strlen($p) > 6) {
            $skipCache = true;   // out of range: render live (the templates clamp it), never store it
        } else {
            $cachePage = (int) $p;
        }
    }
}

if (!$skipCache) {
    /*
        The cache file is named after the WHOLE path, not just its last part,
        so pages/subfolder/test and pages/test never share a file. The path
        here has already passed the .htaccess rule (letters, digits, hyphens
        and slashes only) and the pages/ whitelist in index.php.
    */
    $path = trim(isset($urlParts['path']) ? $urlParts['path'] : '', '/');
    $key  = $path === '' ? 'root' : str_replace('/', '--', $path);
    $key  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
    if ($cachePage > 1) { $key .= '--page-' . $cachePage; }
    $cachefile = 'cached-' . $key . '.html';

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

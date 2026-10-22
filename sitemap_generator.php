<?php
/*
    sitemap_generator.php
    The site's sitemap, served at /sitemap.xml (a rewrite rule in .htaccess
    maps it to index.php?sitemap, which hands over here before the cache
    layer loads).

    Lists every live page — pages and posts — except the machinery (404,
    navigation, the footer columns), any page carrying
    <!-- sitemap: no -->, and drafts, which do not exist as .html files.
    lastmod is the file's modification time, which is when the content last
    actually changed, rather than the pagedate, which is an editorial date.

    Needs required/feeds.php (swf_sitemap_pages), which index.php includes.
*/

/*
    Anything already in the output buffer — typically a newline after the
    closing ?> in config.php — would land before the <?xml declaration and
    make the document invalid. PHP's output buffering is on by default on
    hosted servers, so it can be discarded here; if it is off, there is
    nothing to be done and the stray bytes have already gone out.
*/
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: application/xml; charset=UTF-8');

$siteLink = rtrim($WebsiteURL, '/');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (swf_sitemap_pages() as $p) {
    $loc = $p['name'] === 'home' ? $siteLink . '/' : $siteLink . '/' . $p['name'];
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . date('c', $p['lastmod']) . "</lastmod>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";

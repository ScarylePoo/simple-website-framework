<?php
/*
    rss_generator.php
    The site's RSS 2.0 feed, served by index.php for ?rss requests before the
    cache layer loads, so a feed is always fresh.

        ?rss                 every post
        ?rss=all             the same, explicitly
        ?rss=tutorials       one category
        ?rss=design,lore     posts in any of several categories
        ?rss=uncategorized   posts with no category tag

    Which posts: every live file in pages/posts/ with a title and a date.
    Drafts (pages/posts/*.html.draft) are never included. Posts without an
    excerpt or image are included with fallbacks.

    What each item carries: title, link, a guid (the permalink, so readers
    recognise an item they have seen even if its title changes), the
    excerpt as <description>, the full post as <content:encoded> unless
    $rssFullContent is false in config.php, the post's image as an
    enclosure with its real size, the publish date, and a <category> per
    category.

    Relative paths in the full content (images at pages/posts/images/…, links
    to other pages) are made absolute, because a feed reader has no idea
    what site the content came from.

    Needs the shortcode and Markdown parsers and required/feeds.php, which
    index.php includes before this file.
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

header('Content-Type: application/rss+xml; charset=UTF-8');

// ── Settings ──────────────────────────────────────────────────────────────

$rssFullContent = isset($rssFullContent) ? (bool) $rssFullContent : true;
$rssMaxItems    = isset($rssMaxItems) && (int) $rssMaxItems > 0 ? (int) $rssMaxItems : 50;

// ── The category filter from ?rss= ─────────────────────────────────────────

$rssFilterCategories = array();
if (!empty($_GET['rss']) && strtolower(trim($_GET['rss'])) !== 'all') {
    $rssFilterCategories = swf_feeds_split_categories($_GET['rss']);
}

// ── Channel ────────────────────────────────────────────────────────────────

$siteLink  = rtrim($WebsiteURL, '/');
$siteTitle = html_entity_decode($WebsiteTitle, ENT_QUOTES, 'UTF-8');
$cats      = swf_site_categories();

if ($rssFilterCategories) {
    $names = array();
    foreach ($rssFilterCategories as $c) { $names[] = isset($cats[$c]) ? $cats[$c]['title'] : ucwords(str_replace('-', ' ', $c)); }
    $siteTitle .= ' – ' . implode(', ', $names);
}

$selfUrl  = $siteLink . '/?rss' . ($rssFilterCategories ? '=' . implode(',', $rssFilterCategories) : '');
$language = strtolower(($WebsiteLanguage ?? 'en') . (!empty($WebsiteLanguageCountry) ? '-' . $WebsiteLanguageCountry : ''));

/*
    Turn a path from page metadata or content into an absolute URL.
    Already-absolute URLs, protocol-relative URLs, anchors, mailto: and
    data: are left alone.
*/
function rss_absolute($url, $siteLink) {
    $url = trim((string) $url);
    if ($url === '' || preg_match('#^(https?:)?//|^(mailto|tel|data):|^\##i', $url)) { return $url; }
    return $siteLink . '/' . ltrim($url, '/');
}

// Make src="" and href="" in a block of HTML absolute
function rss_absolute_html($html, $siteLink) {
    return preg_replace_callback('/\b(src|href|poster)=(["\'])(.*?)\2/i', function ($m) use ($siteLink) {
        return $m[1] . '=' . $m[2] . rss_absolute($m[3], $siteLink) . $m[2];
    }, $html);
}

function rss_mime($url) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml');
    return $types[$ext] ?? 'application/octet-stream';
}

// Text for an XML element: decode entities the page stored, then escape once
function rss_text($s) {
    return htmlspecialchars(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ── Items ──────────────────────────────────────────────────────────────────

$items = array();
foreach (swf_live_posts() as $post) {
    if ($rssFilterCategories && !array_intersect($rssFilterCategories, $post['categories'])) { continue; }

    $file    = $post['file'];
    $excerpt = swf_feeds_tag($file, 'pageexcerpt');
    $image   = swf_feeds_tag($file, 'pageimage');
    $author  = swf_feeds_tag($file, 'pageauthor');
    $layout  = swf_feeds_tag($file, 'pagelayout');

    $url      = $siteLink . '/' . $post['name'];
    $imageUrl = rss_absolute($image !== '' ? $image : $WebsiteImage, $siteLink);
    $imageLen = 0;
    $localImg = ltrim(parse_url($imageUrl, PHP_URL_PATH) ?: '', '/');
    if ($localImg !== '' && strpos($imageUrl, $siteLink . '/') === 0 && is_file($localImg)) { $imageLen = filesize($localImg); }

    $body = '';
    if ($rssFullContent) {
        $raw = file_get_contents($file);
        // Drop the metadata comment block at the top
        $raw = preg_replace('/\A(\s*<!--\s*[a-z]+\s*:.*?-->\s*)+/s', '', $raw);
        $raw = parse_shortcodes($raw);
        if (strpos($layout, 'page-md') === 0) { $raw = from_markdown($raw); }
        $body = rss_absolute_html(trim($raw), $siteLink);
    }

    $items[] = array(
        'title'      => rss_text($post['title']),
        'link'       => $url,
        'excerpt'    => rss_text($excerpt),
        'body'       => $body,
        'image'      => $imageUrl,
        'imageLen'   => $imageLen,
        'author'     => rss_text($author),
        'categories' => $post['categories'],
        'time'       => $post['time'],
    );
    if (count($items) >= $rssMaxItems) { break; }   // swf_live_posts() is newest first
}

$lastBuild = $items ? max(array_column($items, 'time')) : time();

// ── Output ─────────────────────────────────────────────────────────────────

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
echo '<channel>' . "\n";
echo '<title>' . rss_text($siteTitle) . '</title>' . "\n";
echo '<link>' . rss_text($siteLink) . '</link>' . "\n";
echo '<atom:link href="' . rss_text($selfUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
echo '<description>' . rss_text($WebsiteDescription) . '</description>' . "\n";
echo '<language>' . rss_text($language) . '</language>' . "\n";
echo '<lastBuildDate>' . date(DATE_RSS, $lastBuild) . '</lastBuildDate>' . "\n";
echo '<generator>Simple Website Framework</generator>' . "\n";
echo '<image>' . "\n";
echo '<url>' . rss_text(rss_absolute($WebsiteImage, $siteLink)) . '</url>' . "\n";
echo '<title>' . rss_text($siteTitle) . '</title>' . "\n";
echo '<link>' . rss_text($siteLink) . '</link>' . "\n";
echo '</image>' . "\n";

foreach ($items as $item) {
    echo '<item>' . "\n";
    echo '<title>' . $item['title'] . '</title>' . "\n";
    echo '<link>' . rss_text($item['link']) . '</link>' . "\n";
    echo '<guid isPermaLink="true">' . rss_text($item['link']) . '</guid>' . "\n";
    echo '<pubDate>' . date(DATE_RSS, $item['time']) . '</pubDate>' . "\n";
    if ($item['author'] !== '') { echo '<dc:creator>' . $item['author'] . '</dc:creator>' . "\n"; }
    foreach ($item['categories'] as $c) { echo '<category>' . rss_text($c) . '</category>' . "\n"; }
    echo '<description>' . $item['excerpt'] . '</description>' . "\n";
    if ($item['body'] !== '') { echo '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $item['body']) . ']]></content:encoded>' . "\n"; }
    echo '<enclosure url="' . rss_text($item['image']) . '" type="' . rss_mime($item['image']) . '" length="' . (int) $item['imageLen'] . '" />' . "\n";
    echo '</item>' . "\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";

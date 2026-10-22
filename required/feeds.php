<?php
/*
    required/feeds.php
    What the site syndicates: the categories that exist, the RSS feeds that
    follow from them, and the <link rel="alternate"> tags that let feed
    readers discover those feeds. Also the list of public pages for the
    sitemap. Included by index.php on every request (it is cheap: one pass
    over the metadata comments of pages/).

    Used by rss_generator.php, sitemap_generator.php, the [feeds] shortcode,
    and the bundled themes' header.php (swf_feed_link_tags()).

    Expects $WebsiteURL (with scheme, as index.php sets it) and $WebsiteTitle.
*/

/*
    Read one metadata tag out of a page file. Same regex family as
    vitalfunctions.php; returns '' when the tag is absent.
*/
function swf_feeds_tag($file, $tag) {
    static $cache = array();
    if (!isset($cache[$file])) {
        $cache[$file] = @file_get_contents($file);
    }
    if ($cache[$file] === false) { return ''; }
    return preg_match('/<!--\s*' . $tag . '\s*:\s*(.*?)\s*-->/s', $cache[$file], $m) ? trim($m[1]) : '';
}

// Lowercase, trimmed category slugs from a comma separated tag value
function swf_feeds_split_categories($value) {
    $out = array();
    foreach (explode(',', (string) $value) as $c) {
        $c = strtolower(trim($c));
        if ($c !== '' && preg_match('/^[a-z0-9\-]+$/', $c) && !in_array($c, $out, true)) { $out[] = $c; }
    }
    return $out;
}

/*
    Every live post (pages/posts/*.html, never .html.draft) with its title,
    date and categories. Untagged posts are "uncategorized", matching the
    archive templates and the RSS feed.
*/
function swf_live_posts() {
    static $posts = null;
    if ($posts !== null) { return $posts; }
    $posts = array();
    foreach (glob('pages/posts/*.html') as $file) {
        $title = swf_feeds_tag($file, 'pagetitle');
        $date  = swf_feeds_tag($file, 'pagedate');
        if ($title === '' || $date === '') { continue; }   // same rule as the feed and the archives
        $cats = swf_feeds_split_categories(swf_feeds_tag($file, 'pagecategory'));
        if (!$cats) { $cats = array('uncategorized'); }
        $posts[] = array(
            'name'       => 'posts/' . basename($file, '.html'),
            'file'       => $file,
            'title'      => $title,
            'date'       => $date,
            'time'       => strtotime($date) ?: filemtime($file),
            'categories' => $cats,
        );
    }
    usort($posts, function ($a, $b) { return $b['time'] <=> $a['time']; });
    return $posts;
}

/*
    The site's categories: slug => array(count => posts in it, page => the
    archive page that lists it, or null, title => a display name).

    A category exists if a post is tagged with it OR an archive page filters
    on it (so a brand new category page shows up before its first post). The
    display name is the archive page's title when there is one, otherwise
    the slug with hyphens turned to spaces and words capitalised.
*/
function swf_site_categories() {
    static $cats = null;
    if ($cats !== null) { return $cats; }
    $cats = array();
    foreach (swf_live_posts() as $p) {
        foreach ($p['categories'] as $c) {
            if (!isset($cats[$c])) { $cats[$c] = array('count' => 0, 'page' => null, 'title' => ucwords(str_replace('-', ' ', $c))); }
            $cats[$c]['count']++;
        }
    }
    // Archive pages that filter on a category
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('pages', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), 6)); // after "pages/"
        if (strpos($rel, 'posts/') === 0) { continue; }
        $file = 'pages/' . $rel;
        if (strpos(swf_feeds_tag($file, 'pagelayout'), 'postarchives') !== 0) { continue; }
        $filter = strtolower(swf_feeds_tag($file, 'postcategory'));
        if ($filter === '' || !preg_match('/^[a-z0-9\-]+$/', $filter)) { continue; }
        if (!isset($cats[$filter])) { $cats[$filter] = array('count' => 0, 'page' => null, 'title' => ucwords(str_replace('-', ' ', $filter))); }
        if ($cats[$filter]['page'] === null) {
            $cats[$filter]['page'] = substr($rel, 0, -5);
            $t = swf_feeds_tag($file, 'pagetitle');
            if ($t !== '') { $cats[$filter]['title'] = html_entity_decode($t, ENT_QUOTES, 'UTF-8'); }
        }
    }
    ksort($cats);
    return $cats;
}

/*
    The feeds the site offers, main feed first, then one per category:
    array(array(title => ..., url => ..., category => slug|null, count => n))
*/
function swf_feed_list() {
    global $WebsiteURL, $WebsiteTitle;
    $base  = rtrim($WebsiteURL, '/');
    $feeds = array(array('title' => $WebsiteTitle, 'url' => $base . '/?rss', 'category' => null, 'count' => count(swf_live_posts())));
    foreach (swf_site_categories() as $slug => $c) {
        $feeds[] = array('title' => $WebsiteTitle . ' – ' . $c['title'], 'url' => $base . '/?rss=' . $slug, 'category' => $slug, 'count' => $c['count']);
    }
    return $feeds;
}

/*
    <link rel="alternate" type="application/rss+xml"> tags for a page's
    <head>. Feed readers and browser extensions find feeds through these.
    $currentCategory (the postcategory of an archive page being rendered)
    puts that category's feed first, so "subscribe" on /tutorials offers the
    tutorials feed before the whole-site one.
*/
function swf_feed_link_tags($currentCategory = '') {
    $feeds = swf_feed_list();
    $currentCategory = strtolower(trim((string) $currentCategory));
    if ($currentCategory !== '') {
        usort($feeds, function ($a, $b) use ($currentCategory) {
            return ($b['category'] === $currentCategory) <=> ($a['category'] === $currentCategory);
        });
    }
    $out = '';
    foreach ($feeds as $f) {
        $out .= "\t" . '<link rel="alternate" type="application/rss+xml" title="' . htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($f['url'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    return $out;
}

/*
    Public pages for the sitemap: every live page except the ones that are
    machinery (404, navigation, footer columns) and any page carrying
    <!-- sitemap: no -->. Drafts never appear (they are .html.draft).
*/
function swf_sitemap_pages() {
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('pages', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel  = str_replace('\\', '/', substr($f->getPathname(), 6));
        $name = substr($rel, 0, -5);
        if ($name === '404' || $name === 'navigation' || strpos($name, 'footer/') === 0) { continue; }
        $file = 'pages/' . $rel;
        if (strtolower(swf_feeds_tag($file, 'sitemap')) === 'no') { continue; }
        $date = swf_feeds_tag($file, 'pagedate');
        $out[] = array(
            'name'    => $name,
            'lastmod' => filemtime($file),
            'date'    => $date !== '' ? (strtotime($date) ?: 0) : 0,
            'is_post' => strpos($name, 'posts/') === 0,
        );
    }
    usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}

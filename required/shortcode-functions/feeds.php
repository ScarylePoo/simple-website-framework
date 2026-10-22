<?php
/*********************************************
* [feeds] shortcode
**********************************************
* Lists the RSS feeds the site offers: the
* whole-site feed and one per category, with
* post counts. Built from what exists, so a new
* category appears here on its own.
*
*   [feeds]
*   [feeds counts="false"]
*   [feeds title="Subscribe"]
*
* Attributes
*   counts   true (default) / false — show the
*            number of posts after each feed
*   title    optional heading above the list
*   class    added to the <ul>
*
* Output
*   <ul class="swf-feeds [class]">
*     <li><a href="…?rss" type="application/rss+xml">Site name</a> <span class="swf-feed-count">12 posts</span></li>
*     …
*   </ul>
**********************************************/

function shortcode_feeds($attributes, $content) {
    $e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $counts = !isset($attributes['counts']) || !in_array(strtolower(trim($attributes['counts'])), array('0', 'false', 'no', 'off'), true);
    $class  = 'swf-feeds';
    if (!empty($attributes['class'])) {
        foreach (preg_split('/\s+/', trim($attributes['class'])) as $c) { if (preg_match('/^[A-Za-z_][\w-]*$/', $c)) { $class .= ' ' . $c; } }
    }
    $html = '';
    if (!empty($attributes['title'])) { $html .= '<h3 class="swf-feeds-title">' . $e($attributes['title']) . '</h3>'; }
    $html .= '<ul class="' . $class . '">';
    foreach (swf_feed_list() as $f) {
        $label = $f['category'] === null ? 'All posts' : (swf_site_categories()[$f['category']]['title'] ?? $f['category']);
        $html .= '<li><a href="' . $e($f['url']) . '" type="application/rss+xml">' . $e($label) . '</a>';
        if ($counts) { $html .= ' <span class="swf-feed-count">' . (int) $f['count'] . ' post' . ($f['count'] === 1 ? '' : 's') . '</span>'; }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

<?php
// Updated RSS shortcode function with a limit parameter
function shortcode_rss_feed($attributes, $content) {
    // Determine the RSS URL and limit
    $rss_url = isset($attributes['url']) ? $attributes['url'] : $content;
    $limit = isset($attributes['limit']) ? intval($attributes['limit']) : -1; // Default to no limit

    // Check if the RSS URL is provided
    if (empty($rss_url)) {
        return '<p>Error: RSS feed URL missing.</p>';
    }

    // Fetch and parse the RSS feed
    $rss = @simplexml_load_file($rss_url);

    // Check if the feed was successfully loaded
    if ($rss === false) {
        return '<p>Error loading RSS feed.</p>' . $rss_url;
    }

    // Start building the HTML output
    $output = '<div class="rss-feed-container">';

    // Loop through each item in the feed
    $items = $rss->channel->item;
    $count = 0;

    foreach ($items as $item) {
        // Stop if the limit is reached
        if ($limit > 0 && $count >= $limit) {
            break;
        }

        // Extract data from the RSS feed
        $title = (string) $item->title;
        $link = (string) $item->link;
        $description = (string) $item->description;
        $date = (string) $item->pubDate;
        $image = '';

        // Check if there is an image (media:content or enclosure tags are common)
        if (isset($item->enclosure['url'])) {
            $image = (string) $item->enclosure['url'];
        } elseif ($item->children('media', true)->content) {
            $image = (string) $item->children('media', true)->content->attributes()->url;
        }

        // Build HTML for each RSS item
        $output .= '<div class="rss-item">';
        $output .= '<div class="rss-title"><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($title) . '</a></div>';
        $output .= '<div class="rss-date">' . htmlspecialchars($date) . '</div>';
        if (!empty($image)) {
            $output .= '<div class="rss-image"><img src="' . htmlspecialchars($image) . '" alt="Feed Image"></div>';
        }
        $output .= '<div class="rss-description">' . htmlspecialchars($description) . '</div>';
        $output .= '</div>';

        $count++;
    }

    $output .= '</div>';

    return $output;
}
?>
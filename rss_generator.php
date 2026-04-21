<?php

// Set the content-type to XML
header("Content-Type: application/rss+xml; charset=UTF-8");

function extractValueFromPattern($filename, $pattern) {
    $markdownContent = file_get_contents("pages/posts/" . $filename . ".html");
    if (preg_match($pattern, $markdownContent, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

// Function to get MIME type from file extension
function getMimeType($url) {
    $path = parse_url($url, PHP_URL_PATH); // strips query string and fragment
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        // Add more extensions and MIME types as needed
    ];
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}

// Define patterns for the metadata you want to extract
$patterns = [
    'pagetitle'   => '/<!--\s+pagetitle:(.*?)\s+-->/s',
    'pagedate'    => '/<!--\s+pagedate:(.*?)\s+-->/s',
    'pageexcerpt' => '/<!--\s+pageexcerpt:(.*?)\s+-->/s',
    'pageimage'   => '/<!--\s+pageimage:(.*?)\s+-->/s' // Image pattern
];

// Site information
$siteTitle       = $WebsiteTitle;
$siteLink        = $WebsiteURL;
$siteDescription = $WebsiteDescription;
$defaultImage    = $WebsiteImage; // Default image URL

// Initialize the RSS feed
echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
echo '<rss version="2.0">' . "\n";
echo '<channel>' . "\n";
echo '<title>' . htmlspecialchars($siteTitle) . '</title>' . "\n";
echo '<link>' . htmlspecialchars($siteLink) . '</link>' . "\n";
echo '<description>' . htmlspecialchars($siteDescription) . '</description>' . "\n";
echo '<language>en-us</language>' . "\n";

// Channel-wide image
echo '<image>' . "\n";
echo '<url>' . htmlspecialchars($WebsiteImage) . '</url>' . "\n";
echo '<title>' . htmlspecialchars($siteTitle) . '</title>' . "\n";
echo '<link>' . htmlspecialchars($siteLink) . '</link>' . "\n";
echo '</image>' . "\n";

// Collect all items
$items = [];
$postsDir = __DIR__ . '/pages/posts';

foreach (new DirectoryIterator($postsDir) as $fileInfo) {
    if ($fileInfo->isDot() || $fileInfo->getExtension() !== 'html') continue;

    $filename = $fileInfo->getBasename('.html');
    $pageData = [];

    // Extract metadata using the patterns
    foreach ($patterns as $variableName => $pattern) {
        $pageData[$variableName] = extractValueFromPattern($filename, $pattern);
    }

    if (empty($pageData['pagetitle']) || empty($pageData['pagedate'])) continue;

    $url      = $siteLink . '/posts/' . $filename;
    $pubDate  = strtotime($pageData['pagedate']); // Use timestamp for sorting
    $imageUrl = !empty($pageData['pageimage']) ? $siteLink . '/' . $pageData['pageimage'] : $defaultImage;
    $mimeType = getMimeType($imageUrl);

    // Add the item to the array
    $items[] = [
        'title'       => htmlspecialchars($pageData['pagetitle']),
        'link'        => htmlspecialchars($url),
        'description' => htmlspecialchars($pageData['pageexcerpt']),
        'enclosure'   => '<enclosure url="' . htmlspecialchars($imageUrl) . '" type="' . htmlspecialchars($mimeType) . '" length="0" />',
        'pubDate'     => date(DATE_RSS, $pubDate),
        'timestamp'   => $pubDate // Store timestamp for sorting
    ];
}

// Sort items by date, latest first
usort($items, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

// Output sorted items
foreach ($items as $item) {
    echo '<item>' . "\n";
    echo '<title>' . $item['title'] . '</title>' . "\n";
    echo '<link>' . $item['link'] . '</link>' . "\n";
    echo '<description>' . $item['description'] . '</description>' . "\n";
    echo $item['enclosure'] . "\n";
    echo '<pubDate>' . $item['pubDate'] . '</pubDate>' . "\n";
    echo '</item>' . "\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";
?>

<?php
function parse_shortcodes($content) {
    // Define an array of shortcodes and their corresponding functions
    $shortcodes = [
        'rss' => 'shortcode_rss_feed',
        'pre' => 'shortcode_pre',
		'php' => 'shortcode_php',
        'video' => 'shortcode_video',
        // Add more shortcodes as needed
    ];

    // Regex to handle both self-closing and enclosing shortcodes.
    // Enclosed content may not contain another opening tag of the same
    // shortcode, so a self-closing [video ...] can't swallow everything up
    // to the [/video] of a later enclosing one.
    $pattern = '/\[(\w+)(?:\s+([^]]+))?\](?:((?:(?!\[\1[\s\]])[\s\S])*?)\[\/\1\])?/';

    // Use preg_replace_callback to process the shortcodes
    return preg_replace_callback($pattern, function ($matches) use ($shortcodes) {
        $shortcode = $matches[1];
        $attributes = isset($matches[2]) ? parse_shortcode_attributes($matches[2]) : [];
        $content = isset($matches[3]) ? $matches[3] : '';

        if (isset($shortcodes[$shortcode])) {
            return call_user_func($shortcodes[$shortcode], $attributes, $content);
        }

        // If no matching shortcode, return the original text
        return $matches[0];
    }, $content);
}

// Helper function to parse shortcode attributes
function parse_shortcode_attributes($attribute_string) {
    $attributes = [];
    // key="value" or key='value'. Each quote style may contain the other,
    // so caption="Scary's trailer" works. A bare word (e.g. [video ... loop])
    // is a flag and is treated as key="true".
    preg_match_all('/(\w+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $attribute_string, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (isset($match[3])) {
            $attributes[$match[1]] = $match[3];
        } elseif (isset($match[2])) {
            $attributes[$match[1]] = $match[2];
        } else {
            $attributes[$match[1]] = 'true';
        }
    }
    return $attributes;
}

foreach (glob("./required/shortcode-functions/*.php") as $shortcode_functions)
{
    include $shortcode_functions;
}
?>

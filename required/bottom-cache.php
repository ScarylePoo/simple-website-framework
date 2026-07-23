<?php
// Cache the contents to a cache file — but never persist meta/editor requests to disk
if (empty($skipCache)) {
    $cached = fopen($cachefile, 'w');
    fwrite($cached, ob_get_contents());
    fclose($cached);
}
ob_end_flush(); // Send the output to the browser
?>

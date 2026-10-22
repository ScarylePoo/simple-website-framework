<?php if(isset($_GET['meta'])) { 

function stripQueryFromUrl($url) {
    $parsedUrl = parse_url($url);
    $strippedUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    
    if (isset($parsedUrl['port'])) {
        $strippedUrl .= ':' . $parsedUrl['port'];
    }
    
    $strippedUrl .= $parsedUrl['path'];
    return $strippedUrl;
}

$strippedUrl = stripQueryFromUrl($currentURL);
?>

<div id="debug-overlay-container">
	<div class="debug-content">
		<strong>Metadata Information for "<?php echo $pagetitle; ?>"</strong></br>
		<strong>PageTitle:</strong> <?php echo $pagetitle; ?></br>
		<strong>Layout:</strong> <?php echo $pagelayout; ?></br>
		<strong>Date:</strong> <?php echo $pagedate; ?></br>
		<strong>Page Image:</strong> <?php echo $WebsiteURL . "/" . $pageimage; ?></br>
		<strong>Excerpt:</strong> <?php echo $pageexcerpt; ?></br>
		<strong>Keywords:</strong> <?php echo $pagekeywords; ?></br>
		<strong>Author:</strong> <?php echo $pageauthor; ?></br>
		<strong>Type:</strong> <?php echo $pagetype; ?></br>
		<strong>Website Title:</strong> <?php echo $WebsiteTitle; ?></br>
		<strong>Language/Country:</strong> <?php echo $WebsiteLanguageCountry; ?></br>
		<strong>Current URL:</strong> <?php echo $strippedUrl; ?></br>
		<strong>Language/Locale:</strong> <?php echo $WebsiteLanguageLocale; ?></br>
		<strong>Framework:</strong> Simple Website Framework <?php echo defined('SWF_VERSION') ? SWF_VERSION : 'unknown version'; ?></br>
	</div>
</div>
<?php } ?>
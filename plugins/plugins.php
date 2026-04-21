<?php /* 
In our system, managing plugins involves manual integration. You'll need to incorporate plugins directly into the framework. To control which plugins are active or inactive, set each one to true or false in config/config.php.

For a plugin to be operational, it must be loaded. This occurs only when the 'loadplugins' option within the 'config/config.php' file is set to true.

Now, let's talk about JavaScript and jQuery functionality. To ensure smooth operation and optimal performance, it's often best to call scripts at the end of the page (additionally, a lot of jquery scripts will outright fail if they are not called at the end of the page). This prevents them from delaying the initial page load. To facilitate this practice, we use a special variable named '$pluginCalledBelowContent'.

This variable serves as a container for HTML content that needs to be placed at the bottom of the page. Here's how it works:

1. You store the HTML content you need at the bottom of the page in a variable, let's call it '$myPluginContent'.
2. Then, you concatenate '$myPluginContent' with '$pluginCalledBelowContent' using the '.=' operator.
3. This ensures that your HTML content is appended to the content already stored in '$pluginCalledBelowContent'.

Here's an example:

$myPluginContent = '<div>HTML content specific to your plugin</div>';
$pluginCalledBelowContent = $pluginCalledBelowContent . $myPluginContent;

You can also concatenate using:
$pluginCalledBelowContent .= $myPluginContent;

I generally use the more verbose method because it is easier for a novice to understand what is happening.

You repeat this process for each plugin, gradually building up the content in '$pluginCalledBelowContent'. This approach guarantees that your plugins are integrated seamlessly, ensuring efficient execution and optimal performance.

P.S. Make sure that you escape each ' with a backslash, otherwise you will break the page. As an example:
var href = link.getAttribute(\'href\');

*/ ?>

<?php /* Initialize the variable */ $pluginCalledBelowContent = ""; ?>

<?php /* Include jQuery 3.7.1 */ ?>
<?php if ($jQuery == true) { ?>
	<script src="includes/jquery-3.7.1.min.js"></script>
	<script src="includes/jquery-migrate-3.4.0.min.js"></script>
<?php } ?>

<?php /* Add a class automatically to anchor links (Typically used for setting scroll-margin-top properties so that navigation bars don't cover the content */ ?>
<?php if ($anchorLinkAutoClass == true) {
	$anchorLinkAutoClassContent = '
	<script>
		document.addEventListener("DOMContentLoaded", function() {
			// Get all anchor links
			var anchorLinks = document.querySelectorAll("a");

			// Loop through each anchor link
			anchorLinks.forEach(function(link) {
				// Add your desired class to each anchor link
				link.classList.add("anchor-top-margin");
			});
		});
	</script>
	';
	$pluginCalledBelowContent = $pluginCalledBelowContent . $anchorLinkAutoClassContent;
} ?>

<?php /* Add a class automatically to anchor links and rewrite the target */ ?>
<?php if ($anchorLinkCurrentURLRewrite == true) {
	$anchorLinkCurrentURLRewriteContent = '
	<script>
		// Get the base URL of the current page
		var baseURL = window.location.protocol + \'//\' + window.location.host + window.location.pathname;

		// Function to update anchor links
		function updateAnchorLinks() {
			var anchorLinks = document.querySelectorAll(\'a[href^="#"]\');
			anchorLinks.forEach(function(link) {
				var href = link.getAttribute(\'href\');
				link.setAttribute(\'href\', baseURL + href);
			});
		}

		// Call the function when the DOM is ready
		document.addEventListener(\'DOMContentLoaded\', function() {
			updateAnchorLinks();
		});
	</script>
	';
	$pluginCalledBelowContent = $pluginCalledBelowContent . $anchorLinkCurrentURLRewriteContent;
} ?>

<?php /* If meta info is being shown, append ?meta=yes to every link so that you can easily browse the site in meta mode */ ?>
<?php if ($metaInfoBoxRewriteURL == true) {
	if(isset($_GET['meta'])) {
		$metaInfoBoxRewriteURLContent = '
		<script>
			// Get all the links on the page
			const links = document.querySelectorAll(\'a\');

			// Loop through each link
			links.forEach(link => {
				// Get the current href attribute value
				let href = link.getAttribute(\'href\');
				// Check if href is not null or empty
				if (href) {
					// Append "?meta=yes" to the href attribute
					href += (href.includes(\'?\') ? \'&\' : \'?\') + \'meta=yes\';
					// Update the href attribute with the modified value
					link.setAttribute(\'href\', href);
				}
			});
		</script>
		';
		$pluginCalledBelowContent = $pluginCalledBelowContent . $metaInfoBoxRewriteURLContent;
	}
} ?>

<?php /* FontAwesome 4.7.0 */ ?>
<?php if ($fontAwesome == true) { ?>
    <link type="text/css" href="plugins/font-awesome-4.7.0/css/font-awesome.css" rel="stylesheet" />
<?php } ?>

<?php /* yBox (Lightbox) */ ?>
<?php if ($yBox == true) {
	$yBoxScriptContent = '
	<link rel="stylesheet" href="plugins/yBox-main/dist/css/ybox.min.css" />
	<script type="text/javascript" src="plugins/yBox-main/dist/js/ybox.min.js?lang=he"></script>
	';
	$pluginCalledBelowContent = $pluginCalledBelowContent . $yBoxScriptContent;
} ?>

<?php /* Page Editor */ ?>
<?php if (isset($editorEnabled) && $editorEnabled == true) {
    include 'plugins/editor/editor.php';
} ?>
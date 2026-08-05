<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:og="http://ogp.me/ns#">
<head>
	<base href="<?php echo $WebsiteURL . '/'; ?>">
	<?php 
		/* This HTML code snippet sets the base URL for all relative URLs within the document using the <base> element. Here's what each part of the PHP code within the href attribute does:

		<?php echo ... ?>: This PHP code snippet is used to dynamically generate the value for the href attribute of the <base> element.

		'http' . ...: This part concatenates the string 'http' with additional strings to construct the base URL.

		(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : ''): This is a ternary conditional operator (?:) that checks if the HTTPS protocol is being used. If $_SERVER['HTTPS'] is set and equals 'on', it appends the letter 's' to the string (for https://), otherwise it appends an empty string.

		'://' . $_SERVER['HTTP_HOST']: This part appends the :// separator and the value of $_SERVER['HTTP_HOST'], which represents the host name from the request headers.

		'/': This part appends a trailing slash to ensure that the base URL is properly formatted.

		In summary, this HTML code sets the base URL for all relative URLs within the document to either http:// or https:// depending on the current protocol (http or https), followed by the host name from the request headers ($_SERVER['HTTP_HOST']). */
	?>
	
	<!-- Basic Page Needs
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<meta charset="utf-8">
	<title><?php if ($pagename != "home") { if (isset($pagetitle)) { echo ucwords($pagetitle) . " - "; } else { echo ucwords($pagename) . " - "; }} echo $WebsiteTitle; ?></title>
	
	<?php /* Set Language Country */ ?>
	<html lang="<?php echo $WebsiteLanguage; ?>">
	<html xmlns="http://www.w3.org/1999/xhtml" xmlns:og="http://ogp.me/ns#" lang="<?php echo $WebsiteLanguageCountry; ?>">
	
	<meta name="referrer" content="strict-origin">
	<link rel="canonical" href="<?php echo $currentURL; ?>">
	
	<?php /* Set a fallback page excerpt/description */ ?>
	<?php if ($pageexcerpt == "") { $pageexcerpt = $WebsiteDescription; }?>
	<meta name="description" content="<?php echo $pageexcerpt; ?>">
	
	<?php /* Set a fallback page author */ ?>
	<?php if ($pageauthor == "") { $pageauthor = $WebsiteAuthor; }?>
	<meta name="author" content="<?php echo $pageauthor; ?>">
	
	<?php /* Set a fallback page keywords */ ?>
	<?php if ($pagekeywords == "") { $pagekeywords = $WebsiteKeywords; }?>
	<meta name="keywords" content="<?php echo $pagekeywords; ?>">
	
	<meta name="robots" content="robots.txt">
	
	<?php /* Opengraph Garbage goes here */ ?>
	<meta property="og:title" content="<?php if (isset($pagetitle)) { echo ucwords($pagetitle); } else { echo ucwords($pagename); } ?> - <?php echo $WebsiteTitle; ?>">
	<meta property="og:description" content="<?php echo $pageexcerpt; ?>">
	<meta property="og:url" content="<?php echo $currentURL; ?>">
	
	<?php /* Set a default image */ ?>
	<?php if ($pageimage == "") { $pageimage = $WebsiteImage; } ?>
	<meta property="og:image" content="<?php echo $WebsiteURL . "/" . $pageimage; ?>">
	
	<?php /* Set a default pagetype (E.G. website, article, blog, profile, video, music, book, product) */ ?>
	<?php if ($pagetype == "") { $pagetype = "website"; }?>
	<meta property="og:type" content="<?php echo $pagetype ?>">
	
	<meta property="og:site_name" content="<?php echo $WebsiteTitle; ?>">
	<meta property="og:locale" content="<?php echo $WebsiteLanguageLocale; ?>">
	
	<?php /* Convert date to YYYY-MM-DD*/ ?>
	<?php	
		if ($pagedate != "") {
			$outputDateFormat1 = formatDate($pagedate, 'Y-m-d H:i:s');
			$pagedate = $outputDateFormat1;
		} else {
			// Get the last modified time of the file
			$pagefilename = "pages/" . $pagename .".html";
			$lastModifiedTime = filemtime($pagefilename);
			$lastModifiedDate = date("Y-m-d H:i:s", $lastModifiedTime);
			$pagedate = $lastModifiedDate;
		}
	?>
	<meta property="og:article:published_time" content="<?php echo $pagedate; ?>">



	<!-- Mobile Specific Metas
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<!-- FONT
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<?php /* Load the Font via CSS */ ?>

	<!-- CSS
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<link rel="stylesheet" href="css/normalize.css">
	<link rel="stylesheet" href="css/base.css">
	<link rel="stylesheet" href="css/flexgridsystem.css">
	<link rel="stylesheet" href="themes/<?php echo $theme; ?>/navigation.css">
	<link rel="stylesheet" href="themes/<?php echo $theme; ?>/custom.css">

	<!-- Favicon
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<link rel="icon" type="image/png" href="images/favicon.png">
	

</head>
<?php include 'required/metainfo.php'; ?>
<?php if ($loadplugins == true) { include 'plugins/plugins.php'; } ?>

	
	<!-- Beginning of actual page layout
	––––––––––––––––––––––––––––––––––––––––––––––––––
	 Everything above this line is metadata and SEO
	 and is unchanged from the skeleton theme.
	 Everything below is yours to rearrange.
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
<body>

	<a class="skiplink" href="#maincontent">Skip to content</a>

	<?php include 'navigation.php'; ?>

	<!-- HEADER ––––––––––––––––––––––––––––––––––––––
	 Plain text block, no background image, no fixed
	 height. Delete the whole .header div if you'd
	 rather start each page at the content.
	–––––––––––––––––––––––––––––––––––––––––––––––– -->
	<header class="header">
	<?php if ($pagename == "home") { ?>
		<div class="hero">
			<div class="herocontent">
				<span class="herotext"><?php echo $WebsiteTitle; ?></span>
				<p class="herosubtext"><?php echo $WebsiteDescription; ?></p>
			</div>
		</div>
	<?php } else { ?>
		<div class="herosmall">
			<div class="herocontent">
				<span class="herotext"><?php echo $WebsiteTitle; ?></span>
			</div>
		</div>
	<?php } ?>
	</header>

<a id="maincontent"></a>

<?php if (!isset($pagelayout) || $pagelayout == "") { $pagelayout = "page-md"; } /* Set pagelayout to basic page if it isn't explicitly set */ ?>
<?php include $pagelayout . ".php" ?>
<?php include 'footer.php'; ?>
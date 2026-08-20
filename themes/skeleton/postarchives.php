</head>
<body>

	<!-- Primary Page Layout
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<div class="contentcontainer">
		<div class="pagetitle">
		<?php if ($pagename != "home") { ?>
			<?php if (isset($pagetitle)) { ?>
				<h1><?php echo $pagetitle; ?></h1>
			<?php } else { ?>	
				<h1><?php echo ucwords($pagename); ?></h1>
			<?php } ?>
		<?php } ?>
		</div>
		<div class="content">
			<div class="section group">

				<?php
					// Folder containing the Article files
					$postsFolder = 'posts';

					// Array to store file details
					$fileDetails = array();

					// Loop through each file in the Article folder
					foreach (glob("pages/$postsFolder/*.html") as $file) {
						// Read the file contents
						$contents = file_get_contents($file);

						// Extract pagetitle, date, thumbnail, and excerpt from HTML comments
						preg_match('/<!--\s+pagetitle:(.*?)\s+-->/s', $contents, $titleMatch); //This will be the linktext
						preg_match('/<!--\s+pagedate:(.*?)\s+-->/s', $contents, $dateMatch); //This needs to be mm/dd/yyyy format
						preg_match('/<!--\s+pageimage:(.*?)\s+-->/s', $contents, $imageMatch); //Image filename with extension
						preg_match('/<!--\s+pageexcerpt:(.*?)\s+-->/s', $contents, $excerptMatch); //No real formatting here, just a blurb
						preg_match('/<!--\\s+pagecategory:(.*?)\\s+-->/s', $contents, $categoryMatch); //Optional. Comma-separated category list. Posts without it are treated as "uncategorized".

						// If all details are found, add them to the array
						if ($titleMatch && $dateMatch && $imageMatch && $excerptMatch) {
							$title = trim($titleMatch[1]);
							$date = trim($dateMatch[1]);
							$image = trim($imageMatch[1]);
							$excerpt = trim($excerptMatch[1]);
							// Categories are OPTIONAL. Legacy posts without a pagecategory tag must never be
							// dropped from archives, so this is deliberately NOT part of the required-fields
							// check above. Untagged posts are treated as belonging to "uncategorized".
							$categories = array();
							if ($categoryMatch) {
								foreach (explode(',', $categoryMatch[1]) as $cat) {
									$cat = strtolower(trim($cat));
									if ($cat !== '') { $categories[] = $cat; }
								}
							}
							if (empty($categories)) { $categories = array('uncategorized'); }
							$fileDetails[] = array('title' => $title, 'date' => $date, 'image' => $image, 'excerpt' => $excerpt, 'filename' => $file, 'categories' => $categories);
						}
					}

					/* CATEGORY FILTER */
					// $postcategory comes from this page's own <!-- postcategory: ... --> tag,
					// extracted by required/vitalfunctions.php. When the tag is absent (e.g. the
					// main archives page), no filtering happens and every post is listed.
					// A page with <!-- postcategory: uncategorized --> lists all untagged posts.
					if (!empty($postcategory)) {
						$filterCategory = strtolower(trim($postcategory));
						$fileDetails = array_values(array_filter($fileDetails, function ($post) use ($filterCategory) {
							return in_array($filterCategory, $post['categories']);
						}));
					}
					/* END CATEGORY FILTER */

					/* SORTING METHODS */

					// Sort the array by date in descending order
					usort($fileDetails, function ($a, $b) {
						return strtotime($b['date']) - strtotime($a['date']);
					});
					
					// Sort the array by title in descending order (A-Z)
					// usort($fileDetails, function ($a, $b) {
						// return strcmp($a['title'], $b['title']); // strcmp for string comparison (A-Z)
					// });
					
					/* END SORTING METHODS */

					// Pagination
					$itemsPerPage = 5;
					$page = isset($_GET['page']) ? $_GET['page'] : 1;
					$startIndex = ($page - 1) * $itemsPerPage;
					$fileDetailsPage = array_slice($fileDetails, $startIndex, $itemsPerPage);

					// Output the sorted list of links, images, and excerpts for the current page
					/* This is where you would set up the classes for styling the post archives page */
					foreach ($fileDetailsPage as $fileDetail) {
						$dateFormatted = date('m/d/Y', strtotime($fileDetail['date']));
						echo '<a href="' . $postsFolder . '/' . basename($fileDetail['filename'], '.html') . '">' . $fileDetail['title'] . '</a> - ' . $dateFormatted . '<br>';
						if (file_exists($fileDetail['image'])) {
							echo '<img src="' . $fileDetail['image'] . '" alt="' . $fileDetail['title'] . '"><br>';
						} else {
							echo 'Image not found for ' . $fileDetail['title'] . '<br>';
							echo 'Imagepath: ' . $fileDetail['image'];
						}
						echo '<p>' . $fileDetail['excerpt'] . '</p><br>';
					}

					// Pagination links
					$totalPages = ceil(count($fileDetails) / $itemsPerPage);
					echo '<div class="pagination">Page: ';
					for ($i = 1; $i <= $totalPages; $i++) {
						if ($i == $page) {
							echo "<span>$i</span> ";
						} else {
							echo "<a href='$pagename?page=$i'>$i</a> ";
						}
					}
					echo '</div>';
				?>

			</div>
		</div>
	</div>

<!-- End Document
  –––––––––––––––––––––––––––––––––––––––––––––––––– -->

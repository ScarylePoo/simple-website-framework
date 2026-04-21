</head>
<body>

	<!-- Primary Page Layout
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<div class="contentcontainer">
		<div class="pagetitle">
		<?php if ($pagename != "home" || $showhomepagetitle == true) { ?>
			<?php if (isset($pagetitle)) { ?>
				<h1><?php echo $pagetitle; ?></h1>
					<?php if ($pagetype == "article") { ?>
					
						<?php echo "<p class=\"pagedate\">" . formatDate($pagedate, 'pretty') . "</p>"; ?>
						<?php echo "<p class=\"pageauthor\"><i>Written by " . $pageauthor . "</i></p>"; ?>
					<?php } ?>
			<?php } else { ?>	
					<h1><?php echo ucwords($pagename); ?></h1>
			<?php } ?>
		<?php } ?>
		</div>
		<div class="content">
			<div class="section group">
					<?php
						$filename = file_get_contents("./pages/" . $pagename . ".html");
						$parsed_content = parse_shortcodes($filename);
						echo from_markdown($parsed_content);
					?>
			</div>
		</div>
	</div>

<!-- End Document
  –––––––––––––––––––––––––––––––––––––––––––––––––– -->
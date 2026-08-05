
	<!-- Full-bleed layout
	     No container, no measure, no page title.
	     The page file supplies its own <section>
	     elements and decides its own widths.

	     Turn it on with a pagelayout:page-blank
	     line in the page's metadata block.
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<main class="blanklayout">
		<?php
			$filename = file_get_contents("./pages/" . $pagename . ".html");
			$parsed_content = parse_shortcodes($filename);
			echo $parsed_content;
		?>
	</main>

<!-- End Document
  –––––––––––––––––––––––––––––––––––––––––––––––––– -->

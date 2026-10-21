
	<!-- Primary Page Layout
	–––––––––––––––––––––––––––––––––––––––––––––––––– -->
	<main class="contentcontainer">
		<div class="content">
			<div class="section group">
					<?php
						$filename = swf_page_source($pagename);
						$parsed_content = parse_shortcodes($filename);
						echo from_markdown($parsed_content);
					?>
			</div>
		</div>
	</main>

<!-- End Document
  –––––––––––––––––––––––––––––––––––––––––––––––––– -->
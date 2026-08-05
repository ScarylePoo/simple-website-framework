<div class="stickynav">
	
        <nav>
        <div class="stellarnav">
			<?php
				$navigationFilename = "./pages/navigation.html";
				$navigationOutput = file_get_contents($navigationFilename);
				echo from_markdown($navigationOutput);
			?>
        </div><!-- .stellarnav -->
        </nav>
    <div style="clear:both"></div>
</div>
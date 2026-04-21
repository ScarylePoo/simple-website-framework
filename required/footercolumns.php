<?php
// Initialize arrays to store footer content and section titles
$footerContents = [];
$sectionTitles = [];

// Scan the footer directory to determine how many footer files exist.
// Files must be named footer1.html, footer2.html, etc.
$footerDir = "pages/footer/";
$footerCount = 0;
for ($i = 1; file_exists($footerDir . "footer{$i}.html"); $i++) {
    $footerCount++;
}

// Iterate through the discovered footer files
for ($i = 1; $i <= $footerCount; $i++) {
    $footerFile = $footerDir . "footer{$i}.html";

    // Read the contents of the footer file
    $footerContent = file_get_contents($footerFile);

    // Extract the section title from the <!-- sectiontitle: --> comment
    $titlePattern = '/<!--\s*sectiontitle\s*:\s*(.*?)\s*-->/';
    if (preg_match($titlePattern, $footerContent, $titleMatches)) {
        $sectionTitles[] = trim($titleMatches[1]);
    } else {
        $sectionTitles[] = "Section {$i}"; // Default title if not found
    }

    // Extract the footer type from the <!-- footertype: --> comment.
    // Accepted values: html, md
    // Defaults to html if the comment is absent.
    $typePattern = '/<!--\s*footertype\s*:\s*(.*?)\s*-->/';
    if (preg_match($typePattern, $footerContent, $typeMatches)) {
        $footerType = strtolower(trim($typeMatches[1]));
    } else {
        $footerType = "html"; // Default to html
    }

    // Render the content based on the footer type
    if ($footerType === "md") {
        $footerContents[] = from_markdown($footerContent);
    } else {
        $footerContents[] = $footerContent;
    }
}

// Output each footer column
echo '<div class="row">';
foreach ($footerContents as $index => $footerContent) {
    echo '<div class="column flex-basis-300">';
    echo '<span class="sectiontitle">' . $sectionTitles[$index] . '</span>';
    echo $footerContent;
    echo '</div>';
}
echo '</div>';
?>

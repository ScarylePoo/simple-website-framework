<?php

function formatDate($inputDate, $outputDateFormat) {
    $inputDate = trim($inputDate);

    if (empty($inputDate)) {
        return false;
    }

    $dateTime = false;

    // --- Explicit formats first ---
    // These are tried before strtotime because some are ambiguous
    // and strtotime would guess wrong (e.g. m/d/Y vs d/m/Y).

    $explicitFormats = [
        'Y-m-d H:i:s',     // 2024-04-20 14:30:00
        'Y-m-d H:i',       // 2024-04-20 14:30
        'Y-m-d',           // 2024-04-20
        'd/m/Y H:i:s',     // 20/04/2024 14:30:00
        'd/m/Y H:i',       // 20/04/2024 14:30
        'm/d/Y H:i:s',     // 04/20/2024 14:30:00
        'm/d/Y H:i',       // 04/20/2024 14:30
        'm/d/Y',           // 04/20/2024
        'm/d/y',           // 04/20/24
        'd-m-Y',           // 20-04-2024
        'Y/m/d',           // 2024/04/20
        'd.m.Y',           // 20.04.2024
        'l F jS, Y',       // Saturday April 20th, 2024
        'l, F jS, Y',      // Saturday, April 20th, 2024
        'F jS, Y',         // April 20th, 2024
        'F j, Y',          // April 20, 2024
        'F Y',             // April 2024
        'F j Y',           // April 20 2024
        'j F Y',           // 20 April 2024
        'D, d M Y H:i:s O', // RFC 2822: Sat, 20 Apr 2024 14:30:00 +0000
        'D, d M Y H:i:s',  // Sat, 20 Apr 2024 14:30:00
        'd M Y',           // 20 Apr 2024
        'd M Y H:i:s',     // 20 Apr 2024 14:30:00
        'M d, Y',          // Apr 20, 2024
        'M j, Y',          // Apr 20, 2024 (no leading zero)
        'M d Y',           // Apr 20 2024
        'U',               // Unix timestamp
    ];

    foreach ($explicitFormats as $format) {
        $attempt = date_create_from_format($format, $inputDate);
        if ($attempt !== false) {
            // Verify the date is actually valid (catches format matches on garbage input)
            $errors = date_get_last_errors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                $dateTime = $attempt;
                break;
            }
        }
    }

    // --- strtotime fallback ---
    // Handles natural language and any written format not caught above:
    // "today", "next Friday", "20 April 2024", "April 20th 2024",
    // "2024-04-20T14:30:00Z" (ISO 8601), "20th of April 2024", etc.
    if ($dateTime === false) {
        $timestamp = strtotime($inputDate);
        if ($timestamp !== false) {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($timestamp);
        }
    }

    if ($dateTime === false) {
        return false;
    }

    // --- Output ---
    switch ($outputDateFormat) {
        case 'Y-m-d H:i:s':
            return $dateTime->format('Y-m-d H:i:s');
        case 'pretty':
            return $dateTime->format('l F jS, Y');
        default:
            // Allow any arbitrary PHP date format string to be passed in
            return $dateTime->format($outputDateFormat);
    }
}

/*
Example usage:

    $dates = [
        "4/20/2026",
        "2026-04-20",
        "April 20, 2026",
        "20 April 2026",
        "20th of April 2026",
        "Sunday April 20th, 2026",
        "next Friday",
        "2026-04-20T14:30:00Z",
        "Apr 20 2026",
        "20.04.2026",
    ];

    foreach ($dates as $date) {
        echo formatDate($date, 'pretty') . "\n";
        echo formatDate($date, 'Y-m-d H:i:s') . "\n";
    }
*/
?>
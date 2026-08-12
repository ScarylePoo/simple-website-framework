<?php
/*
    editor-qr.php
    Minimal, self-contained QR code encoder — byte mode, error correction
    level M, versions 1 to 10. Outputs SVG. No GD, no Imagick, no libraries,
    no network calls.

    This exists solely so the TOTP enrolment screen can show a scannable code
    without sending your secret to a hosted QR generator, which would defeat
    the entire point of the exercise.

    Only loaded by the enrolment screen. If you delete this file, enrolment
    falls back to typing the Base32 secret into your app by hand, which every
    authenticator supports. Nothing else in the editor depends on it.

    Version 10 at level M holds 213 bytes; otpauth:// URIs run to roughly 120,
    so there is comfortable headroom.
*/

if (!defined('SWF_EDITOR')) {
    die('Direct access not permitted.');
}

/*
    Per-version tables for error correction level M.
    [ data codewords, ec codewords per block, group1 blocks, group1 data cw,
      group2 blocks, group2 data cw ]
*/
function editor_qr_spec($version) {
    static $table = [
        1  => [16,  10, 1, 16, 0, 0],
        2  => [28,  16, 1, 28, 0, 0],
        3  => [44,  26, 1, 44, 0, 0],
        4  => [64,  18, 2, 32, 0, 0],
        5  => [86,  24, 2, 43, 0, 0],
        6  => [108, 16, 4, 27, 0, 0],
        7  => [124, 18, 4, 31, 0, 0],
        8  => [154, 22, 2, 38, 2, 39],
        9  => [182, 22, 3, 36, 2, 37],
        10 => [216, 26, 4, 43, 1, 44],
    ];
    return $table[$version] ?? null;
}

/*
    Alignment pattern centre coordinates per version.
*/
function editor_qr_alignment_centres($version) {
    static $table = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];
    return $table[$version] ?? [];
}

/*
    Pre-computed 15-bit format strings for level M, masks 0-7,
    already XORed with the 0x5412 mask required by the spec.
*/
function editor_qr_format_bits($mask) {
    static $table = [0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0];
    return $table[$mask];
}

/*
    18-bit version information, required for version 7 and above only.
*/
function editor_qr_version_bits($version) {
    static $table = [
        7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3,
    ];
    return $table[$version] ?? null;
}

/*
    Smallest version that fits the payload. Byte mode uses an 8-bit character
    count for versions 1-9 and a 16-bit count from version 10, so the overhead
    differs by one byte across that boundary.
*/
function editor_qr_pick_version($length) {
    for ($v = 1; $v <= 10; $v++) {
        $spec     = editor_qr_spec($v);
        $overhead = ($v >= 10) ? 3 : 2;  // 4-bit mode + count field, rounded up
        if ($length + $overhead <= $spec[0]) {
            return $v;
        }
    }
    return null;  // too long — caller falls back to manual entry
}

/*
    Galois field GF(256) log/antilog tables, primitive polynomial 0x11D.
*/
function editor_qr_gf_tables() {
    static $exp = null, $log = null;
    if ($exp === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x   = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
    }
    return [$exp, $log];
}

function editor_qr_gf_mul($a, $b) {
    if ($a === 0 || $b === 0) {
        return 0;
    }
    list($exp, $log) = editor_qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

/*
    Reed-Solomon generator polynomial of the given degree.
*/
function editor_qr_rs_generator($degree) {
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        list($exp,) = editor_qr_gf_tables();
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $coeff) {
            $next[$j]     ^= editor_qr_gf_mul($coeff, 1);
            $next[$j + 1] ^= editor_qr_gf_mul($coeff, $exp[$i]);
        }
        // The first term above is just $coeff; written out for clarity
        $poly = $next;
    }
    return $poly;
}

/*
    Error correction codewords for one block.
*/
function editor_qr_rs_encode($data, $ecLength) {
    $generator = editor_qr_rs_generator($ecLength);
    $remainder = array_fill(0, $ecLength, 0);

    foreach ($data as $byte) {
        $factor = $byte ^ $remainder[0];
        array_shift($remainder);
        $remainder[] = 0;
        for ($i = 0; $i < $ecLength; $i++) {
            $remainder[$i] ^= editor_qr_gf_mul($generator[$i + 1], $factor);
        }
    }
    return $remainder;
}

/*
    Build the full codeword stream: encode, pad, split into blocks, generate
    error correction, then interleave as the spec requires.
*/
function editor_qr_build_codewords($text, $version) {
    $spec = editor_qr_spec($version);
    list($totalData, $ecPerBlock, $g1Blocks, $g1Size, $g2Blocks, $g2Size) = $spec;

    $countBits = ($version >= 10) ? 16 : 8;

    // Mode indicator 0100 (byte), then character count, then the data
    $bits = '0100';
    $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }

    // Terminator: up to four zero bits
    $capacityBits = $totalData * 8;
    $terminator   = min(4, $capacityBits - strlen($bits));
    $bits .= str_repeat('0', $terminator);

    // Pad to a byte boundary
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }

    // Alternating pad bytes 0xEC, 0x11 until full
    $padBytes = ['11101100', '00010001'];
    $padIndex = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= $padBytes[$padIndex % 2];
        $padIndex++;
    }

    $dataCodewords = [];
    foreach (str_split($bits, 8) as $byte) {
        $dataCodewords[] = bindec($byte);
    }

    // Split into blocks
    $blocks   = [];
    $ecBlocks = [];
    $pos      = 0;

    for ($i = 0; $i < $g1Blocks; $i++) {
        $block      = array_slice($dataCodewords, $pos, $g1Size);
        $pos       += $g1Size;
        $blocks[]   = $block;
        $ecBlocks[] = editor_qr_rs_encode($block, $ecPerBlock);
    }
    for ($i = 0; $i < $g2Blocks; $i++) {
        $block      = array_slice($dataCodewords, $pos, $g2Size);
        $pos       += $g2Size;
        $blocks[]   = $block;
        $ecBlocks[] = editor_qr_rs_encode($block, $ecPerBlock);
    }

    // Interleave data codewords column-wise across blocks
    $result   = [];
    $maxData  = max($g1Size, $g2Size);
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $block) {
            if (isset($block[$i])) {
                $result[] = $block[$i];
            }
        }
    }
    // Then the error correction codewords, likewise interleaved
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $block) {
            if (isset($block[$i])) {
                $result[] = $block[$i];
            }
        }
    }

    return $result;
}

/*
    Lay out the function patterns: finders, separators, timing, alignment,
    and the reserved areas for format and version information.
    $reserved marks every module that data must skip over.
*/
function editor_qr_place_function_patterns($version, &$matrix, &$reserved) {
    $size = $version * 4 + 17;

    $matrix   = array_fill(0, $size, array_fill(0, $size, 0));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    // Finder patterns plus their separators, at three corners
    $finders = [[0, 0], [$size - 7, 0], [0, $size - 7]];
    foreach ($finders as list($fr, $fc)) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $fr + $r;
                $cc = $fc + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }
                $inRing   = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                         || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                $inCentre = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrix[$rr][$cc]   = ($inRing || $inCentre) ? 1 : 0;
                $reserved[$rr][$cc] = true;
            }
        }
    }

    // Timing patterns
    for ($i = 8; $i < $size - 8; $i++) {
        $bit = ($i % 2 === 0) ? 1 : 0;
        $matrix[6][$i]   = $bit;
        $reserved[6][$i] = true;
        $matrix[$i][6]   = $bit;
        $reserved[$i][6] = true;
    }

    // Alignment patterns, skipping any that would collide with a finder
    $centres = editor_qr_alignment_centres($version);
    foreach ($centres as $cr) {
        foreach ($centres as $cc) {
            $nearFinder = ($cr <= 8 && $cc <= 8)
                       || ($cr <= 8 && $cc >= $size - 9)
                       || ($cr >= $size - 9 && $cc <= 8);
            if ($nearFinder) {
                continue;
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $isDark = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                    $matrix[$cr + $r][$cc + $c]   = $isDark ? 1 : 0;
                    $reserved[$cr + $r][$cc + $c] = true;
                }
            }
        }
    }

    // The dark module, always set
    $matrix[$size - 8][8]   = 1;
    $reserved[$size - 8][8] = true;

    // Reserve the format information areas
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[8][$size - 1 - $i] = true;
        $reserved[$size - 1 - $i][8] = true;
    }

    // Reserve the version information areas, version 7 and above
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $reserved[$i][$size - 11 + $j] = true;
                $reserved[$size - 11 + $j][$i] = true;
            }
        }
    }
}

/*
    Walk the data codewords into the matrix, two columns at a time, upward then
    downward, skipping the vertical timing column at index 6.
*/
function editor_qr_place_data($codewords, &$matrix, $reserved) {
    $size = count($matrix);

    $bits = '';
    foreach ($codewords as $cw) {
        $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }

    $index     = 0;
    $bitLength = strlen($bits);
    $upward    = true;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col = 5;  // skip the timing column
        }
        for ($i = 0; $i < $size; $i++) {
            $row = $upward ? ($size - 1 - $i) : $i;
            foreach ([$col, $col - 1] as $c) {
                if ($reserved[$row][$c]) {
                    continue;
                }
                $matrix[$row][$c] = ($index < $bitLength) ? (int) $bits[$index] : 0;
                $index++;
            }
        }
        $upward = !$upward;
    }
}

/*
    The eight mask conditions from the spec.
*/
function editor_qr_mask_condition($mask, $row, $col) {
    switch ($mask) {
        case 0: return ($row + $col) % 2 === 0;
        case 1: return $row % 2 === 0;
        case 2: return $col % 3 === 0;
        case 3: return ($row + $col) % 3 === 0;
        case 4: return (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0;
        case 5: return (($row * $col) % 2) + (($row * $col) % 3) === 0;
        case 6: return (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0;
        case 7: return (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0;
    }
    return false;
}

/*
    The four penalty rules used to choose the least visually confusing mask.
*/
function editor_qr_penalty($matrix) {
    $size    = count($matrix);
    $penalty = 0;

    // Rule 1: runs of five or more same-coloured modules in a row or column
    for ($i = 0; $i < $size; $i++) {
        for ($dir = 0; $dir < 2; $dir++) {
            $run     = 1;
            $previous = -1;
            for ($j = 0; $j < $size; $j++) {
                $value = $dir === 0 ? $matrix[$i][$j] : $matrix[$j][$i];
                if ($value === $previous) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run      = 1;
                    $previous = $value;
                }
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
        }
    }

    // Rule 2: 2x2 blocks of the same colour
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $matrix[$r][$c];
            if ($v === $matrix[$r][$c + 1]
             && $v === $matrix[$r + 1][$c]
             && $v === $matrix[$r + 1][$c + 1]) {
                $penalty += 3;
            }
        }
    }

    // Rule 3: finder-like patterns
    $patternA = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $patternB = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c <= $size - 11; $c++) {
            $rowSlice = [];
            $colSlice = [];
            for ($k = 0; $k < 11; $k++) {
                $rowSlice[] = $matrix[$r][$c + $k];
                $colSlice[] = $matrix[$c + $k][$r];
            }
            if ($rowSlice === $patternA || $rowSlice === $patternB) {
                $penalty += 40;
            }
            if ($colSlice === $patternA || $colSlice === $patternB) {
                $penalty += 40;
            }
        }
    }

    // Rule 4: deviation from an even balance of dark and light
    $dark = 0;
    foreach ($matrix as $row) {
        $dark += array_sum($row);
    }
    $percent   = ($dark * 100) / ($size * $size);
    $deviation = (int) (abs($percent - 50) / 5);
    $penalty  += $deviation * 10;

    return $penalty;
}

/*
    Write the format information for a chosen mask into both of its copies.
*/
function editor_qr_place_format(&$matrix, $mask) {
    $size = count($matrix);
    $bits = editor_qr_format_bits($mask);

    for ($i = 0; $i < 15; $i++) {
        // The spec writes the format string most significant bit first
        $bit = ($bits >> (14 - $i)) & 1;

        // First copy, around the top-left finder
        if ($i < 6) {
            $matrix[8][$i] = $bit;
        } elseif ($i === 6) {
            $matrix[8][7] = $bit;
        } elseif ($i === 7) {
            $matrix[8][8] = $bit;
        } elseif ($i === 8) {
            $matrix[7][8] = $bit;
        } else {
            $matrix[14 - $i][8] = $bit;
        }

        // Second copy, split between the other two finders.
        // The vertical run stops at seven modules — the eighth position down
        // there is the permanently dark module, not part of the format string.
        if ($i < 7) {
            $matrix[$size - 1 - $i][8] = $bit;
        } else {
            $matrix[8][$size - 15 + $i] = $bit;
        }
    }
}

/*
    Write version information, version 7 and above only.
*/
function editor_qr_place_version(&$matrix, $version) {
    if ($version < 7) {
        return;
    }
    $size = count($matrix);
    $bits = editor_qr_version_bits($version);

    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $r   = intdiv($i, 3);
        $c   = $i % 3;
        $matrix[$r][$size - 11 + $c] = $bit;
        $matrix[$size - 11 + $c][$r] = $bit;
    }
}

/*
    Encode text to a QR matrix. Returns a 2D array of 0/1, or null if the
    payload exceeds what version 10 at level M can carry.
*/
function editor_qr_encode($text) {
    $version = editor_qr_pick_version(strlen($text));
    if ($version === null) {
        return null;
    }

    $codewords = editor_qr_build_codewords($text, $version);

    $best      = null;
    $bestScore = null;

    for ($mask = 0; $mask < 8; $mask++) {
        editor_qr_place_function_patterns($version, $matrix, $reserved);
        editor_qr_place_data($codewords, $matrix, $reserved);

        // Apply the mask to data modules only
        $size = count($matrix);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c] && editor_qr_mask_condition($mask, $r, $c)) {
                    $matrix[$r][$c] ^= 1;
                }
            }
        }

        editor_qr_place_format($matrix, $mask);
        editor_qr_place_version($matrix, $version);

        $score = editor_qr_penalty($matrix);
        if ($bestScore === null || $score < $bestScore) {
            $bestScore = $score;
            $best      = $matrix;
        }
    }

    return $best;
}

/*
    Render a matrix as inline SVG. The quiet zone of four modules is part of
    the spec — scanners need it, so it is not optional decoration.
*/
function editor_qr_svg($text, $moduleSize = 5, $quietZone = 4) {
    $matrix = editor_qr_encode($text);
    if ($matrix === null) {
        return '';
    }

    $size  = count($matrix);
    $total = ($size + $quietZone * 2) * $moduleSize;

    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="' . $total . '" ';
    $svg .= 'viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" ';
    $svg .= 'aria-label="TOTP enrolment QR code">';
    $svg .= '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>';

    // One path for every dark module keeps the markup small
    $path = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($matrix[$r][$c]) {
                $x = ($c + $quietZone) * $moduleSize;
                $y = ($r + $quietZone) * $moduleSize;
                $path .= 'M' . $x . ' ' . $y . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
            }
        }
    }
    $svg .= '<path d="' . $path . '" fill="#000000"/>';
    $svg .= '</svg>';

    return $svg;
}

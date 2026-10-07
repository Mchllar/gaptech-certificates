<?php
/**
 * The certificate sheet: A4 landscape, reproducing the printed GAPTECH sheet.
 * Left: compliance certificate.  Top right: warranty card.  Bottom right: office copy and vehicle sticker.
 *
 * Expects: $d (the certificate snapshot), $assets (URL or file:// path to /public/assets),
 *          $zoom (1 sheet unit -> CSS px factor), $qr, $certno_img, $logo, $logo_gold, $kebs,
 *          $sticker_bg, $microtext.
 *
 * Coordinates are "sheet units": the original sheet measured at 200 dpi (2338 x 1653).
 * Do not retype them - they were measured from the printed certificate.
 */
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Rough text width in sheet units, used to shrink values that would overflow their line. */
function sheet_text_width($text, $font, $size)
{
    $bold = in_array($font, ['AB', 'CB'], true);
    $narrow = in_array($font, ['C', 'CB'], true);   // Calibri/Carlito is narrower than Arial
    $w = 0.0;
    for ($i = 0; $i < strlen($text); $i++) {
        $ch = $text[$i];
        if ($ch === ' ') { $w += 0.28; }
        elseif (ctype_upper($ch) || ctype_digit($ch)) { $w += $bold ? 0.68 : 0.62; }
        elseif (ctype_lower($ch)) { $w += $bold ? 0.56 : 0.51; }
        else { $w += 0.33; }
    }
    if ($narrow) { $w *= 0.92; }
    return $w * $size;
}

/** Largest font size up to $maxSize at which $text fits in $maxWidth sheet units. */
function fit($text, $font, $maxWidth, $maxSize)
{
    $text = (string)$text;
    if ($text === '') { return $maxSize; }
    $width = sheet_text_width($text, $font, $maxSize);
    if ($width <= $maxWidth) { return $maxSize; }
    return round($maxSize * $maxWidth / $width, 1);
}

function t($x, $cy, $size, $cls, $text, $style = '')
{
    $top = round($cy - $size * 0.52, 1);
    return '<div class="t ' . $cls . '" style="left:' . $x . 'px; top:' . $top . 'px; font-size:'
        . $size . 'px; ' . $style . '">' . e($text) . '</div>';
}

function tc($x1, $x2, $cy, $size, $cls, $text, $style = '')
{
    $top = round($cy - $size * 0.52, 1);
    return '<div class="t ' . $cls . '" style="left:' . $x1 . 'px; width:' . ($x2 - $x1)
        . 'px; text-align:center; top:' . $top . 'px; font-size:' . $size . 'px; ' . $style . '">'
        . e($text) . '</div>';
}

function ln($x1, $x2, $y)
{
    return '<div class="ln" style="left:' . $x1 . 'px; width:' . ($x2 - $x1) . 'px; top:' . $y . 'px;"></div>';
}

/** A value typed onto an underline, centred and shrunk to fit. */
function val($x1, $x2, $y, $size, $cls, $font, $text, $lift = 6, $style = '')
{
    $s = fit($text, $font, $x2 - $x1 - 8, $size);
    $top = round($y - $s - $lift, 1);
    return '<div class="t ' . $cls . '" style="left:' . $x1 . 'px; width:' . ($x2 - $x1)
        . 'px; text-align:center; top:' . $top . 'px; font-size:' . $s . 'px; ' . $style . '">'
        . e($text) . '</div>';
}

/** The stamped-looking certificate number. */
function certno($x, $cy, $h)
{
    global $certno_img;
    return '<img class="img" src="' . $certno_img . '" style="left:' . $x . 'px; top:' . ($cy - $h / 2)
        . 'px; height:' . $h . 'px;">';
}

function signature($x, $y, $w, $h)
{
    global $d;
    if (!empty($d['signatory']['signature_image'])) {
        return '<img class="img" src="' . e($d['signatory']['signature_image']) . '" style="left:' . $x
            . 'px; top:' . $y . 'px; width:' . $w . 'px; height:' . $h . 'px;">';
    }
    return '<div class="sigph" style="left:' . $x . 'px; top:' . $y . 'px; width:' . $w . 'px; height:'
        . $h . 'px; line-height:' . $h . 'px;">[signature]</div>';
}

function logo_lockup($x, $y, $w, $tx, $cy1, $s1, $cy2, $s2)
{
    global $d, $logo;
    $html = '<img class="img" src="' . $logo . '" style="left:' . $x . 'px; top:' . $y . 'px; width:' . $w . 'px;">';
    $html .= '<div class="t" style="left:' . $tx . 'px; top:' . round($cy1 - $s1 * 0.52, 1) . 'px; font-size:'
        . $s1 . 'px;"><span class="ai blue">GAPTECH</span> <span class="abi">Solutions Ltd</span></div>';
    $html .= t($tx, $cy2, $s2, 'abi red', $d['company']['tagline']);
    return $html;
}

function address($x1, $x2, $top, $size, $lh, $align = 'left')
{
    global $d;
    $c = $d['company'];
    $lines = '';
    foreach ($c['address_lines'] as $line) {
        $lines .= e($line) . '<br>';
    }
    $lines .= 'Cell: ' . e($c['cell']) . '<br>Landline: ' . e($c['landline']) . '<br>Email: ' . e($c['email']);
    return '<div class="t c" style="left:' . $x1 . 'px; width:' . ($x2 - $x1) . 'px; top:' . $top
        . 'px; font-size:' . $size . 'px; line-height:' . $lh . 'px; text-align:' . $align
        . '; white-space:nowrap;">' . $lines . '</div>';
}
?>
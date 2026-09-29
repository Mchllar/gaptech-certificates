<?php
/**
 * QR code generator (byte mode, error-correction level L, versions 1-10).
 *
 * Self-contained so the system needs no Composer packages or internet access.
 * Verified against a reference implementation for every version and mask.
 */
class QrCode
{
    /** version => [ec codewords per block, [[number of blocks, data codewords per block], ...]] */
    private static $ecL = [
        1 => [7, [[1, 19]]], 2 => [10, [[1, 34]]], 3 => [15, [[1, 55]]], 4 => [20, [[1, 80]]],
        5 => [26, [[1, 108]]], 6 => [18, [[2, 68]]], 7 => [20, [[2, 78]]], 8 => [24, [[2, 97]]],
        9 => [30, [[2, 116]]], 10 => [18, [[2, 68], [2, 69]]],
    ];
    private static $align = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    private static $exp = [];
    private static $log = [];

    /** QR code as an <svg> string. */
    public static function svg($text, $quiet = 1)
    {
        $m = self::encode($text);
        $n = count($m);
        $size = $n + 2 * $quiet;
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c]) {
                    $path .= 'M' . ($c + $quiet) . ' ' . ($r + $quiet) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" '
            . 'shape-rendering="crispEdges"><rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#111"/></svg>';
    }

    /** QR code as a data: URI, ready for an <img src>. */
    public static function dataUri($text, $quiet = 1)
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $quiet));
    }

    /** The finished module matrix: $m[row][col], 1 = dark. */
    public static function encode($text)
    {
        self::initGf();
        $version = self::pickVersion($text);
        $data = self::interleave(self::bitString($text, $version), $version);
        $size = 0;
        $base = self::baseMatrix($version, $size);
        $reserved = self::placeData($base, $size, $data);

        $best = null;
        $bestScore = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::applyMask($base, $size, $reserved, $mask);
            self::putFormat($cand, $size, $mask);
            self::putVersion($cand, $size, $version);
            $score = self::penalty($cand, $size);
            if ($bestScore === null || $score < $bestScore) {
                $best = $cand;
                $bestScore = $score;
            }
        }
        return $best;
    }

    // ---------------------------------------------------------------- maths

    private static function initGf()
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul($a, $b)
    {
        if ($a == 0 || $b == 0) {
            return 0;
        }
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function generatorPoly($n)
    {
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            $new = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $c) {
                $new[$j] ^= $c;
                $new[$j + 1] ^= self::gfMul($c, self::$exp[$i]);
            }
            $poly = $new;
        }
        return $poly;
    }

    private static function ecCodewords($data, $n)
    {
        $gen = self::generatorPoly($n);
        $rem = array_merge($data, array_fill(0, $n, 0));
        $len = count($data);
        for ($i = 0; $i < $len; $i++) {
            $factor = $rem[$i];
            if ($factor) {
                foreach ($gen as $j => $g) {
                    $rem[$i + $j] ^= self::gfMul($g, $factor);
                }
            }
        }
        return array_slice($rem, $len);
    }

    private static function bitLength($v)
    {
        $n = 0;
        while ($v) {
            $n++;
            $v >>= 1;
        }
        return $n;
    }

    private static function bch($value, $generator, $genBits)
    {
        $v = $value << ($genBits - 1);
        while (self::bitLength($v) >= $genBits) {
            $v ^= $generator << (self::bitLength($v) - $genBits);
        }
        return $v;
    }

    private static function formatBits($mask)
    {
        $value = (1 << 3) | $mask;            // 01 = error-correction level L
        return (($value << 10) | self::bch($value, 0x537, 11)) ^ 0x5412;
    }

    private static function versionBits($version)
    {
        return ($version << 12) | self::bch($version, 0x1F25, 13);
    }

    // ---------------------------------------------------------------- data

    private static function capacity($version)
    {
        list($ec, $groups) = self::$ecL[$version];
        $total = 0;
        foreach ($groups as $g) {
            $total += $g[0] * $g[1];
        }
        return $total;
    }

    private static function pickVersion($text)
    {
        $len = strlen($text);
        for ($v = 1; $v <= 10; $v++) {
            $header = 4 + ($v < 10 ? 8 : 16);
            if ($len * 8 + $header <= self::capacity($v) * 8) {
                return $v;
            }
        }
        throw new RuntimeException('Text is too long for a version 10 QR code.');
    }

    private static function bitString($text, $version)
    {
        $bits = '0100';                                   // byte mode
        $countBits = $version < 10 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < strlen($text); $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $total = self::capacity($version) * 8;
        $bits .= str_repeat('0', min(4, $total - strlen($bits)));          // terminator
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);             // pad to whole bytes
        $pads = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $total) {
            $bits .= $pads[$i % 2];
            $i++;
        }
        return $bits;
    }

    private static function interleave($bits, $version)
    {
        list($ecCount, $groups) = self::$ecL[$version];
        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }
        $blocks = [];
        $ecBlocks = [];
        $pos = 0;
        foreach ($groups as $g) {
            for ($k = 0; $k < $g[0]; $k++) {
                $block = array_slice($codewords, $pos, $g[1]);
                $pos += $g[1];
                $blocks[] = $block;
                $ecBlocks[] = self::ecCodewords($block, $ecCount);
            }
        }
        $longest = 0;
        foreach ($blocks as $b) {
            $longest = max($longest, count($b));
        }
        $out = [];
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b)) {
                    $out[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecCount; $i++) {
            foreach ($ecBlocks as $b) {
                $out[] = $b[$i];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- matrix

    private static function baseMatrix($version, &$size)
    {
        $size = $version * 4 + 17;
        $m = array_fill(0, $size, array_fill(0, $size, null));

        $finder = function ($r, $c) use (&$m, $size) {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $rr = $r + $dr;
                    $cc = $c + $dc;
                    if ($rr >= 0 && $rr < $size && $cc >= 0 && $cc < $size) {
                        $inside = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6);
                        $dark = $inside && ($dr == 0 || $dr == 6 || $dc == 0 || $dc == 6
                            || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                        $m[$rr][$cc] = $dark ? 1 : 0;
                    }
                }
            }
        };
        $finder(0, 0);
        $finder(0, $size - 7);
        $finder($size - 7, 0);

        for ($i = 0; $i < $size; $i++) {                                   // timing patterns
            if ($m[6][$i] === null) {
                $m[6][$i] = ($i % 2 == 0) ? 1 : 0;
            }
            if ($m[$i][6] === null) {
                $m[$i][6] = ($i % 2 == 0) ? 1 : 0;
            }
        }

        $coords = self::$align[$version];                                  // alignment patterns
        $last = $coords ? $coords[count($coords) - 1] : 0;
        foreach ($coords as $r) {
            foreach ($coords as $c) {
                if (($r == 6 && $c == 6) || ($r == 6 && $c == $last) || ($r == $last && $c == 6)) {
                    continue;                                              // these clash with the finders
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $m[$r + $dr][$c + $dc] = (max(abs($dr), abs($dc)) != 1) ? 1 : 0;
                    }
                }
            }
        }

        $m[$size - 8][8] = 1;                                              // the always-dark module
        for ($i = 0; $i < 9; $i++) {                                       // reserve format areas
            if ($m[8][$i] === null) {
                $m[8][$i] = 0;
            }
            if ($m[$i][8] === null) {
                $m[$i][8] = 0;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            if ($m[8][$size - 1 - $i] === null) {
                $m[8][$size - 1 - $i] = 0;
            }
            if ($m[$size - 1 - $i][8] === null) {
                $m[$size - 1 - $i][8] = 0;
            }
        }
        if ($version >= 7) {                                               // reserve version areas
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $m[$size - 11 + $j][$i] = 0;
                    $m[$i][$size - 11 + $j] = 0;
                }
            }
        }
        return $m;
    }

    private static function placeData(&$m, $size, $data)
    {
        $reserved = [];
        for ($r = 0; $r < $size; $r++) {
            $row = [];
            for ($c = 0; $c < $size; $c++) {
                $row[] = ($m[$r][$c] !== null);
            }
            $reserved[] = $row;
        }
        $bits = '';
        foreach ($data as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $len = strlen($bits);
        $idx = 0;
        $col = $size - 1;
        $up = true;
        while ($col > 0) {
            if ($col == 6) {
                $col--;                                                    // skip the timing column
            }
            for ($k = 0; $k < $size; $k++) {
                $r = $up ? ($size - 1 - $k) : $k;
                foreach ([$col, $col - 1] as $c) {
                    if (!$reserved[$r][$c]) {
                        $m[$r][$c] = ($idx < $len) ? (int)$bits[$idx] : 0;
                        $idx++;
                    }
                }
            }
            $up = !$up;
            $col -= 2;
        }
        return $reserved;
    }

    private static function maskCondition($mask, $r, $c)
    {
        switch ($mask) {
            case 0: return ($r + $c) % 2 == 0;
            case 1: return $r % 2 == 0;
            case 2: return $c % 3 == 0;
            case 3: return ($r + $c) % 3 == 0;
            case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 == 0;
            case 5: return ($r * $c) % 2 + ($r * $c) % 3 == 0;
            case 6: return (($r * $c) % 2 + ($r * $c) % 3) % 2 == 0;
            default: return (($r + $c) % 2 + ($r * $c) % 3) % 2 == 0;
        }
    }

    private static function applyMask($m, $size, $reserved, $mask)
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c] && self::maskCondition($mask, $r, $c)) {
                    $m[$r][$c] ^= 1;
                }
            }
        }
        return $m;
    }

    /** Both copies of the 15-bit format information, most-significant bit first. */
    private static function putFormat(&$m, $size, $mask)
    {
        $bits = str_pad(decbin(self::formatBits($mask)), 15, '0', STR_PAD_LEFT);
        for ($k = 0; $k < 15; $k++) {
            $bit = (int)$bits[$k];
            if ($k < 6) {                       // copy 1: along row 8, then up column 8
                $m[8][$k] = $bit;
            } elseif ($k == 6) {
                $m[8][7] = $bit;
            } elseif ($k == 7) {
                $m[8][8] = $bit;
            } elseif ($k == 8) {
                $m[7][8] = $bit;
            } else {
                $m[14 - $k][8] = $bit;
            }
            if ($k < 7) {                       // copy 2: up column 8, then along row 8
                $m[$size - 1 - $k][8] = $bit;
            } else {
                $m[8][$size - 15 + $k] = $bit;
            }
        }
    }

    private static function putVersion(&$m, $size, $version)
    {
        if ($version < 7) {
            return;
        }
        $bits = str_pad(decbin(self::versionBits($version)), 18, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 18; $i++) {
            $bit = (int)$bits[17 - $i];
            $r = intdiv($i, 3);
            $c = $size - 11 + $i % 3;
            $m[$r][$c] = $bit;
            $m[$c][$r] = $bit;
        }
    }

    /** Standard penalty score, used to choose the mask that scans most reliably. */
    private static function penalty($m, $size)
    {
        $lines = $m;
        for ($c = 0; $c < $size; $c++) {
            $col = [];
            for ($r = 0; $r < $size; $r++) {
                $col[] = $m[$r][$c];
            }
            $lines[] = $col;
        }
        $score = 0;
        $patterns = [
            [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0],
            [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1],
        ];
        foreach ($lines as $line) {
            $run = 1;
            $prev = $line[0];
            for ($i = 1; $i < $size; $i++) {
                if ($line[$i] == $prev) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                    $prev = $line[$i];
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
            for ($i = 0; $i + 11 <= $size; $i++) {
                $window = array_slice($line, $i, 11);
                if ($window === $patterns[0] || $window === $patterns[1]) {
                    $score += 40;
                }
            }
        }
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                if ($m[$r][$c] == $m[$r][$c + 1] && $m[$r][$c] == $m[$r + 1][$c]
                    && $m[$r][$c] == $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }
        $dark = 0;
        foreach ($m as $row) {
            $dark += array_sum($row);
        }
        $percent = $dark * 100 / ($size * $size);
        $score += 10 * (int)(abs($percent - 50) / 5);
        return $score;
    }
}

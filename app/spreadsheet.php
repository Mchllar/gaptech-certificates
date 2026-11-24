<?php
/**
 * Read an uploaded spreadsheet - CSV or Excel (.xlsx) - for the fleet uploads.
 *
 * spreadsheet_as_csv() hands back a path to CSV whatever came in, so the readers that
 * already understand CSV (batch_rows_from_csv, trk_batch_rows_from_csv) need no change.
 *
 * An .xlsx file is a zip of XML files. PHP's zip extension is not switched on in every
 * install (it is not in XAMPP by default), so when it is missing the file is unpacked
 * here with gzinflate, which is always available. Only the first worksheet is read.
 * Excel dates become YYYY-MM-DD. The old binary .xls format is not supported: Excel
 * saves it as .xlsx in one step (File, Save As).
 */
declare(strict_types=1);

/** CSV path for any accepted upload. $name is the file name the browser sent. */
function spreadsheet_as_csv(string $tmpPath, string $name): string
{
    $head = (string)@file_get_contents($tmpPath, false, null, 0, 8);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (strncmp($head, "PK\x03\x04", 4) === 0) {          // a zip: .xlsx (whatever it is called)
        $rows = xlsx_rows($tmpPath);
        $csv  = tempnam(sys_get_temp_dir(), 'xlsx');
        $out  = fopen($csv, 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        register_shutdown_function(static function () use ($csv) { @unlink($csv); });
        return $csv;
    }
    if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0 || $ext === 'xls') {
        throw new RuntimeException('That is an old-style Excel file (.xls). Open it in Excel, choose '
            . 'File, Save As, "Excel Workbook (.xlsx)", and upload that instead.');
    }
    return $tmpPath;                                        // CSV, as before
}

/** Every row of the first worksheet, as arrays of strings, gaps filled with ''. */
function xlsx_rows(string $path): array
{
    if (filesize($path) > 15 * 1024 * 1024) {
        throw new RuntimeException('That spreadsheet is over 15 MB. Split it into smaller files.');
    }
    $files = xlsx_unzip($path, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels',
                                'xl/sharedStrings.xml', 'xl/styles.xml'], '~^xl/worksheets/[^/]+\.xml$~');

    // Which file is the first sheet: workbook.xml names it, the rels file says where it is.
    $sheetFile = null;
    if (isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
        $wb   = xlsx_xml($files['xl/workbook.xml']);
        $rels = xlsx_xml($files['xl/_rels/workbook.xml.rels']);
        $first = $wb->sheets->sheet[0] ?? null;
        if ($first !== null) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetFile = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }
    if ($sheetFile === null || !isset($files[$sheetFile])) {
        $sheets = array_values(array_filter(array_keys($files), fn($f) => str_starts_with($f, 'xl/worksheets/')));
        sort($sheets, SORT_NATURAL);
        $sheetFile = $sheets[0] ?? null;
    }
    if ($sheetFile === null) {
        throw new RuntimeException('No worksheet was found in that Excel file.');
    }

    // Text cells usually point into one shared list of strings.
    $shared = [];
    if (isset($files['xl/sharedStrings.xml'])) {
        foreach (xlsx_xml($files['xl/sharedStrings.xml'])->si as $si) {
            $shared[] = xlsx_text($si);
        }
    }

    // Which cell styles are dates, so a fitting date comes out as 2026-11-24, not 46350.
    $dateStyle = [];
    if (isset($files['xl/styles.xml'])) {
        $st = xlsx_xml($files['xl/styles.xml']);
        $custom = [];
        foreach (($st->numFmts->numFmt ?? []) as $f) {
            $custom[(int)$f['numFmtId']] = (string)$f['formatCode'];
        }
        $i = 0;
        foreach (($st->cellXfs->xf ?? []) as $xf) {
            $id = (int)$xf['numFmtId'];
            $code = $custom[$id] ?? '';
            $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)
                || ($code !== '' && preg_match('/[dmy]/i', (string)preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code)));
            $dateStyle[$i++] = (bool)$isDate;
        }
    }

    $rows = [];
    foreach (xlsx_xml($files[$sheetFile])->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = xlsx_col((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = xlsx_text($c->is);
            } else {
                $val = (string)$c->v;
                if ($type === '' && $val !== '' && is_numeric($val)) {
                    if (!empty($dateStyle[(int)$c['s']]) && (float)$val > 0 && (float)$val < 2958466) {
                        // Excel counts days from 1899-12-30.
                        $val = gmdate('Y-m-d', (int)round(((float)$val - 25569) * 86400));
                    } elseif (preg_match('/^-?\d+\.0+$/', $val)) {
                        $val = (string)preg_replace('/\.0+$/', '', $val);    // 12.0 -> 12
                    }
                }
            }
            $cells[$col ?? count($cells)] = trim($val);
        }
        if (!$cells) {
            continue;
        }
        $line = [];
        for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }
    if (!$rows) {
        throw new RuntimeException('The first worksheet in that Excel file is empty.');
    }
    return $rows;
}

/** The named files (and any matching $pattern) from a zip, as name => contents. */
function xlsx_unzip(string $path, array $want, string $pattern): array
{
    $out = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (in_array($name, $want, true) || preg_match($pattern, $name)) {
                    $out[$name] = (string)$zip->getFromIndex($i);
                }
            }
            $zip->close();
            return $out;
        }
    }

    // No zip extension: read the archive's directory (at the end of the file) and inflate
    // each entry wanted. Spreadsheets are small, so it is read in one go.
    $bin = (string)file_get_contents($path);
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) {
        throw new RuntimeException('That file is not a readable Excel workbook.');
    }
    $count  = unpack('v', substr($bin, $eocd + 10, 2))[1];
    $offset = unpack('V', substr($bin, $eocd + 16, 4))[1];
    for ($n = 0; $n < $count; $n++) {
        if (substr($bin, $offset, 4) !== "PK\x01\x02") {
            break;
        }
        // method, mod time, mod date, crc, sizes, name/extra/comment lengths: 24 bytes from +10.
        $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($bin, $offset + 10, 24));
        $method = $h['method'];
        $local  = unpack('V', substr($bin, $offset + 42, 4))[1];
        $name   = substr($bin, $offset + 46, $h['nlen']);
        $offset += 46 + $h['nlen'] + $h['elen'] + $h['clen'];

        if (!in_array($name, $want, true) && !preg_match($pattern, $name)) {
            continue;
        }
        $l = unpack('vnlen/velen', substr($bin, $local + 26, 4));
        $data = substr($bin, $local + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        if ($method === 8) {
            $data = @gzinflate($data);
        } elseif ($method !== 0) {
            $data = false;
        }
        if ($data === false) {
            throw new RuntimeException('Part of that Excel file could not be read. Save it again in Excel and retry.');
        }
        $out[$name] = $data;
    }
    return $out;
}

function xlsx_xml(string $xml): SimpleXMLElement
{
    $doc = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    if ($doc === false) {
        throw new RuntimeException('That Excel file is damaged or not a real workbook.');
    }
    return $doc;
}

/** All the text in a cell or shared string, including formatted runs. */
function xlsx_text(?SimpleXMLElement $el): string
{
    if ($el === null) {
        return '';
    }
    if (isset($el->t)) {
        return (string)$el->t;
    }
    $s = '';
    foreach (($el->r ?? []) as $run) {
        $s .= (string)$run->t;
    }
    return $s;
}

/** "B7" -> 1 (zero-based column). */
function xlsx_col(string $ref): ?int
{
    if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
        return null;
    }
    $n = 0;
    foreach (str_split($m[1]) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

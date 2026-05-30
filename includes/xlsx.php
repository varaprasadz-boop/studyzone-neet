<?php
/* ============================================================
   Minimal spreadsheet reader for bulk uploads — handles .xlsx
   (native Excel, via ZipArchive + SimpleXML) and .csv. Returns an
   array of rows; each row is a 0-indexed array of cell strings.
   No external libraries (works on shared hosting).
   ============================================================ */

function spreadsheet_rows($path, $ext) {
    $ext = strtolower($ext);
    if ($ext === 'csv') return csv_rows($path);
    if ($ext === 'xlsx') return xlsx_rows($path);
    return null;
}

function csv_rows($path) {
    $rows = [];
    if (($h = fopen($path, 'r')) === false) return null;
    while (($r = fgetcsv($h, 0, ',')) !== false) {
        if ($r === [null] || $r === false) continue;          // blank line
        $rows[] = array_map(fn($c) => trim((string)$c), $r);
    }
    fclose($h);
    return $rows;
}

function xlsx_rows($path) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    // shared strings table
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && ($x = @simplexml_load_string($ss)) !== false) {
        foreach ($x->si as $si) $shared[] = xlsx_si_text($si);
    }

    // first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nm)) { $sheetXml = $zip->getFromName($nm); break; }
        }
    }
    $zip->close();
    if ($sheetXml === false) return null;
    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) return null;

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = []; $maxCol = -1;
        foreach ($row->c as $c) {
            $col  = xlsx_col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's')           { $val = $shared[(int)$c->v] ?? ''; }
            elseif ($type === 'inlineStr') { $val = xlsx_si_text($c->is); }
            else                          { $val = (string)$c->v; }
            $cells[$col] = trim($val);
            if ($col > $maxCol) $maxCol = $col;
        }
        $dense = [];
        for ($i = 0; $i <= $maxCol; $i++) $dense[$i] = $cells[$i] ?? '';
        $rows[] = $dense;
    }
    return $rows;
}

/* text of a shared-string / inline-string node (handles rich-text runs) */
function xlsx_si_text($si) {
    if ($si === null) return '';
    if (isset($si->t)) return (string)$si->t;          // simple string
    $txt = '';
    if (isset($si->r)) foreach ($si->r as $r) $txt .= (string)$r->t;   // rich-text runs
    return $txt;
}

/* "B3" → 1 (0-indexed column) */
function xlsx_col_index($ref) {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return 0;
    $letters = strtoupper($m[1]); $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

<?php
/* Анализ на реални BABH XLSX пакети с реалния reader и normalizer на плъгина, без WordPress (ARC-002 / QA-002 основа).
   Пускане: php -d memory_limit=-1 tests/php/corpus-analysis.php <out.json> <file1.xlsx> [<file2.xlsx> ...]
   Не променя файловете; не записва в база. Изходът е JSON отчет с row accounting и качество на полетата. */
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
if ($argc < 3) { fwrite(STDERR, "usage: corpus-analysis.php <out.json> <file.xlsx>...\n"); exit(2); }

require_once __DIR__ . '/wp-stubs.php';
require_once ABSPATH . 'includes/detect.php';
require_once ABSPATH . 'includes/xlsx-reader.php';
require_once ABSPATH . 'includes/etl.php';

$today = gmdate('Y-m-d');
$limits = array('purpose' => 2000, 'composition' => 5000, 'storage' => 2000, 'deletion' => 2000, 'producer' => 490, 'trader' => 490, 'notif_no' => 95);
$report = array('generated_at' => $today, 'files' => array(), 'combined' => array());
$all_regs = array(); $per_file_regs = array();

foreach (array_slice($argv, 2) as $path) {
    $t0 = microtime(true);
    $f = array('file' => basename($path), 'size' => filesize($path), 'sha256' => hash_file('sha256', $path));

    /* 1. Суров пас: всички физически редове, най-висок индекс на колона със стойност, редове със стойности отвъд колона N (индекс 13) */
    $zip = new ZipArchive(); $zip->open($path);
    $best = null; $best_size = 0; $sheets = array();
    for ($i = 0; $i < $zip->numFiles; $i++) { $st = $zip->statIndex($i); if (preg_match('#^xl/(worksheets|chartsheets)/sheet\d+\.xml$#', $st['name'])) { $sheets[] = $st['name'] . ' (' . $st['size'] . ' B)'; if (strpos($st['name'], 'worksheets/') !== false && $st['size'] > $best_size) { $best = $st['name']; $best_size = $st['size']; } } }
    $zip->close();
    $f['sheets'] = $sheets; $f['sheet_used_by_reader'] = $best;
    $r = new XMLReader(); $r->open('zip://' . $path . '#' . $best);
    $phys = 0; $maxcol = -1; $beyond_n_rows = 0; $beyond_n_cells = 0; $beyond_cols = array(); $dimension = '';
    while (@$r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT) continue;
        if ($r->localName === 'dimension') { $dimension = (string)$r->getAttribute('ref'); continue; }
        if ($r->localName !== 'row') continue;
        $phys++;
        $rx = simplexml_load_string($r->readOuterXML());
        if (!$rx) continue;
        $has_beyond = false;
        foreach ($rx->c as $c) {
            $ref = (string)$c['r']; $letters = preg_replace('/\d+/', '', $ref);
            $n = 0; for ($k = 0; $k < strlen($letters); $k++) $n = $n * 26 + (ord($letters[$k]) - 64); $col = $n - 1;
            $has_val = isset($c->v) || isset($c->is);
            if (!$has_val) continue;
            if ($col > $maxcol) $maxcol = $col;
            if ($col > 13) { $beyond_n_cells++; $has_beyond = true; $beyond_cols[$letters] = isset($beyond_cols[$letters]) ? $beyond_cols[$letters] + 1 : 1; }
        }
        if ($has_beyond) $beyond_n_rows++;
    }
    $r->close();
    $f['raw'] = array('dimension' => $dimension, 'physical_rows' => $phys, 'max_col_index_with_value' => $maxcol, 'rows_with_values_beyond_col_N' => $beyond_n_rows, 'cells_beyond_col_N' => $beyond_n_cells, 'cols_beyond_N' => $beyond_cols);

    /* 2. Reader-ът на плъгина */
    $cnt = BABH6_XLSX_Reader::count_data_rows($path);
    $rows = BABH6_XLSX_Reader::read_register_rows($path);
    if (is_wp_error($rows)) { $f['reader_error'] = $rows->get_error_message(); $report['files'][] = $f; continue; }
    $f['reader'] = array('count_data_rows' => $cnt, 'rows_returned' => count($rows), 'rows_with_cells_not_returned' => $phys - count($rows));
    $f['first_rows_raw'] = array_map(function ($r) { return array_map(function ($v) { return mb_substr($v, 0, 40, 'UTF-8'); }, $r); }, array_slice($rows, 0, 4));

    /* 3. Normalizer-ът на плъгина: row accounting (IM-07/IM-09 логика) */
    $acc = array('data_rows' => 0, 'skipped_with_number_like_text' => 0, 'skipped_breakdown' => array('no_valid_reg' => 0, 'valid_reg_but_empty_name' => 0, 'reg_over_20' => 0), 'skipped_examples' => array(), 'header_or_empty' => 0, 'duplicate_reg_within_file' => 0, 'duplicate_identical_src' => 0, 'duplicate_conflicting_src' => 0, 'duplicate_conflicting_examples' => array(), 'long_reg_over_20' => 0);
    $q_extra = array('reg_digit_lengths' => array(), 'notif_date_invalid_examples' => array(), 'producer_empty_breakdown' => array('E_F_G_all_empty' => 0, 'G_or_F_has_text_not_firm' => 0, 'G_or_F_text_examples' => array()), 'producer_and_trader_both_empty' => 0);
    $hash_by_reg = array();
    $q = array('type' => array('П' => 0, 'Т' => 0), 'reg_with_suffix' => 0, 'ryear_null' => 0, 'ryear_min' => null, 'ryear_max' => null, 'oblast_unknown' => 0,
        'notif_date_null' => 0, 'notif_date_present_but_invalid' => 0, 'notif_date_future' => 0, 'notif_date_future_examples' => array(), 'notif_date_min' => null, 'notif_date_max' => null, 'notif_date_before_2000' => 0,
        'entry_date_null' => 0, 'launch_date_null' => 0,
        'producer_empty' => 0, 'producer_country' => 0, 'producer_country_examples' => array(), 'producer_from_contract_column' => 0,
        'trader_empty' => 0, 'trader_country' => 0, 'trader_equals_producer_norm' => 0,
        'deletion_note' => 0, 'truncated' => array_fill_keys(array_keys($limits), 0), 'truncated_rows' => 0, 'truncated_examples' => array(),
        'composition_empty' => 0, 'composition_has_semicolon' => 0, 'composition_comma_no_semicolon' => 0, 'composition_multi_qty_no_semicolon' => 0, 'composition_multi_qty_no_semicolon_examples' => array(),
        'composition_over_400_no_separator' => 0, 'name_empty' => 0, 'flags_rows' => 0, 'categories' => array());
    $seen = array();
    foreach ($rows as $i => $row) {
        $p = babh6_normalize_row($row);
        if ($p === null) {
            $a = trim((string)$row[0]);
            if ($a !== '' && preg_match('/\d{5,}/u', $a)) {
                $acc['skipped_with_number_like_text']++;
                $pr = babh6_parse_reg($a); $nm = trim((string)$row[9]);
                if ($pr === null) $acc['skipped_breakdown']['no_valid_reg']++;
                elseif (strlen($pr['reg']) > 20) { $acc['skipped_breakdown']['reg_over_20']++; $acc['long_reg_over_20']++; }
                elseif ($nm === '') $acc['skipped_breakdown']['valid_reg_but_empty_name']++;
                if (count($acc['skipped_examples']) < 10) $acc['skipped_examples'][] = 'A=„' . mb_substr($a, 0, 30, 'UTF-8') . '“ J=„' . mb_substr($nm, 0, 30, 'UTF-8') . '“ E=„' . mb_substr(trim((string)$row[4]), 0, 20, 'UTF-8') . '“';
            }
            else $acc['header_or_empty']++;
            continue;
        }
        $acc['data_rows']++;
        if (isset($seen[$p['reg']])) { $acc['duplicate_reg_within_file']++; if ($hash_by_reg[$p['reg']] === $p['src_hash']) $acc['duplicate_identical_src']++; else { $acc['duplicate_conflicting_src']++; if (count($acc['duplicate_conflicting_examples']) < 5) $acc['duplicate_conflicting_examples'][] = $p['reg'] . ' (ред ' . ($i + 1) . '): „' . mb_substr($p['name'], 0, 40, 'UTF-8') . '“'; } }
        else { $seen[$p['reg']] = 1; $hash_by_reg[$p['reg']] = $p['src_hash']; }
        $dl = strlen(preg_replace('/\D/', '', preg_replace('/[^0-9ПТ]+.*$/u', '', $p['reg']))); $q_extra['reg_digit_lengths'][$dl] = isset($q_extra['reg_digit_lengths'][$dl]) ? $q_extra['reg_digit_lengths'][$dl] + 1 : 1;
        if ($p['notif_date'] === null && trim((string)$row[3]) !== '' && count($q_extra['notif_date_invalid_examples']) < 8) $q_extra['notif_date_invalid_examples'][] = $p['reg'] . ': „' . mb_substr(trim((string)$row[3]), 0, 24, 'UTF-8') . '“';
        if ($p['producer_name'] === '') { $g = trim((string)$row[6]); $fcol = trim((string)$row[5]); if ($g === '' && $fcol === '') $q_extra['producer_empty_breakdown']['E_F_G_all_empty']++; else { $q_extra['producer_empty_breakdown']['G_or_F_has_text_not_firm']++; if (count($q_extra['producer_empty_breakdown']['G_or_F_text_examples']) < 6) $q_extra['producer_empty_breakdown']['G_or_F_text_examples'][] = mb_substr($g !== '' ? $g : $fcol, 0, 50, 'UTF-8'); } if ($p['trader_name'] === '') $q_extra['producer_and_trader_both_empty']++; }
        $q['type'][$p['rtype']]++;
        if (!preg_match('/^[ПТ]\d+$/u', $p['reg'])) $q['reg_with_suffix']++;
        if ($p['ryear'] === null) $q['ryear_null']++; else { if ($q['ryear_min'] === null || $p['ryear'] < $q['ryear_min']) $q['ryear_min'] = $p['ryear']; if ($q['ryear_max'] === null || $p['ryear'] > $q['ryear_max']) $q['ryear_max'] = $p['ryear']; }
        if ($p['oblast'] === '') $q['oblast_unknown']++;
        $nd = $p['notif_date'];
        if ($nd === null) { $q['notif_date_null']++; if (trim((string)$row[3]) !== '') $q['notif_date_present_but_invalid']++; }
        else {
            if ($nd > $today) { $q['notif_date_future']++; if (count($q['notif_date_future_examples']) < 8) $q['notif_date_future_examples'][] = $p['reg'] . ' → ' . $nd . ' (клетка: ' . mb_substr((string)$row[3], 0, 20, 'UTF-8') . ')'; }
            if ($nd < '2000-01-01') $q['notif_date_before_2000']++;
            if ($q['notif_date_min'] === null || $nd < $q['notif_date_min']) $q['notif_date_min'] = $nd;
            if ($q['notif_date_max'] === null || $nd > $q['notif_date_max']) $q['notif_date_max'] = $nd;
        }
        if ($p['entry_date'] === null) $q['entry_date_null']++;
        if ($p['launch_date'] === null) $q['launch_date_null']++;
        if ($p['producer_name'] === '') $q['producer_empty']++;
        elseif ($p['producer_kind'] === 'country') { $q['producer_country']++; if (count($q['producer_country_examples']) < 6 && !in_array($p['producer_name'], $q['producer_country_examples'], true)) $q['producer_country_examples'][] = $p['producer_name']; }
        $praw = trim((string)$row[4]); if (($praw === '' || $praw === '-') && $p['producer_name'] !== '') $q['producer_from_contract_column']++;
        if ($p['trader_name'] === '') $q['trader_empty']++; elseif ($p['trader_kind'] === 'country') $q['trader_country']++;
        if ($p['trader_norm'] !== '' && $p['trader_norm'] === $p['producer_norm']) $q['trader_equals_producer_norm']++;
        if ($p['deletion'] !== '') $q['deletion_note']++;
        $raw = array('purpose' => preg_replace('/\s+/u', ' ', trim((string)$row[10])), 'composition' => preg_replace('/\s+/u', ' ', trim((string)$row[11])), 'storage' => trim((string)$row[8]), 'deletion' => trim((string)$row[13]), 'producer' => $praw !== '' && $praw !== '-' ? $praw : $p['producer_name'], 'trader' => trim((string)$row[7]), 'notif_no' => trim((string)$row[2]));
        $tr = false;
        foreach ($limits as $k => $lim) { if (mb_strlen($raw[$k], 'UTF-8') > $lim) { $q['truncated'][$k]++; $tr = true; if (count($q['truncated_examples']) < 6) $q['truncated_examples'][] = $p['reg'] . ' ' . $k . ' ' . mb_strlen($raw[$k], 'UTF-8') . ' зн.'; } }
        if ($tr) $q['truncated_rows']++;
        $c = $raw['composition'];
        if ($c === '') $q['composition_empty']++;
        else {
            if (strpos($c, ';') !== false) $q['composition_has_semicolon']++;
            elseif (strpos($c, ',') !== false) $q['composition_comma_no_semicolon']++;
            $nq = preg_match_all('/\d+(?:[.,]\d+)?\s*(?:мг|мкг|г|mg|mcg|µg|μg|g|iu|ме|ml|мл)\b/iu', $c);
            if ($nq >= 2 && strpos($c, ';') === false) { $q['composition_multi_qty_no_semicolon']++; if (count($q['composition_multi_qty_no_semicolon_examples']) < 5) $q['composition_multi_qty_no_semicolon_examples'][] = $p['reg'] . ': ' . mb_substr($c, 0, 90, 'UTF-8'); }
            if (mb_strlen($c, 'UTF-8') > 400 && strpos($c, ';') === false) $q['composition_over_400_no_separator']++;
        }
        if ($p['flag_count'] > 0) $q['flags_rows']++;
        $q['categories'][$p['category']] = isset($q['categories'][$p['category']]) ? $q['categories'][$p['category']] + 1 : 1;
        $all_regs[$p['reg']] = isset($all_regs[$p['reg']]) ? $all_regs[$p['reg']] + 1 : 1;
        $per_file_regs[basename($path)][$p['reg']] = 1;
    }
    arsort($q['categories']); ksort($q_extra['reg_digit_lengths']); $q['extra'] = $q_extra;
    $acc['check'] = 'physical_rows = data_rows + skipped_with_number_like_text + header_or_empty + rows_with_cells_not_returned → ' . ($phys === $acc['data_rows'] + $acc['skipped_with_number_like_text'] + $acc['header_or_empty'] + ($phys - count($rows)) ? 'OK' : 'MISMATCH');
    $f['accounting'] = $acc; $f['quality'] = $q; $f['seconds'] = round(microtime(true) - $t0, 1);
    $report['files'][] = $f;
    unset($rows);
}
$names = array_keys($per_file_regs);
$dups = 0; foreach ($all_regs as $n) if ($n > 1) $dups++;
$inter = count($names) === 2 ? count(array_intersect_key($per_file_regs[$names[0]], $per_file_regs[$names[1]])) : null;
$report['combined'] = array('unique_regs' => count($all_regs), 'regs_seen_more_than_once' => $dups, 'regs_in_both_files' => $inter);
file_put_contents($argv[1], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "OK → ", $argv[1], "\n";

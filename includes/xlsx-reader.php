<?php
if (!defined('ABSPATH')) exit;

/**
 * Минимален XLSX reader без външни зависимости.
 * ZipArchive за архива, XMLReader (streaming) за листа, SimpleXML за sharedStrings.
 * Избира автоматично най-големия работен лист (регистърът е винаги той).
 */
class BABH6_XLSX_Reader {

    /**
     * @return array|WP_Error Масив от редове; всеки ред е плътен масив с 14 колони (индекс 0-13).
     */
    public static function read_register_rows($path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('babh6_nozip', 'Липсва PHP поддръжка за ZIP архиви (ZipArchive). Помоли хостинг доставчика да я активира.');
        }
        if (!class_exists('XMLReader')) {
            return new WP_Error('babh6_noxml', 'Липсва PHP поддръжка за XMLReader. Помоли хостинг доставчика да я активира.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return new WP_Error('babh6_zip', 'Файлът не може да бъде прочетен като .xlsx. Провери формата и качи файла отново.');
        }

        /* Най-големият worksheet = регистърът (chart sheets са в друга папка) */
        $best = null;
        $best_size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $st['name']) && $st['size'] > $best_size) {
                $best = $st['name'];
                $best_size = $st['size'];
            }
        }
        if (!$best) {
            $zip->close();
            return new WP_Error('babh6_nosheet', 'Във файла не е намерен работен лист за обработка. Провери дали качваш правилния .xlsx файл.');
        }

        /* Shared strings */
        $shared = array();
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && $ss !== '') {
            $prev = libxml_use_internal_errors(true);
            $sx = simplexml_load_string($ss);
            libxml_use_internal_errors($prev);
            if ($sx) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string)$si->t;
                        continue;
                    }
                    $txt = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $txt .= (string)$run->t;
                        }
                    }
                    $shared[] = $txt;
                }
            }
        }
        $zip->close();

        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $best)) {
            return new WP_Error('babh6_open', 'Работният лист не може да бъде прочетен. Опитай да качиш файла отново.');
        }

        $rows = array();
        $prev = libxml_use_internal_errors(true);
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $row_xml = $reader->readOuterXML();
                $rx = simplexml_load_string($row_xml);
                if (!$rx) continue;
                $cells = array();
                foreach ($rx->c as $c) {
                    $ref = (string)$c['r'];
                    $col = self::col_index(preg_replace('/\d+/', '', $ref));
                    if ($col === null || $col > 30) continue;
                    $t = (string)$c['t'];
                    $val = '';
                    if ($t === 's') {
                        $idx = intval((string)$c->v);
                        $val = isset($shared[$idx]) ? $shared[$idx] : '';
                    } elseif ($t === 'inlineStr') {
                        $val = isset($c->is->t) ? (string)$c->is->t : '';
                    } elseif (isset($c->v)) {
                        $val = (string)$c->v;
                    }
                    $cells[$col] = $val;
                }
                if (!$cells) continue;
                $dense = array_fill(0, 14, '');
                foreach ($cells as $ci => $v) {
                    if ($ci < 14) $dense[$ci] = $v;
                }
                $rows[] = $dense;
            }
        }
        libxml_use_internal_errors($prev);
        $reader->close();

        if (count($rows) < 2) {
            return new WP_Error('babh6_empty', 'Работният лист е празен или не съдържа достатъчно редове за обработка. Провери дали това е файлът с регистъра.');
        }
        return $rows;
    }

    private static function col_index($letters) {
        $letters = strtoupper(trim($letters));
        if ($letters === '') return null;
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($letters[$i]);
            if ($o < 65 || $o > 90) return null;
            $n = $n * 26 + ($o - 64);
        }
        return $n - 1;
    }

    /** Намира най-големия worksheet и връща (път в архива, shared strings) или WP_Error */
    private static function open_context($path) {
        if (!class_exists('ZipArchive')) return new WP_Error('babh6_nozip', 'Липсва PHP поддръжка за ZIP архиви (ZipArchive). Помоли хостинг доставчика да я активира.');
        if (!class_exists('XMLReader')) return new WP_Error('babh6_noxml', 'Липсва PHP поддръжка за XMLReader. Помоли хостинг доставчика да я активира.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return new WP_Error('babh6_zip', 'Файлът не може да бъде прочетен като .xlsx. Провери формата и качи файла отново.');
        $best = null; $best_size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $st['name']) && $st['size'] > $best_size) {
                $best = $st['name']; $best_size = $st['size'];
            }
        }
        if (!$best) { $zip->close(); return new WP_Error('babh6_nosheet', 'Във файла не е намерен работен лист за обработка. Провери дали качваш правилния .xlsx файл.'); }
        $shared = array();
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && $ss !== '') {
            $prev = libxml_use_internal_errors(true);
            $sx = simplexml_load_string($ss);
            libxml_use_internal_errors($prev);
            if ($sx) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) { $shared[] = (string)$si->t; continue; }
                    $txt = '';
                    if (isset($si->r)) foreach ($si->r as $run) $txt .= (string)$run->t;
                    $shared[] = $txt;
                }
            }
        }
        $zip->close();
        return array('sheet' => $best, 'shared' => $shared);
    }

    /** Брои редовете в листа (бързо, без парсване на клетки). */
    public static function count_data_rows($path) {
        $ctx = self::open_context($path);
        if (is_wp_error($ctx)) return $ctx;
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $ctx['sheet'])) return new WP_Error('babh6_open', 'Работният лист не може да бъде прочетен. Опитай да качиш файла отново.');
        $count = 0;
        $found = false;
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') { $found = true; break; }
        }
        if ($found) {
            do { $count++; } while (@$reader->next('row'));
        }
        $reader->close();
        return $count;
    }

    /** Чете порция редове [skip, skip+limit) — за chunked обработка без timeout. */
    public static function read_rows_chunk($path, $skip, $limit) {
        $ctx = self::open_context($path);
        if (is_wp_error($ctx)) return $ctx;
        $shared = $ctx['shared'];
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $ctx['sheet'])) return new WP_Error('babh6_open', 'Работният лист не може да бъде прочетен. Опитай да качиш файла отново.');
        $out = array();
        $prev = libxml_use_internal_errors(true);
        $found = false;
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') { $found = true; break; }
        }
        if ($found) {
            $i = 0;
            do {
                if ($i >= $skip) {
                    $rx = simplexml_load_string($reader->readOuterXML());
                    if ($rx) {
                        $cells = array();
                        foreach ($rx->c as $c) {
                            $ref = (string)$c['r'];
                            $col = self::col_index(preg_replace('/\d+/', '', $ref));
                            if ($col === null || $col > 30) continue;
                            $t = (string)$c['t'];
                            $val = '';
                            if ($t === 's') { $idx = intval((string)$c->v); $val = isset($shared[$idx]) ? $shared[$idx] : ''; }
                            elseif ($t === 'inlineStr') { $val = isset($c->is->t) ? (string)$c->is->t : ''; }
                            elseif (isset($c->v)) { $val = (string)$c->v; }
                            $cells[$col] = $val;
                        }
                        $dense = array_fill(0, 14, '');
                        foreach ($cells as $ci => $v) { if ($ci < 14) $dense[$ci] = $v; }
                        $out[] = $dense;
                    }
                    if (count($out) >= $limit) break;
                }
                $i++;
            } while (@$reader->next('row'));
        }
        libxml_use_internal_errors($prev);
        $reader->close();
        return $out;
    }
}

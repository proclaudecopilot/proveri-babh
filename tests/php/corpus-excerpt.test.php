<?php
/* Реалният reader + normalizer на плъгина върху извлеци от реалния пакет на БАБХ от 08.09.2026 (tests/fixtures/source/).
   Очакванията са в expected.json и са генерирани НЕЗАВИСИМО с openpyxl (make-expected.py), не от тествания parser (QA-001).
   Пускане: php tests/php/corpus-excerpt.test.php */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp-stubs.php';
require_once ABSPATH . 'includes/xlsx-reader.php';
require_once ABSPATH . 'includes/etl.php';

$dir = ABSPATH . 'tests/fixtures/source/';
$exp = json_decode(file_get_contents($dir . 'expected.json'), true);
t_eq('expected.json се чете', true, is_array($exp) && count($exp) === 2);

foreach ($exp as $name => $e) {
    $path = $dir . $name;
    t_eq("$name: извлекът е непроменен (sha256)", $e['sha256'], hash_file('sha256', $path));
    $rows = BABH6_XLSX_Reader::read_register_rows($path);
    t_eq("$name: reader чете без грешка", false, is_wp_error($rows));
    if (is_wp_error($rows)) continue;
    t_eq("$name: всички физически редове са върнати", $e['physical_rows'], count($rows));
    t_eq("$name: count_data_rows = физически редове", $e['physical_rows'], BABH6_XLSX_Reader::count_data_rows($path));

    $parsed = array(); $skipped_prefixed = 0;
    foreach ($rows as $row) {
        $p = babh6_normalize_row($row);
        $a = preg_replace('/\s+/u', '', (string)$row[0]);
        if ($p === null) { if (preg_match('/^[ПТPT]\d{7,}/u', $a)) $skipped_prefixed++; continue; }
        if (!isset($parsed[$p['reg']])) $parsed[$p['reg']] = $p;
    }
    /* Всеки ред с рег. № с префикс и име трябва да стане регистрация; извлеците нямат редове без име */
    t_eq("$name: редовете с рег. № П/Т стават записи (без тихо изпускане)", $e['rows_with_prefixed_reg'], count($parsed) + $skipped_prefixed);
    t_eq("$name: няма изпуснати редове с валиден префикс", 0, $skipped_prefixed);
    t_eq("$name: различните рег. номера съвпадат с независимото броене", $e['distinct_prefixed_reg'], count($parsed));

    foreach ($e['anomalies'] as $reg => $a) {
        if (!isset($parsed[$reg])) { t_eq("$name: $reg е прочетен", true, false); continue; }
        $p = $parsed[$reg];
        t_eq("$reg: наименованието е същото като в клетката (с нормализирани интервали)", $a['name'], $p['name']);
        /* Дата на уведомление: първата дата в клетката; Excel сериен номер → ISO (46357 = 2026-12-01 по независимото изчисление) */
        if (!empty($a['notif_first_iso'])) t_eq("$reg: дата „{$a['notif_raw']}“ → " . $a['notif_first_iso'] . (strpos($a['notif_raw'], ';') !== false ? ' (клетка с две дати → първата; характеризация, NRM-003 решава семантиката)' : ''), $a['notif_first_iso'], $p['notif_date']);
        if ($a['notif_raw'] === '30.10.2026') t_eq("$reg: бъдеща дата от източника се ПАЗИ (не се „поправя“)", '2026-10-30', $p['notif_date']);
        if ($a['composition_len'] > 5000) t_xfail('DAT-001', "$reg: съставът не се реже тихо (суровата дължина {$a['composition_len']} се пази)", $a['composition_len'], mb_strlen($p['composition'], 'UTF-8'));
        if ($a['storage_len'] > 2000) t_xfail('DAT-001', "$reg: „съхранение“ не се реже тихо ({$a['storage_len']} знака)", $a['storage_len'], mb_strlen($p['storage'], 'UTF-8'));
        if (substr($reg, -3) === '***') t_eq("$reg: суфиксът *** е част от стабилния ID (характеризация; DAT-002 решава)", $reg, $p['reg']);
    }
}
/* Бъдещата дата е В ИЗТОЧНИКА (LIVE-08): parser-ът трябва да я пази И да я маркира като аномалия (NRM-002). Днес няма флаг. */
t_xfail('NRM-002', 'бъдеща дата на уведомление носи anomaly flag', true, isset($parsed['Т2225069041']['anomalies']));

exit(t_report(__FILE__) ? 0 : 1);

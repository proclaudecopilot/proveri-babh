<?php
/**
 * Зарежда чистите domain функции на плъгина БЕЗ WordPress (ARC-003: бизнес правилата работят без HTTP и UI).
 * Файловете в includes/ започват с `if (!defined('ABSPATH')) exit;` — тук ABSPATH е дефинирана, за да минат.
 * Само файлове без WordPress зависимости се зареждат; при нужда от WP функция се добавя stub тук с коментар
 * защо. Ако stub-ът трябва да „имитира“ поведение, тестът не е чист и се описва като такъв.
 */
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
define('BABH6_TESTS', true);

/* Stub-ове за WP функции, които чистите файлове викат само условно */
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }

require_once ABSPATH . 'includes/detect.php';

/* ---- минимален runner ---- */
$GLOBALS['t'] = array('pass' => 0, 'fail' => 0, 'xfail' => 0, 'xpass' => 0, 'msgs' => array());
function t_eq($name, $expected, $actual) {
    if ($expected === $actual) { $GLOBALS['t']['pass']++; return; }
    $GLOBALS['t']['fail']++;
    $GLOBALS['t']['msgs'][] = "FAIL  $name\n      очаквано: " . var_export($expected, true) . "\n      получено: " . var_export($actual, true);
}
/** Известен дефект (task ID): тестът описва ЖЕЛАНОТО поведение; „xfail“ = все още не е поправено. Когато мине — xpass → смени на t_eq. */
function t_xfail($task, $name, $expected, $actual) {
    if ($expected === $actual) { $GLOBALS['t']['xpass']++; $GLOBALS['t']['msgs'][] = "XPASS $name — $task изглежда поправен: смени t_xfail на t_eq"; return; }
    $GLOBALS['t']['xfail']++;
    $GLOBALS['t']['msgs'][] = "xfail $name ($task) — очаквано " . var_export($expected, true) . ", текущо " . var_export($actual, true);
}
function t_report($file) {
    $t = $GLOBALS['t'];
    foreach ($t['msgs'] as $m) echo $m, "\n";
    printf("%s: pass=%d fail=%d xfail=%d xpass=%d\n", basename($file), $t['pass'], $t['fail'], $t['xfail'], $t['xpass']);
    return $t['fail'] === 0 && $t['xpass'] === 0;
}

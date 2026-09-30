<?php
if (!defined('ABSPATH')) exit;

/**
 * Автоматично обновяване от портала на БАБХ.
 *
 * По график (по подразбиране понеделник 06:00 и петък 18:00, локално време на
 * сайта) плъгинът отваря страницата на регистъра, намира актуалните .xlsx
 * линкове (част 1, част 2, ...), сваля ги и ги обработва като ЕДНО качване
 * през същия chunked ETL, който ползва и ръчното качване. Ако линковете не са
 * променени от последния успешен импорт — не прави нищо.
 *
 * Cron hooks:
 *   babh6_sync_check    — планираната проверка (single event, пренасрочва се сама)
 *   babh6_sync_process  — фонова обработка на порции, докато job-ът приключи
 */

define('BABH6_SYNC_DEFAULT_URL', 'https://bfsa.egov.bg/wps/portal/bfsa-web/registers/Registyr_na_hranitelnite_dobavki');
define('BABH6_SYNC_DEFAULT_SLOTS', 'mon 06:00, fri 18:00');

/* ============ Настройки / състояние ============ */

function babh6_sync_enabled() {
    return (int)get_option('babh6_sync_enabled', 1) === 1;
}

function babh6_sync_url() {
    $u = trim((string)get_option('babh6_sync_url', ''));
    return $u !== '' ? $u : BABH6_SYNC_DEFAULT_URL;
}

function babh6_sync_state() {
    $s = get_option('babh6_sync_state');
    return is_array($s) ? $s : array();
}

function babh6_sync_state_set($patch) {
    $s = array_merge(babh6_sync_state(), $patch);
    update_option('babh6_sync_state', $s, false);
    return $s;
}

/**
 * Парсва графика "mon 06:00, fri 18:00" → [[iso_dow(1-7), h, m], ...].
 * Приема и български съкращения: пон, вт, ср, чет, пет, съб, нед.
 */
function babh6_sync_slots($raw = null) {
    if ($raw === null) $raw = (string)get_option('babh6_sync_slots', BABH6_SYNC_DEFAULT_SLOTS);
    $days = array(
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        'пон' => 1, 'вт' => 2, 'вто' => 2, 'ср' => 3, 'сря' => 3, 'чет' => 4, 'пет' => 5, 'съб' => 6, 'нед' => 7,
    );
    $out = array();
    foreach (preg_split('/[,;\n]+/u', $raw) as $part) {
        $part = trim(mb_strtolower($part, 'UTF-8'));
        if ($part === '') continue;
        if (!preg_match('/^([a-zа-я]+)\.?\s+(\d{1,2})[:.](\d{2})$/u', $part, $m)) continue;
        $key = mb_substr($m[1], 0, 3, 'UTF-8');
        if (!isset($days[$key])) $key = mb_substr($m[1], 0, 2, 'UTF-8');
        if (!isset($days[$key])) continue;
        $h = (int)$m[2]; $mi = (int)$m[3];
        if ($h > 23 || $mi > 59) continue;
        $out[] = array($days[$key], $h, $mi);
    }
    if (!$out && $raw !== BABH6_SYNC_DEFAULT_SLOTS) return babh6_sync_slots(BABH6_SYNC_DEFAULT_SLOTS);
    return $out;
}

/** Валидатор на графика (SY-06): връща списък с неразпознатите части; празен = валиден. */
function babh6_sync_slots_invalid($raw) {
    $days = array('mon','tue','wed','thu','fri','sat','sun','пон','вт','вто','ср','сря','чет','пет','съб','нед');
    $bad = array();
    foreach (preg_split('/[,;\n]+/u', (string)$raw) as $part) {
        $part = trim(mb_strtolower($part, 'UTF-8'));
        if ($part === '') continue;
        if (!preg_match('/^([a-zа-я]+)\.?\s+(\d{1,2})[:.](\d{2})$/u', $part, $m)) { $bad[] = $part; continue; }
        $key = mb_substr($m[1], 0, 3, 'UTF-8');
        if (!in_array($key, $days, true)) $key = mb_substr($m[1], 0, 2, 'UTF-8');
        if (!in_array($key, $days, true) || (int)$m[2] > 23 || (int)$m[3] > 59) $bad[] = $part;
    }
    return $bad;
}

function babh6_sync_timezone() {
    if (function_exists('wp_timezone')) return wp_timezone();
    $tz = get_option('timezone_string');
    try { return new DateTimeZone($tz ? $tz : 'UTC'); } catch (Exception $e) { return new DateTimeZone('UTC'); }
}

/** Следващият момент (UTC timestamp) от графика след $from (UTC timestamp, default сега). */
function babh6_sync_next_ts($from = null, $slots = null) {
    if ($from === null) $from = time();
    if ($slots === null) $slots = babh6_sync_slots();
    if (!$slots) return false;
    $tz = babh6_sync_timezone();
    $now = new DateTime('@' . $from);
    $now->setTimezone($tz);
    $best = null;
    foreach ($slots as $s) {
        list($dow, $h, $mi) = $s;
        $cand = clone $now;
        $cand->setTime($h, $mi, 0);
        $diff = ($dow - (int)$cand->format('N') + 7) % 7;
        if ($diff > 0) $cand->modify('+' . $diff . ' days');
        if ($cand->getTimestamp() <= $from) $cand->modify('+7 days');
        $ts = $cand->getTimestamp();
        if ($best === null || $ts < $best) $best = $ts;
    }
    return $best;
}

/* ============ Планиране ============ */

function babh6_sync_schedule_next() {
    $ts = wp_next_scheduled('babh6_sync_check');
    while ($ts) { wp_unschedule_event($ts, 'babh6_sync_check'); $ts = wp_next_scheduled('babh6_sync_check'); }
    if (!babh6_sync_enabled()) return false;
    $next = babh6_sync_next_ts();
    if ($next) wp_schedule_single_event($next, 'babh6_sync_check');
    return $next;
}

/* На всяко зареждане: гарантирай, че има насрочена проверка (евтино — cron масивът е autoload). */
add_action('init', function () {
    $ts = wp_next_scheduled('babh6_sync_check');
    if (babh6_sync_enabled()) {
        if (!$ts) babh6_sync_schedule_next();
    } elseif ($ts) {
        babh6_sync_schedule_next();
    }
});

add_action('babh6_sync_check', function () {
    babh6_sync_schedule_next();
    babh6_sync_run(false, 'cron');
});

add_action('babh6_sync_process', 'babh6_sync_process_cron');

/* ============ HTTP ============ */

/**
 * HTTP стратегии — порталът на БАБХ (IBM WebSphere) понякога затваря връзката
 * без отговор (cURL 52) или праща бавно. Пробваме ги подред; работещата се
 * запомня и се ползва първа следващия път.
 */
function babh6_sync_strategies() {
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $list = array(
        'default' => array('ua' => $chrome . ' proveri-babh/' . BABH6_VERSION),
        'http11'  => array('ua' => $chrome, 'http11' => true, 'nogzip' => true, 'close' => true),
        'plain'   => array('ua' => 'Mozilla/5.0', 'http11' => true, 'nogzip' => true, 'close' => true, 'minimal' => true),
    );
    $list = apply_filters('babh6_sync_strategies', $list);
    $pref = (string)get_option('babh6_sync_strategy', '');
    if ($pref !== '' && isset($list[$pref])) $list = array($pref => $list[$pref]) + $list;
    return $list;
}

function babh6_sync_http_args($extra = array(), $strategy = array()) {
    $headers = array();
    if (empty($strategy['minimal'])) {
        $headers['Accept-Language'] = 'bg,en;q=0.8';
        $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    }
    if (!empty($strategy['nogzip'])) $headers['Accept-Encoding'] = 'identity';
    if (!empty($strategy['close']))  $headers['Connection'] = 'close';
    $args = array(
        'timeout'     => 60,
        'redirection' => 5,
        'user-agent'  => isset($strategy['ua']) ? $strategy['ua'] : 'Mozilla/5.0',
        'headers'     => $headers,
        'decompress'  => empty($strategy['nogzip']),
    );
    if (isset($extra['headers'])) { $args['headers'] = array_merge($headers, $extra['headers']); unset($extra['headers']); }
    $GLOBALS['babh6_sync_curl'] = $strategy;
    return apply_filters('babh6_sync_http_args', array_merge($args, $extra));
}

/* cURL опции по стратегия (WP пуска http_api_curl преди всяка заявка) */
add_action('http_api_curl', function ($handle) {
    if (empty($GLOBALS['babh6_sync_curl']) || !is_array($GLOBALS['babh6_sync_curl'])) return;
    $st = $GLOBALS['babh6_sync_curl'];
    if (!empty($st['http11']) && defined('CURL_HTTP_VERSION_1_1')) curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    if (!empty($st['close'])) { curl_setopt($handle, CURLOPT_FORBID_REUSE, true); curl_setopt($handle, CURLOPT_FRESH_CONNECT, true); }
    if (!empty($st['nogzip'])) curl_setopt($handle, CURLOPT_ENCODING, 'identity');
}, 10, 1);

/** Ред в дневника на текущата проверка (пази се в state.last_log, макс. 40 реда). */
function babh6_sync_log($line) {
    $s = babh6_sync_state();
    $log = isset($s['last_log']) && is_array($s['last_log']) ? $s['last_log'] : array();
    $log[] = date_i18n('H:i:s') . ' ' . $line;
    if (count($log) > 40) $log = array_slice($log, -40);
    babh6_sync_state_set(array('last_log' => $log));
}

function babh6_sync_err_short($e) {
    $m = is_wp_error($e) ? $e->get_error_message() : (string)$e;
    return mb_substr($m, 0, 160, 'UTF-8');
}

function babh6_sync_timeout() {
    $t = (int)get_option('babh6_sync_timeout', 300);
    return (int)apply_filters('babh6_sync_page_timeout', $t >= 30 ? $t : 300);
}

/**
 * Тегли HTML-а на страницата на регистъра с дадена стратегия. Порталът на
 * БАБХ праща страницата бавно и на части, затова стриймваме във файл: при
 * timeout ползваме вече полученото, ако линковете са вътре (проверява се после).
 * @return array|WP_Error {html, partial(bool), note, secs, bytes, cookies}
 */
function babh6_sync_fetch_page($url, $strategy = array()) {
    $dir = wp_upload_dir();
    $base = trailingslashit($dir['basedir']) . 'babh6';
    wp_mkdir_p($base);
    $tmp = $base . '/page-' . time() . '-' . wp_rand(100, 999) . '.html';
    $timeout = babh6_sync_timeout();
    $t0  = microtime(true);
    $res = wp_remote_get($url, babh6_sync_http_args(array('timeout' => $timeout, 'stream' => true, 'filename' => $tmp), $strategy));
    $secs = round(microtime(true) - $t0, 1);
    $GLOBALS['babh6_sync_curl'] = null;
    $body = is_file($tmp) ? (string)file_get_contents($tmp) : '';
    @unlink($tmp);
    $bytes = strlen($body);
    if (is_wp_error($res)) {
        if ($body !== '') {
            return array('html' => $body, 'partial' => true, 'secs' => $secs, 'bytes' => $bytes, 'cookies' => array(),
                'note' => $res->get_error_message() . ' — използват се получените ' . number_format_i18n($bytes) . ' байта за ' . $secs . ' сек.');
        }
        $res->add_data(array('secs' => $secs));
        return $res;
    }
    $code = (int)wp_remote_retrieve_response_code($res);
    if ($code !== 200) return new WP_Error('babh6_sync_http', 'Порталът на БАБХ върна HTTP ' . $code . ' (' . $secs . ' сек).');
    if ($body === '') $body = (string)wp_remote_retrieve_body($res);
    if ($body === '') return new WP_Error('babh6_sync_empty', 'Порталът на БАБХ върна празен отговор (HTTP 200, ' . $secs . ' сек).');
    $bytes = strlen($body);
    return array('html' => $body, 'partial' => false, 'secs' => $secs, 'bytes' => $bytes,
        'cookies' => (array)wp_remote_retrieve_cookies($res),
        'note' => 'Страницата е свалена за ' . $secs . ' сек (' . number_format_i18n($bytes) . ' байта).');
}

/**
 * Пробва стратегиите подред (до 2 кръга, ако провалите са бързи — напр.
 * cURL 52). Връща резултата на първата успешна + името ѝ в 'strategy'.
 */
function babh6_sync_fetch_page_retry($url) {
    $last = null;
    for ($round = 1; $round <= 2; $round++) {
        $quick_fail = true;
        foreach (babh6_sync_strategies() as $name => $st) {
            babh6_sync_state_set(array('last_message' => 'Сваляне на страницата на БАБХ (' . $name . ', кръг ' . $round . ', до ' . babh6_sync_timeout() . ' сек)…', 'hb' => time()));
            $r = babh6_sync_fetch_page($url, $st);
            if (!is_wp_error($r)) {
                babh6_sync_log('страница [' . $name . '] OK: ' . $r['note']);
                update_option('babh6_sync_strategy', $name);
                $r['strategy'] = $name;
                return $r;
            }
            $d = $r->get_error_data();
            $secs = is_array($d) && isset($d['secs']) ? $d['secs'] : 0;
            babh6_sync_log('страница [' . $name . '] грешка: ' . babh6_sync_err_short($r));
            if ($secs > 15) $quick_fail = false;
            $last = $r;
            sleep(3);
        }
        if (!$quick_fail) break;
        if ($round === 1) { babh6_sync_log('всички стратегии паднаха бързо — пауза 15 сек и втори кръг'); sleep(15); }
    }
    return $last;
}

/**
 * При частично свалена страница: сигурни ли сме, че списъкът с линкове е пълен?
 * Порталът затваря портлета с </section> след съдържанието — трябва да го има
 * СЛЕД последния линк. Иначе може да сме хванали само „част 1" и import-ът
 * би заличил „част 2".
 */
function babh6_sync_links_complete($html, $links) {
    $last = 0;
    foreach ($links as $l) {
        $pos = strpos($html, $l['path']);
        if ($pos !== false && $pos > $last) $last = $pos;
    }
    $tail = substr($html, $last);
    return (bool)preg_match('#</section>|</html>#i', $tail);
}

/**
 * Намира актуалните .xlsx линкове в HTML-а на страницата.
 * Порталът оставя старите версии като празни <a></a> след активния линк — взимаме
 * само линковете с текст. Връща масив от ['url','path','title','part','date'].
 */
function babh6_sync_parse_links($html, $base = 'https://bfsa.egov.bg', $page_url = '') {
    $out = array();
    if (!preg_match_all('#<a\b([^>]*)>(.*?)</a>#isu', $html, $all, PREG_SET_ORDER)) return $out;
    $seen = array();
    $i = 0;
    /* Относителни адреси се разрешават спрямо папката на страницата, не спрямо root (SY-02) */
    $dir = $page_url !== '' ? preg_replace('#[^/]*$#', '', preg_replace('/[?#].*$/', '', $page_url)) : rtrim($base, '/') . '/';
    foreach ($all as $a) {
        if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/is', $a[1], $hm)) continue;
        $href = html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = preg_replace('/[?#].*$/', '', $href);
        /* Само .xlsx — reader-ът очаква ZIP/XML структура; .xls не може да бъде обработен */
        if (!preg_match('/\.xlsx$/i', $path)) continue;
        $title = trim(html_entity_decode(wp_strip_all_tags($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '') continue; /* стара версия (празен anchor) */
        $url = $href;
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        elseif (strpos($url, '/') === 0) $url = rtrim($base, '/') . $url;
        elseif (!preg_match('#^https?://#i', $url)) $url = $dir . ltrim($url, './');
        $key = strtolower($path);
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $decoded = rawurldecode(str_replace('+', ' ', $path));
        $date = '';
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $decoded, $dm)) $date = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
        $part = 0;
        if (preg_match('/част\s*(\d+)/iu', $title, $pm)) $part = (int)$pm[1];
        elseif (preg_match('/supplements[\s+]*(\d+)/i', $decoded, $pm)) $part = (int)$pm[1];
        $i++;
        $out[] = array('url' => $url, 'path' => $path, 'title' => $title, 'part' => $part ? $part : $i, 'date' => $date);
    }
    usort($out, function ($a, $b) { return $a['part'] - $b['part']; });
    return $out;
}

/** "Последна актуализация: 28.09.2026" от страницата → Y-m-d или ''. */
function babh6_sync_parse_updated($html) {
    if (preg_match('/Последна\s+актуализация:?\s*(\d{2})\.(\d{2})\.(\d{4})/iu', $html, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    return '';
}

function babh6_sync_signature($links, $updated = '') {
    $paths = array();
    foreach ($links as $l) $paths[] = strtolower($l['path']);
    sort($paths);
    return md5(implode("\n", $paths) . '|' . $updated);
}

/** Пълнота на пакета (SY-02): при номерирани части трябва да са точно 1..N без пропуски. */
function babh6_sync_parts_gap($links) {
    $nums = array();
    foreach ($links as $l) {
        if (preg_match('/част\s*(\d+)/iu', $l['title'], $m)) $nums[] = (int)$m[1];
    }
    if (!$nums) return '';
    sort($nums);
    $expect = range(1, max($nums));
    if ($nums !== $expect) return 'намерени са части ' . implode(', ', $nums) . ' — липсва номер или има дубликат';
    return '';
}

/** Hash на съдържанието на свалените файлове — идентично съдържание не се обработва повторно (SY-01). */
function babh6_sync_files_hash($files) {
    $h = array();
    foreach ((array)$files as $f) { $src = is_array($f) ? $f['src'] : $f; $h[] = is_file($src) ? md5_file($src) : ''; }
    sort($h);
    return md5(implode('|', $h));
}

/* ============ Сваляне на порции с продължаване (resumable) ============
 * Порталът дава ~10-15 KB/s, а файловете са мегабайти → едно сваляне трае
 * над 10 мин и надхвърля лимита на една PHP заявка. Затова всяка фонова
 * заявка тегли ~1 мин, записва .part файла и продължава (HTTP Range) в
 * следващата. Състоянието е в option 'babh6_sync_dl'.
 */

function babh6_sync_dl_state() {
    $s = get_option('babh6_sync_dl');
    return is_array($s) ? $s : null;
}

function babh6_sync_dl_cleanup($dl) {
    if (!$dl) return;
    foreach ((array)$dl['links'] as $i => $l) @unlink($dl['base'] . '/dl-' . $dl['started'] . '-' . ($i + 1) . '.part');
    foreach ((array)$dl['done'] as $f) @unlink($f['src']);
    delete_option('babh6_sync_dl');
    babh6_lock_release('dl');
}

function babh6_sync_fmt_bytes($b) {
    if ($b >= 1048576) return number_format_i18n($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return number_format_i18n($b / 1024, 0) . ' KB';
    return (int)$b . ' B';
}

/**
 * Тегли (или дотегля) един файл с native cURL до $budget секунди.
 * @return array {status: 'done'|'partial'|'error', message, bytes}
 */
function babh6_sync_curl_download($url, $part, $budget, $label, $strategy = array(), $referer = '') {
    $have = is_file($part) ? (int)filesize($part) : 0;
    if (!function_exists('curl_init')) {
        /* Без cURL: цял файл наведнъж през WP HTTP (без порции) */
        $res = wp_remote_get($url, babh6_sync_http_args(array('timeout' => 3600, 'stream' => true, 'filename' => $part, 'headers' => $referer ? array('Referer' => $referer) : array()), $strategy));
        if (is_wp_error($res)) { @unlink($part); return array('status' => 'error', 'message' => $res->get_error_message(), 'bytes' => 0); }
        $code = (int)wp_remote_retrieve_response_code($res);
        if ($code !== 200) { @unlink($part); return array('status' => 'error', 'message' => 'HTTP ' . $code, 'bytes' => 0); }
        return array('status' => 'done', 'message' => 'OK', 'bytes' => (int)@filesize($part));
    }

    $fh = @fopen($part, $have ? 'ab' : 'wb');
    if (!$fh) return array('status' => 'error', 'message' => 'Файлът не може да бъде записан: ' . $part, 'bytes' => $have);

    $headers = array('Accept: */*', 'Accept-Language: bg,en;q=0.8');
    if (!empty($strategy['nogzip'])) $headers[] = 'Accept-Encoding: identity';
    if (!empty($strategy['close']))  $headers[] = 'Connection: close';
    if ($referer) $headers[] = 'Referer: ' . $referer;

    $t0 = microtime(true); $last_ui = 0; $base_have = $have; $aborted = false;
    $progress = function ($ch, $dltotal, $dlnow) use ($budget, $t0, &$last_ui, $base_have, $label, &$aborted) {
        $el = microtime(true) - $t0;
        if ($el - $last_ui >= 3) {
            $last_ui = $el;
            $speed = $el > 0 ? $dlnow / $el : 0;
            $tot = $base_have + $dlnow;
            $full = $dltotal > 0 ? ' от ' . babh6_sync_fmt_bytes($base_have + $dltotal) : '';
            $wait = ($dlnow == 0) ? ' — изчакване на първите байтове от портала (' . (int)$el . ' сек)' : '';
            /* heartbeat: съобщение + подновяване на lock-а (умрял процес → lock изтича за 3 мин) */
            babh6_sync_state_set(array('last_message' => 'Сваляне на ' . $label . ': ' . babh6_sync_fmt_bytes($tot) . $full . ' (' . babh6_sync_fmt_bytes($speed) . '/s)' . $wait . '…', 'hb' => time()));
            babh6_lock_refresh('dl', 3 * MINUTE_IN_SECONDS);
        }
        if ($budget > 0 && $el > $budget) { $aborted = true; return 1; }
        return 0;
    };

    $ch = curl_init($url);
    $opts = array(
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 3600,
        CURLOPT_LOW_SPEED_LIMIT => 1,     /* под 1 B/s … */
        CURLOPT_LOW_SPEED_TIME  => 300,   /* … за 300 сек = блокирала връзка (порталът бави първите байтове с минути) */
        CURLOPT_USERAGENT      => isset($strategy['ua']) ? $strategy['ua'] : 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => $progress,
        CURLOPT_FAILONERROR    => false,
    );
    if (!empty($strategy['http11']) && defined('CURL_HTTP_VERSION_1_1')) $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    if (!empty($strategy['close'])) { $opts[CURLOPT_FORBID_REUSE] = true; $opts[CURLOPT_FRESH_CONNECT] = true; }
    $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    if (is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    if ($have > 0) $opts[CURLOPT_RESUME_FROM] = $have;
    /* Само http/https, включително при redirect; ограничен размер на файла */
    if (defined('CURLPROTO_HTTPS')) { $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP; $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP; }
    $opts[CURLOPT_MAXFILESIZE] = (int)apply_filters('babh6_sync_max_file_bytes', 200 * 1048576);
    /* Филтърът се прилага ПРЕДИ опциите да стигнат до handle-а (SY-07) */
    $opts = apply_filters('babh6_sync_curl_opts', $opts, $url);
    curl_setopt_array($ch, $opts);

    curl_exec($ch);
    $errno = curl_errno($ch); $err = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $got   = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fh);
    clearstatcache(true, $part);
    $size = (int)@filesize($part);
    $secs = round(microtime(true) - $t0, 1);

    if ($errno === 42 || $aborted) { /* CURLE_ABORTED_BY_CALLBACK — изтече бюджетът на заявката */
        return array('status' => 'partial', 'message' => 'порция ' . babh6_sync_fmt_bytes($got) . ' за ' . $secs . ' сек, общо ' . babh6_sync_fmt_bytes($size), 'bytes' => $size);
    }
    if ($errno === 33) { /* CURLE_RANGE_ERROR — сървърът не поддържа Range: започваме отначало */
        @unlink($part);
        return array('status' => 'error', 'message' => 'сървърът не поддържа продължаване (Range) — започвам отначало', 'bytes' => 0);
    }
    if ($errno) {
        /* Мрежова грешка по средата — .part файлът остава и ще бъде продължен */
        if ($size > $have) return array('status' => 'partial', 'message' => 'прекъсване (cURL ' . $errno . ': ' . $err . ') след ' . babh6_sync_fmt_bytes($got) . ' — ще продължа от ' . babh6_sync_fmt_bytes($size), 'bytes' => $size);
        return array('status' => 'error', 'message' => 'cURL ' . $errno . ': ' . $err . ' (' . $secs . ' сек)', 'bytes' => $size);
    }
    if ($code !== 200 && $code !== 206) {
        @unlink($part);
        return array('status' => 'error', 'message' => 'HTTP ' . $code . ' (' . $secs . ' сек)', 'bytes' => 0);
    }
    return array('status' => 'done', 'message' => babh6_sync_fmt_bytes($size) . ' за ' . $secs . ' сек' . ($have ? ' (последна порция)' : ''), 'bytes' => $size);
}

/**
 * Една стъпка от свалянето: тегли текущия файл до $budget сек (0 = без лимит).
 * @return array {status: 'none'|'busy'|'progress'|'started'|'error', message}
 */
function babh6_sync_dl_step($budget = 1200) {
    $dl = babh6_sync_dl_state();
    if (!$dl) return array('status' => 'none', 'message' => '');
    if (!empty($dl['retry_after']) && (int)$dl['retry_after'] > time() && $budget > 0) {
        return array('status' => 'wait', 'message' => 'Изчакване преди нов опит.', 'until' => (int)$dl['retry_after']);
    }
    if (!babh6_lock_acquire('dl', 3 * MINUTE_IN_SECONDS)) return array('status' => 'busy', 'message' => 'Свалянето тече в друга заявка.');
    babh6_sync_state_set(array('hb' => time()));
    $r = babh6_sync_dl_step_locked($dl, $budget);
    babh6_lock_release('dl');
    return $r;
}

function babh6_sync_dl_step_locked($dl, $budget) {
    $now = current_time('mysql');
    if (time() - (int)$dl['started'] > 3 * HOUR_IN_SECONDS) {
        babh6_sync_dl_cleanup($dl);
        return babh6_sync_fail($now, 'Свалянето отне над 3 часа и беше прекратено.', $dl['context']);
    }
    $n = count($dl['links']); $i = (int)$dl['idx']; $l = $dl['links'][$i];
    $label = 'файл ' . ($i + 1) . '/' . $n;
    $part  = $dl['base'] . '/dl-' . $dl['started'] . '-' . ($i + 1) . '.part';
    $r = babh6_sync_curl_download($l['url'], $part, $budget, $label, babh6_sync_strategy_by_name($dl['strategy']), babh6_sync_url());

    /* Порталът държи прекъснатата връзка и отговаря „Empty reply" на нова →
       след прекъсване/грешка изчакваме 1-4 мин преди да продължим. */
    if ($r['status'] === 'partial') {
        babh6_sync_log($label . ': ' . $r['message']);
        $dl['tries'] = 0;
        $dl['retry_after'] = time() + 60;
        update_option('babh6_sync_dl', $dl, false);
        if ($budget <= 0) sleep(60);
        return array('status' => 'progress', 'message' => $r['message']);
    }
    if ($r['status'] === 'error') {
        $dl['tries'] = (int)$dl['tries'] + 1;
        $wait = min(240, 60 * $dl['tries']);
        babh6_sync_log($label . ' опит ' . $dl['tries'] . '/6 грешка: ' . $r['message'] . ' → нов опит след ' . $wait . ' сек');
        if ($dl['tries'] >= 6) {
            babh6_sync_dl_cleanup($dl);
            return babh6_sync_fail($now, ucfirst($label) . ': ' . $r['message'] . ' (6 опита — виж дневника).', $dl['context']);
        }
        $dl['retry_after'] = time() + $wait;
        update_option('babh6_sync_dl', $dl, false);
        babh6_sync_state_set(array('last_message' => ucfirst($label) . ': ' . $r['message'] . ' — нов опит след ' . $wait . ' сек (' . $dl['tries'] . '/6)…', 'hb' => time()));
        if ($budget <= 0) sleep($wait);
        return array('status' => 'progress', 'message' => $r['message']);
    }

    /* done → проверка, че е XLSX (zip) а не HTML страница */
    $fh = @fopen($part, 'rb'); $magic = $fh ? fread($fh, 2) : ''; if ($fh) fclose($fh);
    if ($r['bytes'] <= 0 || $magic !== 'PK') {
        @unlink($part);
        $dl['tries'] = (int)$dl['tries'] + 1;
        babh6_sync_log($label . ' опит ' . $dl['tries'] . '/4: отговорът не е .xlsx (' . babh6_sync_fmt_bytes($r['bytes']) . ')');
        if ($dl['tries'] >= 4) { babh6_sync_dl_cleanup($dl); return babh6_sync_fail($now, ucfirst($label) . ': порталът връща нещо, което не е .xlsx (4 опита).', $dl['context']); }
        update_option('babh6_sync_dl', $dl, false);
        return array('status' => 'progress', 'message' => 'не е .xlsx');
    }
    $tmp = $dl['base'] . '/dl-' . $dl['started'] . '-' . ($i + 1) . '.tmp';
    @rename($part, $tmp);
    $name = basename(rawurldecode(str_replace('+', ' ', $l['path'])));
    $dl['done'][] = array('src' => $tmp, 'name' => sanitize_file_name($name), 'title' => $l['title']);
    $dl['idx'] = $i + 1; $dl['tries'] = 0; $dl['retry_after'] = 0;
    babh6_sync_log($label . ' OK: ' . $name . ' — ' . $r['message']);

    if ($dl['idx'] < $n) {
        update_option('babh6_sync_dl', $dl, false);
        babh6_sync_state_set(array('last_message' => 'Сваляне на файл ' . ($dl['idx'] + 1) . ' от ' . $n . '…'));
        return array('status' => 'progress', 'message' => 'следващ файл');
    }

    /* Всички файлове са свалени → сравнение по съдържание (SY-01), после import job */
    delete_option('babh6_sync_dl');
    $hash = babh6_sync_files_hash($dl['done']);
    $state = babh6_sync_state();
    if (empty($dl['force']) && !empty($state['last_hash']) && $state['last_hash'] === $hash) {
        foreach ($dl['done'] as $f) @unlink($f['src']);
        $msg = 'Файловете са свалени, но съдържанието им е същото като при последния успешен импорт. Няма нова версия.';
        babh6_sync_log($msg);
        babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'nochange', 'last_message' => $msg, 'last_sig' => $dl['sig'], 'pending_sig' => ''));
        return array('status' => 'nochange', 'message' => $msg);
    }
    $job = babh6_job_create_files($dl['done'], 'auto', array('sig' => $dl['sig'], 'hash' => $hash, 'context' => $dl['context'], 'portal_updated' => $dl['updated']));
    if (is_wp_error($job)) {
        foreach ($dl['done'] as $f) @unlink($f['src']);
        return babh6_sync_fail($now, $job->get_error_message(), $dl['context']);
    }
    $msg = 'Свалени ' . $n . ' файла от БАБХ. Обработката на записите тече.';
    babh6_sync_log($msg);
    babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'started', 'last_message' => $msg, 'pending_sig' => $dl['sig']));
    babh6_sync_kick();
    return array('status' => 'started', 'message' => $msg);
}

/* ============ Основно изпълнение ============ */

/**
 * Проверява портала и при нови файлове стартира import.
 * @param bool   $force   импортирай дори ако линковете не са променени
 * @param string $context 'cron' | 'manual'
 * @return array {status: 'started'|'nochange'|'busy'|'error', message}
 */
function babh6_sync_run($force = false, $context = 'cron', $inline = false) {
    @set_time_limit(1800);
    $now = current_time('mysql');

    /* Има ли вече активен import / сваляне? Cron не прекъсва пресен ръчен процес. */
    $job = get_option('babh6_job');
    if ($job) {
        $age = time() - (int)(isset($job['created']) ? $job['created'] : 0);
        if ($context === 'cron' && $age < 2 * HOUR_IN_SECONDS) {
            $msg = 'Има активна обработка (' . (isset($job['filename']) ? $job['filename'] : '') . '). Проверката е отложена.';
            babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'busy', 'last_message' => $msg));
            return array('status' => 'busy', 'message' => $msg);
        }
    }
    $dl = babh6_sync_dl_state();
    if ($dl) {
        if ($context === 'cron' && time() - (int)$dl['started'] < 3 * HOUR_IN_SECONDS) {
            babh6_sync_kick();
            return array('status' => 'busy', 'message' => 'Свалянето от предишна проверка още тече.');
        }
        babh6_sync_dl_cleanup($dl);
    }

    /* Атомарен lock: една проверка наведнъж (loopback + cron fallback могат да съвпаднат) */
    if (!babh6_lock_acquire('check', 30 * MINUTE_IN_SECONDS)) {
        return array('status' => 'busy', 'message' => 'Проверката вече тече.');
    }
    $r = babh6_sync_run_locked($force, $context, $now, $inline);
    babh6_lock_release('check');
    return $r;
}

function babh6_sync_run_locked($force, $context, $now, $inline = false) {
    $url = babh6_sync_url();
    babh6_sync_state_set(array('last_result' => 'running', 'last_message' => 'Сваляне на страницата на БАБХ (до ' . babh6_sync_timeout() . ' сек)…', 'queued_force' => 0, 'last_log' => array()));
    babh6_sync_log('старт (' . $context . ($force ? ', принудително' : '') . ') → ' . $url);

    $page = babh6_sync_fetch_page_retry($url);
    if (is_wp_error($page)) {
        return babh6_sync_fail($now, 'Страницата на БАБХ: ' . $page->get_error_message() . ' Всички HTTP стратегии са опитани; подробности в дневника. При изтекло време увеличи „Време за изчакване на портала“.', $context);
    }
    $html  = $page['html'];
    $links = babh6_sync_parse_links($html, babh6_sync_base($url), $url);
    if (!$links) {
        return babh6_sync_fail($now, ($page['partial'] ? 'Порталът отговори частично (' . $page['note'] . ') и линковете не са в получената част. ' : '') .
            'Не намерих .xlsx линкове на страницата на БАБХ' . ($page['partial'] ? ' — вдигни „Timeout за портала" и опитай пак.' : ' — структурата ѝ може да е променена.'), $context);
    }
    if ($page['partial'] && !babh6_sync_links_complete($html, $links)) {
        return babh6_sync_fail($now, 'Порталът отговори частично (' . $page['note'] . ') и не мога да гарантирам, че списъкът с файлове е пълен (намерих ' . count($links) . '). Вдигни „Timeout за портала" и опитай пак.', $context);
    }

    babh6_sync_log('намерени ' . count($links) . ' файла: ' . implode('; ', array_map(function ($l) { return basename(rawurldecode(str_replace('+', ' ', $l['path']))); }, $links)));
    $gap = babh6_sync_parts_gap($links);
    if ($gap !== '') {
        return babh6_sync_fail($now, 'Пакетът на портала не изглежда пълен: ' . $gap . '. Нищо не е обработено, за да не бъдат отбелязани валидни записи като липсващи.', $context);
    }
    $updated = babh6_sync_parse_updated($html);
    $sig     = babh6_sync_signature($links, $updated);
    $state   = babh6_sync_state();
    $files_h = array();
    foreach ($links as $l) $files_h[] = array('title' => $l['title'], 'date' => $l['date'], 'url' => $l['url']);

    if (!$force && isset($state['last_sig']) && $state['last_sig'] === $sig) {
        $msg = 'Адресите и датата на портала са непроменени (актуализация на портала ' . ($updated ? date_i18n('d.m.Y', strtotime($updated)) : '—') . '); съдържанието не е сваляно повторно.';
        babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'nochange', 'last_message' => $msg,
                                   'portal_updated' => $updated, 'portal_files' => $files_h));
        return array('status' => 'nochange', 'message' => $msg);
    }

    /* Сваляне */
    $dir  = wp_upload_dir();
    $base = trailingslashit($dir['basedir']) . 'babh6';
    if (!wp_mkdir_p($base)) return babh6_sync_fail($now, 'Не може да се създаде папката за качвания. Провери правата за запис. (' . $base . ')', $context);

    $dl = array(
        'links' => $links, 'sig' => $sig, 'updated' => $updated, 'context' => $context,
        'strategy' => $page['strategy'], 'base' => $base, 'started' => time(), 'force' => $force ? 1 : 0,
        'idx' => 0, 'done' => array(), 'tries' => 0,
    );
    update_option('babh6_sync_dl', $dl, false);
    $msg = 'Страницата е свалена (' . $page['note'] . '). Сваляне на файл 1 от ' . count($links) . '…';
    babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'running', 'last_message' => $msg,
                               'portal_updated' => $updated, 'portal_files' => $files_h, 'pending_sig' => $sig, 'running_since' => time()));

    if ($inline) {
        /* „Пусни директно": свалянето в същата заявка, без порции */
        do { $r = babh6_sync_dl_step(0); } while ($r['status'] === 'progress');
        return $r;
    }
    /* Фоново: порции по ~1 мин, всяка кикна следващата */
    babh6_sync_kick();
    return array('status' => 'started', 'message' => $msg);
}

function babh6_sync_strategy_by_name($name) {
    $all = babh6_sync_strategies();
    return isset($all[$name]) ? $all[$name] : array();
}

function babh6_sync_base($url) {
    $p = wp_parse_url($url);
    if (empty($p['host'])) return 'https://bfsa.egov.bg';
    return (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function babh6_sync_fail($now, $message, $context) {
    babh6_sync_log('ГРЕШКА: ' . $message);
    babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'error', 'last_message' => $message));
    $st = babh6_sync_state();
    babh6_sync_notify('Грешка при автоматично обновяване от БАБХ', "Проверката (" . $context . ") не успя:\n\n" . $message . "\n\nАдрес: " . babh6_sync_url() .
        "\n\nДневник:\n" . implode("\n", isset($st['last_log']) ? (array)$st['last_log'] : array()));
    return array('status' => 'error', 'message' => $message);
}

/**
 * Пуска фоновата работа: (1) loopback заявка към admin-ajax (тръгва веднага,
 * не чака посещение — като Action Scheduler) и (2) WP-Cron събитие след 1 мин
 * като резервен вариант, ако loopback-ът е блокиран от хостинга.
 */
function babh6_sync_kick() {
    if (!wp_next_scheduled('babh6_sync_process')) wp_schedule_single_event(time() + 60, 'babh6_sync_process');
    babh6_sync_loopback();
}

/** Насрочва работника за конкретен момент (без loopback — за изчакване преди нов опит). */
function babh6_sync_kick_at($ts) {
    $next = wp_next_scheduled('babh6_sync_process');
    if ($next && $next <= $ts) return;
    if ($next) wp_unschedule_event($next, 'babh6_sync_process');
    wp_schedule_single_event($ts, 'babh6_sync_process');
}

function babh6_sync_loopback() {
    $token = wp_generate_password(32, false);
    set_transient('babh6_bg_token', $token, 10 * MINUTE_IN_SECONDS);
    wp_remote_post(admin_url('admin-ajax.php'), array(
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
        'body'      => array('action' => 'babh6_bg', 'token' => $token),
    ));
}

add_action('wp_ajax_nopriv_babh6_bg', 'babh6_sync_bg_handler');
add_action('wp_ajax_babh6_bg', 'babh6_sync_bg_handler');
function babh6_sync_bg_handler() {
    $token = isset($_POST['token']) ? (string)wp_unslash($_POST['token']) : '';
    $want  = get_transient('babh6_bg_token');
    if ($token === '' || !$want || !hash_equals((string)$want, $token)) wp_die('', '', array('response' => 403));
    delete_transient('babh6_bg_token');
    ignore_user_abort(true);
    babh6_sync_worker();
    wp_die();
}

/**
 * Фонов работник (loopback или cron): изпълнява чакаща ръчна проверка и/или
 * върти ETL стъпки в рамките на времеви бюджет, после се пренасрочва сам,
 * докато import-ът приключи.
 */
function babh6_sync_worker() {
    @set_time_limit(1800);
    $state = babh6_sync_state();
    if (isset($state['last_result']) && $state['last_result'] === 'queued') {
        $r = babh6_sync_run(!empty($state['queued_force']), 'manual');
        if ($r['status'] !== 'started') return; /* started → sync_run вече е пуснал kick */
    }
    $limit  = (int)ini_get('max_execution_time');
    if (babh6_sync_dl_state()) {
        /* Една дълга връзка на файл (до 20 мин): порталът не търпи чести прекъсвания.
           Ако хостингът убие процеса, .part файлът остава и следващият работник продължава. */
        $slice = (int)apply_filters('babh6_sync_slice', 1200);
        if ($limit > 0 && $limit - 30 < $slice) $slice = max(45, $limit - 30);
        $r = babh6_sync_dl_step($slice);
        if ($r['status'] === 'progress' || $r['status'] === 'wait') {
            $dl = babh6_sync_dl_state();
            $at = ($dl && !empty($dl['retry_after'])) ? max(time() + 5, (int)$dl['retry_after']) : time() + 5;
            babh6_sync_kick_at($at);
            return;
        }
        if ($r['status'] === 'busy') { babh6_sync_kick_at(time() + 120); return; } /* пазач: продължава, ако процесът е умрял */
        if ($r['status'] !== 'started') return; /* грешка → записана; started → kick вече е пуснат */
    }
    if (!get_option('babh6_job')) return;
    $budget = ($limit > 0) ? min(50, max(15, $limit - 10)) : 50;
    $start  = microtime(true);
    $done   = false;
    do {
        $res = babh6_run_step();
        if (is_wp_error($res)) { $done = true; break; } /* грешката е записана в uploads + hook-ове */
        if (!empty($res['done'])) { $done = true; break; }
        if (isset($res['phase']) && $res['phase'] === 'busy') { break; } /* AJAX-ът движи job-а в момента */
    } while ((microtime(true) - $start) < $budget);
    if (!$done && get_option('babh6_job')) babh6_sync_kick();
}
function babh6_sync_process_cron() { babh6_sync_worker(); }

/** Статус за dashboard-а (polling докато проверката тече на заден план). */
add_action('wp_ajax_babh6_sync_status', function () {
    if (!current_user_can('manage_options')) wp_send_json_error();
    check_ajax_referer('babh6_sync_status', 'nonce');
    $s = babh6_sync_state();
    wp_send_json_success(array(
        'result'  => isset($s['last_result']) ? $s['last_result'] : '',
        'message' => isset($s['last_message']) ? $s['last_message'] : '',
        'job'     => get_option('babh6_job') ? 1 : 0,
        'dl'      => babh6_sync_dl_state() ? 1 : 0,
    ));
});

/* ============ Резултат от import (hook-ове от ETL) ============ */

add_action('babh6_import_done', function ($upload_id, $result, $job) {
    if (empty($job['source']) || $job['source'] !== 'auto') return;
    $now = current_time('mysql');
    $msg = sprintf('Обработени записи: %s · нови записи: %s · с променено име или състав: %s · липсват във файла: %s · отново във файла: %s.',
        number_format_i18n((int)$result['parsed']), number_format_i18n((int)$result['added']),
        number_format_i18n((int)$result['updated']), number_format_i18n((int)$result['removed']),
        number_format_i18n((int)$result['restored']));
    if (!empty($result['notes'])) $msg .= ' ' . $result['notes'];
    $complete = !empty($result['removed_checked']);
    $patch = array(
        'pending_sig'    => '',
        'last_import_at' => $now,
        'last_result'    => $complete ? 'ok' : 'partial',
        'last_message'   => $complete ? $msg : 'Импортът приключи, но версията не е приета за пълна: ' . $msg,
        'last_files'     => isset($job['names']) ? $job['names'] : array(),
    );
    /* Подписът и hash-ът се запомнят само за приета ПЪЛНА версия — иначе следващата проверка опитва отново */
    if ($complete) {
        $patch['last_sig']  = isset($job['meta']['sig']) ? $job['meta']['sig'] : '';
        $patch['last_hash'] = isset($job['meta']['hash']) ? $job['meta']['hash'] : '';
    }
    babh6_sync_state_set($patch);
    babh6_sync_notify('БАБХ регистърът е обновен автоматично',
        "Файлове: " . implode(', ', isset($job['names']) ? $job['names'] : array()) . "\n\n" . $msg . "\n\n" . admin_url('admin.php?page=babh6'));
}, 10, 3);

add_action('babh6_import_failed', function ($upload_id, $message, $job) {
    if (empty($job['source']) || $job['source'] !== 'auto') return;
    babh6_sync_state_set(array('last_result' => 'error', 'last_message' => 'Обработката се провали: ' . $message, 'pending_sig' => ''));
    babh6_sync_notify('Грешка при автоматично обновяване от БАБХ', "Обработката на свалените файлове се провали:\n\n" . $message . "\n\n" . admin_url('admin.php?page=babh6'));
}, 10, 3);

/* Ръчно спиране / нова задача → същото изчистване като при отказ (SY-08) */
add_action('babh6_import_cancelled', function ($upload_id, $job) {
    if (empty($job['source']) || $job['source'] !== 'auto') return;
    babh6_sync_state_set(array('last_result' => 'cancelled', 'last_message' => 'Обработката е спряна; публикуваната версия не е променена.', 'pending_sig' => ''));
}, 10, 2);

function babh6_sync_notify($subject, $body) {
    if ((int)get_option('babh6_sync_notify', 1) !== 1) return;
    $to = apply_filters('babh6_sync_notify_email', get_option('admin_email'));
    if (!$to) return;
    $ok = @wp_mail($to, '[' . get_bloginfo('name') . '] ' . $subject, $body);
    /* Резултатът от пощенската система се записва (SY-08) */
    babh6_sync_state_set(array('last_mail' => array('to' => $to, 'subject' => $subject, 'ok' => $ok ? 1 : 0, 'at' => current_time('mysql'))));
}

/* ============ Admin действия ============ */

add_action('admin_post_babh6_sync_now', function () {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_sync_now');
    $force = !empty($_POST['babh6_force']);
    if (!empty($_POST['babh6_direct'])) {
        /* Директно в тази заявка (ако фоновият режим не тръгва на хостинга) */
        @set_time_limit(3600);
        $r = babh6_sync_run($force, 'manual', true);
        if ($r['status'] === 'started') {
            /* файловете са свалени; редовете се обработват на dashboard-а (progress bar) */
            wp_safe_redirect(admin_url('admin.php?page=babh6'));
            exit;
        }
        wp_safe_redirect(add_query_arg(array('babh6_sync' => $r['status'], 'babh6_msg' => rawurlencode($r['message'])), admin_url('admin.php?page=babh6')));
        exit;
    }
    $dl = babh6_sync_dl_state();
    $st = babh6_sync_state();
    $hb_age = !empty($st['hb']) ? time() - (int)$st['hb'] : PHP_INT_MAX;
    if ($dl && time() - (int)$dl['started'] < 3 * HOUR_IN_SECONDS) {
        if ($hb_age < 4 * MINUTE_IN_SECONDS && !empty($st['last_result']) && $st['last_result'] === 'running') {
            wp_safe_redirect(add_query_arg(array('babh6_sync' => 'running', 'babh6_msg' => rawurlencode('Свалянето вече тече (' . $st['last_message'] . ').')), admin_url('admin.php?page=babh6')));
            exit;
        }
        /* процесът е умрял (heartbeat над 4 мин) → продължаваме от .part файла, не отначало */
        babh6_lock_release('dl'); babh6_lock_release('check');
        $dl['retry_after'] = 0; update_option('babh6_sync_dl', $dl, false);
        babh6_sync_log('ръчно продължаване на прекъснато сваляне (файл ' . ((int)$dl['idx'] + 1) . '/' . count($dl['links']) . ')');
        babh6_sync_state_set(array('last_result' => 'running', 'last_message' => 'Прекъснатото сваляне продължава…', 'hb' => time()));
        babh6_sync_kick();
        wp_safe_redirect(add_query_arg(array('babh6_sync' => 'running', 'babh6_msg' => rawurlencode('Прекъснатото сваляне продължава от последната записана част.')), admin_url('admin.php?page=babh6')));
        exit;
    }
    if ($hb_age > 4 * MINUTE_IN_SECONDS && !babh6_lock_held('check') && !babh6_lock_held('dl')) { babh6_lock_release('check'); babh6_lock_release('dl'); }
    babh6_sync_state_set(array('last_result' => 'queued', 'last_message' => 'Проверката е насрочена…', 'queued_force' => $force ? 1 : 0, 'queued_at' => time(), 'hb' => time()));
    babh6_sync_kick();
    wp_safe_redirect(add_query_arg(array('babh6_sync' => 'queued', 'babh6_msg' => rawurlencode('Проверката започна на заден план. Страницата ще се обнови сама.')), admin_url('admin.php?page=babh6')));
    exit;
});

add_action('admin_post_babh6_sync_settings', function () {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_sync_settings');
    update_option('babh6_sync_enabled', empty($_POST['babh6_sync_enabled']) ? 0 : 1);
    update_option('babh6_sync_notify', empty($_POST['babh6_sync_notify']) ? 0 : 1);
    $slots = sanitize_text_field(wp_unslash($_POST['babh6_sync_slots'] ?? ''));
    $bad = babh6_sync_slots_invalid($slots);
    if ($slots === '' || $bad || !babh6_sync_slots($slots)) {
        /* Невалиден график не се записва и не се подменя мълчаливо с друг (SY-06) */
        wp_safe_redirect(add_query_arg(array('babh6_err' => 'slots', 'babh6_msg' => rawurlencode($bad ? implode(', ', $bad) : $slots)), admin_url('admin.php?page=babh6')));
        exit;
    }
    update_option('babh6_sync_slots', $slots);
    $to = (int)($_POST['babh6_sync_timeout'] ?? 300);
    update_option('babh6_sync_timeout', min(1800, max(30, $to)));
    $url = esc_url_raw(trim((string)wp_unslash($_POST['babh6_sync_url'] ?? '')));
    update_option('babh6_sync_url', $url === BABH6_SYNC_DEFAULT_URL ? '' : $url);
    babh6_sync_schedule_next();
    wp_safe_redirect(add_query_arg('babh6_saved', 1, admin_url('admin.php?page=babh6')));
    exit;
});

/** HTML секция за dashboard-а. */
function babh6_sync_admin_section() {
    $state   = babh6_sync_state();
    $enabled = babh6_sync_enabled();
    $next    = wp_next_scheduled('babh6_sync_check');
    $slots   = (string)get_option('babh6_sync_slots', BABH6_SYNC_DEFAULT_SLOTS);
    $fmt     = function ($mysql) { return $mysql ? date_i18n('d.m.Y H:i', strtotime($mysql)) : '—'; };
    $colors  = array('ok' => '#00a32a', 'started' => '#2271b1', 'nochange' => '#787c82', 'busy' => '#dba617', 'error' => '#d63638', 'queued' => '#dba617', 'running' => '#2271b1', 'partial' => '#dba617', 'cancelled' => '#787c82');
    $res     = isset($state['last_result']) ? $state['last_result'] : '';

    if (isset($_GET['babh6_sync'])) {
        $st  = sanitize_key(wp_unslash($_GET['babh6_sync']));
        $msg = isset($_GET['babh6_msg']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['babh6_msg']))) : '';
        $cls = $st === 'error' ? 'notice-error' : (($st === 'started' || $st === 'queued') ? 'notice-success' : 'notice-info');
        echo '<div class="notice ' . $cls . ' is-dismissible"><p><b>Проверка в БАБХ:</b> ' . esc_html($msg) . '</p></div>';
    }

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;max-width:640px;margin-bottom:20px">';
    echo '<h2 style="margin-top:0">Автоматично обновяване от БАБХ</h2>';
    echo '<p style="color:#787c82">Плъгинът сам отваря <a href="' . esc_url(babh6_sync_url()) . '" target="_blank" rel="noopener">страницата на регистъра</a>, сваля актуалните .xlsx файлове (част 1, част 2, …) и ги обработва като едно качване. Ако файловете не са променени — не прави нищо.</p>';

    echo '<ul style="margin:0 0 14px;line-height:1.7">';
    echo '<li>Статус: ' . ($enabled ? '<b style="color:#00a32a">включено</b>' : '<b style="color:#d63638">изключено</b>') . '</li>';
    $next_h  = '—';
    if ($enabled && $next) $next_h = function_exists('wp_date') ? wp_date('l, d.m.Y H:i', $next) : date_i18n('l, d.m.Y H:i', $next + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS));
    echo '<li>Следваща проверка: <b>' . esc_html($next_h) . '</b> <span style="color:#787c82">(локално време на сайта, ' . esc_html(babh6_sync_timezone()->getName()) . ')</span></li>';
    echo '<li>Последна проверка: ' . esc_html($fmt(isset($state['last_check']) ? $state['last_check'] : '')) .
        ($res ? ' · <b id="babh6-sync-msg" style="color:' . esc_attr(isset($colors[$res]) ? $colors[$res] : '#1d2327') . '">' . esc_html(isset($state['last_message']) ? $state['last_message'] : $res) . '</b>' : '') . '</li>';
    echo '<li>Последен автоматичен импорт: ' . esc_html($fmt(isset($state['last_import_at']) ? $state['last_import_at'] : '')) . '</li>';
    if (!empty($state['last_mail']) && is_array($state['last_mail'])) {
        $lm = $state['last_mail'];
        echo '<li>Последен имейл: ' . esc_html($fmt($lm['at'])) . ' до ' . esc_html($lm['to']) . ' — ' . ($lm['ok'] ? '<span style="color:#00a32a">приет от пощенската система</span>' : '<b style="color:#d63638">не е приет от пощенската система (wp_mail върна грешка)</b>') . '</li>';
    }
    if (!empty($state['portal_files'])) {
        echo '<li>На портала сега: ';
        $parts = array();
        foreach ((array)$state['portal_files'] as $f) {
            $parts[] = '<a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('/^.*(част\s*\d+).*$/iu', '$1', $f['title'])) . '</a>' . ($f['date'] ? ' (' . esc_html(date_i18n('d.m.Y', strtotime($f['date']))) . ')' : '');
        }
        echo implode(', ', $parts) . (!empty($state['portal_updated']) ? ' · актуализация на портала: ' . esc_html(date_i18n('d.m.Y', strtotime($state['portal_updated']))) : '') . '</li>';
    }
    echo '</ul>';

    if (!empty($state['last_log'])) {
        echo '<details style="margin:0 0 12px"><summary style="cursor:pointer;color:#2271b1">Дневник на последната проверка</summary>';
        echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 10px;font-size:11px;white-space:pre-wrap;max-height:260px;overflow:auto;margin:6px 0 0">' . esc_html(implode("\n", (array)$state['last_log'])) . '</pre></details>';
    }

    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        echo '<p style="color:#dba617"><b>Внимание:</b> <code>DISABLE_WP_CRON</code> е включен. Графикът работи само ако системен cron вика <code>wp-cron.php</code> (напр. на всеки 5 мин).</p>';
    } else {
        echo '<p style="color:#787c82;font-size:12px">WP-Cron се задейства от посещения на сайта. При слаб трафик проверката може да закъснее до първото посещение след часа. За точност добави системен cron: <code>*/5 * * * * curl -s ' . esc_html(site_url('wp-cron.php')) . ' &gt;/dev/null</code></p>';
    }

    /* Живо обновяване, докато проверката тече на заден план */
    if ($res === 'queued' || $res === 'running') {
        $stale = $res === 'queued' && !empty($state['queued_at']) && (time() - (int)$state['queued_at']) > 180;
        if ($stale) echo '<p style="color:#d63638"><b>Фоновата проверка не тръгва</b> (чака от ' . esc_html(human_time_diff((int)$state['queued_at'])) . '). Хостингът вероятно блокира loopback заявки и WP-Cron. Използвай „Пусни директно" по-долу или системен cron.</p>';
        $dl = babh6_sync_dl_state();
        $hb_age = !empty($state['hb']) ? time() - (int)$state['hb'] : 0;
        if ($res === 'running' && $hb_age > 6 * MINUTE_IN_SECONDS && (!$dl || empty($dl['retry_after']) || (int)$dl['retry_after'] < time() - 120)) {
            echo '<p style="color:#d63638"><b>Фоновият процес не дава признак на живот от ' . esc_html(human_time_diff((int)$state['hb'])) . '</b> (хостингът вероятно го е спрял). Натисни „Провери и обнови сега" — свалянето ще продължи от там, докъдето е стигнало.</p>';
        }
        ?>
        <script>
        (function(){
            var nonce = <?php echo wp_json_encode(wp_create_nonce('babh6_sync_status')); ?>;
            var el = document.getElementById('babh6-sync-msg');
            var start = Date.now();
            function poll(){
                var fd = new FormData(); fd.append('action', 'babh6_sync_status'); fd.append('nonce', nonce);
                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }).then(function(j){
                    if (!j.success) { if (el) el.textContent = 'Състоянието не може да бъде прочетено (изтекла сесия или отказан достъп). Презареди страницата.'; return; }
                    var d = j.data;
                    if (el && d.message) el.textContent = d.message;
                    if (d.job || (d.result !== 'queued' && d.result !== 'running')) { location.reload(); return; }
                    if (Date.now() - start > 15 * 60 * 1000) { location.reload(); return; }
                    setTimeout(poll, 3000);
                }).catch(function(e){ if (el) el.textContent = 'Връзката със сървъра прекъсна (' + e.message + '); нов опит след 5 сек…'; setTimeout(poll, 5000); });
            }
            setTimeout(poll, 3000);
        })();
        </script>
        <?php
    }

    /* Провери сега */
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 18px;padding:10px 0;border-top:1px solid #f0f0f1;border-bottom:1px solid #f0f0f1">';
    wp_nonce_field('babh6_sync_now');
    echo '<input type="hidden" name="action" value="babh6_sync_now">';
    submit_button('Провери и обнови сега', 'primary', 'submit', false);
    echo ' <button type="submit" name="babh6_direct" value="1" class="button" title="Изпълнява проверката в тази заявка вместо на заден план. Ползвай, ако фоновият режим не тръгва.">Пусни директно</button>';
    echo ' <label style="margin-left:10px"><input type="checkbox" name="babh6_force" value="1"> Обработи файловете дори ако не са променени</label>';
    echo '</form>';

    /* Настройки */
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('babh6_sync_settings');
    echo '<input type="hidden" name="action" value="babh6_sync_settings">';
    echo '<p><label><input type="checkbox" name="babh6_sync_enabled" value="1"' . checked($enabled, true, false) . '> <b>Автоматично обновяване по график</b></label></p>';
    echo '<p style="margin-bottom:4px"><b>График</b> — ден и час, разделени със запетая (напр. <code>mon 06:00, fri 18:00</code> или <code>пон 06:00, пет 18:00</code>).</p>';
    echo '<input type="text" name="babh6_sync_slots" value="' . esc_attr($slots) . '" style="width:100%;max-width:300px">';
    echo '<p style="margin:14px 0 4px"><b>Време за изчакване на портала (сек)</b> — порталът на БАБХ праща страницата бавно, често над минута. При „Operation timed out“ увеличи стойността.</p>';
    echo '<input type="number" name="babh6_sync_timeout" value="' . esc_attr((int)get_option('babh6_sync_timeout', 300)) . '" min="30" max="1800" step="10" style="width:100px">';
    echo '<p style="margin:14px 0 4px"><b>Адрес на регистъра в БАБХ</b></p>';
    echo '<input type="url" name="babh6_sync_url" value="' . esc_attr(babh6_sync_url()) . '" style="width:100%;max-width:460px">';
    echo '<p style="margin-top:14px"><label><input type="checkbox" name="babh6_sync_notify" value="1"' . checked((int)get_option('babh6_sync_notify', 1), 1, false) . '> Изпращай имейл до ' . esc_html(get_option('admin_email')) . ' при обновяване или грешка</label></p>';
    echo '<p style="margin-top:14px">';
    submit_button('Запази настройките за обновяване', 'secondary', 'submit', false);
    echo '</p></form></div>';
}

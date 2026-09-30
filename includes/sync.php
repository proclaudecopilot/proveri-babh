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

function babh6_sync_http_args($extra = array()) {
    $args = array(
        'timeout'    => 60,
        'redirection' => 5,
        'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 proveri-babh/' . BABH6_VERSION,
        'headers'    => array('Accept-Language' => 'bg,en;q=0.8'),
    );
    return apply_filters('babh6_sync_http_args', array_merge($args, $extra));
}

/** Тегли HTML-а на страницата на регистъра. */
function babh6_sync_fetch_page($url) {
    $res = wp_remote_get($url, babh6_sync_http_args(array('timeout' => 45)));
    if (is_wp_error($res)) return $res;
    $code = (int)wp_remote_retrieve_response_code($res);
    if ($code !== 200) return new WP_Error('babh6_sync_http', 'Порталът на БАБХ върна HTTP ' . $code . '.');
    $body = (string)wp_remote_retrieve_body($res);
    if ($body === '') return new WP_Error('babh6_sync_empty', 'Порталът на БАБХ върна празен отговор.');
    return $body;
}

/**
 * Намира актуалните .xlsx линкове в HTML-а на страницата.
 * Порталът оставя старите версии като празни <a></a> след активния линк — взимаме
 * само линковете с текст. Връща масив от ['url','path','title','part','date'].
 */
function babh6_sync_parse_links($html, $base = 'https://bfsa.egov.bg') {
    $out = array();
    if (!preg_match_all('#<a\b([^>]*)>(.*?)</a>#isu', $html, $all, PREG_SET_ORDER)) return $out;
    $seen = array();
    $i = 0;
    foreach ($all as $a) {
        if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/is', $a[1], $hm)) continue;
        $href = html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = preg_replace('/[?#].*$/', '', $href);
        if (!preg_match('/\.xlsx?$/i', $path)) continue;
        $title = trim(html_entity_decode(wp_strip_all_tags($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '') continue; /* стара версия (празен anchor) */
        $url = $href;
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        elseif (strpos($url, '/') === 0) $url = rtrim($base, '/') . $url;
        elseif (!preg_match('#^https?://#i', $url)) $url = rtrim($base, '/') . '/' . ltrim($url, '/');
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

function babh6_sync_signature($links) {
    $paths = array();
    foreach ($links as $l) $paths[] = strtolower($l['path']);
    sort($paths);
    return md5(implode("\n", $paths));
}

/** Сваля файл на диска (stream). Проверява, че е XLSX (zip) а не HTML страница. */
function babh6_sync_download($url, $dest) {
    $res = wp_remote_get($url, babh6_sync_http_args(array('timeout' => 180, 'stream' => true, 'filename' => $dest)));
    if (is_wp_error($res)) { @unlink($dest); return $res; }
    $code = (int)wp_remote_retrieve_response_code($res);
    if ($code !== 200) { @unlink($dest); return new WP_Error('babh6_sync_dl', 'HTTP ' . $code . ' при сваляне на ' . $url); }
    $size = @filesize($dest);
    $fh = @fopen($dest, 'rb');
    $magic = $fh ? fread($fh, 2) : '';
    if ($fh) fclose($fh);
    if (!$size || $magic !== 'PK') { @unlink($dest); return new WP_Error('babh6_sync_notxlsx', 'Сваленият файл не е .xlsx (получих ' . (int)$size . ' байта): ' . $url); }
    return $dest;
}

/* ============ Основно изпълнение ============ */

/**
 * Проверява портала и при нови файлове стартира import.
 * @param bool   $force   импортирай дори ако линковете не са променени
 * @param string $context 'cron' | 'manual'
 * @return array {status: 'started'|'nochange'|'busy'|'error', message}
 */
function babh6_sync_run($force = false, $context = 'cron') {
    @set_time_limit(300);
    $now = current_time('mysql');

    /* Има ли вече активен import? Cron не прекъсва пресен ръчен import. */
    $job = get_option('babh6_job');
    if ($job) {
        $age = time() - (int)(isset($job['created']) ? $job['created'] : 0);
        if ($context === 'cron' && $age < 2 * HOUR_IN_SECONDS) {
            $msg = 'Има активен import (' . (isset($job['filename']) ? $job['filename'] : '') . ') — проверката е отложена.';
            babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'busy', 'last_message' => $msg));
            return array('status' => 'busy', 'message' => $msg);
        }
    }

    $url  = babh6_sync_url();
    $html = babh6_sync_fetch_page($url);
    if (is_wp_error($html)) return babh6_sync_fail($now, $html->get_error_message(), $context);

    $links = babh6_sync_parse_links($html, babh6_sync_base($url));
    if (!$links) return babh6_sync_fail($now, 'Не намерих .xlsx линкове на страницата на БАБХ — структурата ѝ може да е променена.', $context);

    $sig     = babh6_sync_signature($links);
    $updated = babh6_sync_parse_updated($html);
    $state   = babh6_sync_state();
    $files_h = array();
    foreach ($links as $l) $files_h[] = array('title' => $l['title'], 'date' => $l['date'], 'url' => $l['url']);

    if (!$force && isset($state['last_sig']) && $state['last_sig'] === $sig) {
        $msg = 'Няма нови файлове (актуализация ' . ($updated ? date_i18n('d.m.Y', strtotime($updated)) : '—') . ').';
        babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'nochange', 'last_message' => $msg,
                                   'portal_updated' => $updated, 'portal_files' => $files_h));
        return array('status' => 'nochange', 'message' => $msg);
    }

    /* Сваляне */
    $dir  = wp_upload_dir();
    $base = trailingslashit($dir['basedir']) . 'babh6';
    if (!wp_mkdir_p($base)) return babh6_sync_fail($now, 'Не мога да създам папка ' . $base, $context);

    $files = array();
    foreach ($links as $i => $l) {
        $tmp = $base . '/dl-' . time() . '-' . ($i + 1) . '.tmp';
        $r = babh6_sync_download($l['url'], $tmp);
        if (is_wp_error($r)) {
            foreach ($files as $f) @unlink($f['src']);
            return babh6_sync_fail($now, $r->get_error_message(), $context);
        }
        $name = basename(rawurldecode(str_replace('+', ' ', $l['path'])));
        $files[] = array('src' => $tmp, 'name' => sanitize_file_name($name), 'title' => $l['title']);
    }

    $job = babh6_job_create_files($files, 'auto', array('sig' => $sig, 'context' => $context, 'portal_updated' => $updated));
    if (is_wp_error($job)) {
        foreach ($files as $f) @unlink($f['src']);
        return babh6_sync_fail($now, $job->get_error_message(), $context);
    }

    $msg = 'Свалени ' . count($files) . ' файла от БАБХ — обработката тече.';
    babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'started', 'last_message' => $msg,
                               'portal_updated' => $updated, 'portal_files' => $files_h, 'pending_sig' => $sig));

    /* Фонова обработка (ако админът е на dashboard-а, AJAX-ът също я движи; lock пази от дублиране) */
    babh6_sync_kick();
    return array('status' => 'started', 'message' => $msg);
}

function babh6_sync_base($url) {
    $p = wp_parse_url($url);
    if (empty($p['host'])) return 'https://bfsa.egov.bg';
    return (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function babh6_sync_fail($now, $message, $context) {
    babh6_sync_state_set(array('last_check' => $now, 'last_result' => 'error', 'last_message' => $message));
    babh6_sync_notify('Грешка при автоматично обновяване от БАБХ', "Проверката (" . $context . ") не успя:\n\n" . $message . "\n\nАдрес: " . babh6_sync_url());
    return array('status' => 'error', 'message' => $message);
}

/** Насрочва фонова стъпка веднага. */
function babh6_sync_kick() {
    if (!wp_next_scheduled('babh6_sync_process')) wp_schedule_single_event(time(), 'babh6_sync_process');
}

/**
 * Cron: върти ETL стъпки в рамките на времеви бюджет, после се пренасрочва,
 * докато import-ът приключи.
 */
function babh6_sync_process_cron() {
    if (!get_option('babh6_job')) return;
    $limit = (int)ini_get('max_execution_time');
    $budget = ($limit > 0) ? min(50, max(15, $limit - 10)) : 50;
    $start = microtime(true);
    $done = false;
    do {
        $res = babh6_run_step();
        if (is_wp_error($res)) { $done = true; break; } /* грешката е записана в uploads + hook-ове */
        if (!empty($res['done'])) { $done = true; break; }
        if (isset($res['phase']) && $res['phase'] === 'busy') { break; } /* AJAX-ът движи job-а в момента */
    } while ((microtime(true) - $start) < $budget);
    if (!$done && get_option('babh6_job')) babh6_sync_kick();
}

/* ============ Резултат от import (hook-ове от ETL) ============ */

add_action('babh6_import_done', function ($upload_id, $result, $job) {
    if (empty($job['source']) || $job['source'] !== 'auto') return;
    $now = current_time('mysql');
    $msg = sprintf('Импортирани %s реда · нови %s · обновени %s · заличени %s · възстановени %s.',
        number_format_i18n((int)$result['parsed']), number_format_i18n((int)$result['added']),
        number_format_i18n((int)$result['updated']), number_format_i18n((int)$result['removed']),
        number_format_i18n((int)$result['restored']));
    babh6_sync_state_set(array(
        'last_sig'       => isset($job['meta']['sig']) ? $job['meta']['sig'] : '',
        'pending_sig'    => '',
        'last_import_at' => $now,
        'last_result'    => 'ok',
        'last_message'   => $msg,
        'last_files'     => isset($job['names']) ? $job['names'] : array(),
    ));
    babh6_sync_notify('БАБХ регистърът е обновен автоматично',
        "Файлове: " . implode(', ', isset($job['names']) ? $job['names'] : array()) . "\n\n" . $msg . "\n\n" . admin_url('admin.php?page=babh6'));
}, 10, 3);

add_action('babh6_import_failed', function ($upload_id, $message, $job) {
    if (empty($job['source']) || $job['source'] !== 'auto') return;
    babh6_sync_state_set(array('last_result' => 'error', 'last_message' => 'Import-ът се провали: ' . $message, 'pending_sig' => ''));
    babh6_sync_notify('Грешка при автоматично обновяване от БАБХ', "Import-ът на свалените файлове се провали:\n\n" . $message . "\n\n" . admin_url('admin.php?page=babh6'));
}, 10, 3);

function babh6_sync_notify($subject, $body) {
    if ((int)get_option('babh6_sync_notify', 1) !== 1) return;
    $to = apply_filters('babh6_sync_notify_email', get_option('admin_email'));
    if (!$to) return;
    @wp_mail($to, '[' . get_bloginfo('name') . '] ' . $subject, $body);
}

/* ============ Admin действия ============ */

add_action('admin_post_babh6_sync_now', function () {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_sync_now');
    $force = !empty($_POST['babh6_force']);
    $r = babh6_sync_run($force, 'manual');
    wp_safe_redirect(add_query_arg(array('babh6_sync' => $r['status'], 'babh6_msg' => rawurlencode($r['message'])), admin_url('admin.php?page=babh6')));
    exit;
});

add_action('admin_post_babh6_sync_settings', function () {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_sync_settings');
    update_option('babh6_sync_enabled', empty($_POST['babh6_sync_enabled']) ? 0 : 1);
    update_option('babh6_sync_notify', empty($_POST['babh6_sync_notify']) ? 0 : 1);
    $slots = sanitize_text_field(wp_unslash($_POST['babh6_sync_slots'] ?? ''));
    update_option('babh6_sync_slots', $slots !== '' && babh6_sync_slots($slots) ? $slots : BABH6_SYNC_DEFAULT_SLOTS);
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
    $colors  = array('ok' => '#00a32a', 'started' => '#2271b1', 'nochange' => '#787c82', 'busy' => '#dba617', 'error' => '#d63638');
    $res     = isset($state['last_result']) ? $state['last_result'] : '';

    if (isset($_GET['babh6_sync'])) {
        $st  = sanitize_key(wp_unslash($_GET['babh6_sync']));
        $msg = isset($_GET['babh6_msg']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['babh6_msg']))) : '';
        $cls = $st === 'error' ? 'notice-error' : ($st === 'started' ? 'notice-success' : 'notice-info');
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
        ($res ? ' · <b style="color:' . esc_attr(isset($colors[$res]) ? $colors[$res] : '#1d2327') . '">' . esc_html(isset($state['last_message']) ? $state['last_message'] : $res) . '</b>' : '') . '</li>';
    echo '<li>Последен автоматичен импорт: ' . esc_html($fmt(isset($state['last_import_at']) ? $state['last_import_at'] : '')) . '</li>';
    if (!empty($state['portal_files'])) {
        echo '<li>На портала сега: ';
        $parts = array();
        foreach ((array)$state['portal_files'] as $f) {
            $parts[] = '<a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('/^.*(част\s*\d+).*$/iu', '$1', $f['title'])) . '</a>' . ($f['date'] ? ' (' . esc_html(date_i18n('d.m.Y', strtotime($f['date']))) . ')' : '');
        }
        echo implode(', ', $parts) . (!empty($state['portal_updated']) ? ' · актуализация на портала: ' . esc_html(date_i18n('d.m.Y', strtotime($state['portal_updated']))) : '') . '</li>';
    }
    echo '</ul>';

    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        echo '<p style="color:#dba617"><b>Внимание:</b> <code>DISABLE_WP_CRON</code> е включен. Графикът работи само ако системен cron вика <code>wp-cron.php</code> (напр. на всеки 5 мин).</p>';
    } else {
        echo '<p style="color:#787c82;font-size:12px">WP-Cron се задейства от посещения на сайта. При слаб трафик проверката може да закъснее до първото посещение след часа. За точност добави системен cron: <code>*/5 * * * * curl -s ' . esc_html(site_url('wp-cron.php')) . ' &gt;/dev/null</code></p>';
    }

    /* Провери сега */
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 18px;padding:10px 0;border-top:1px solid #f0f0f1;border-bottom:1px solid #f0f0f1">';
    wp_nonce_field('babh6_sync_now');
    echo '<input type="hidden" name="action" value="babh6_sync_now">';
    submit_button('Провери и обнови сега', 'primary', 'submit', false);
    echo ' <label style="margin-left:10px"><input type="checkbox" name="babh6_force" value="1"> Импортирай дори ако файловете не са променени</label>';
    echo '</form>';

    /* Настройки */
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('babh6_sync_settings');
    echo '<input type="hidden" name="action" value="babh6_sync_settings">';
    echo '<p><label><input type="checkbox" name="babh6_sync_enabled" value="1"' . checked($enabled, true, false) . '> <b>Автоматично обновяване по график</b></label></p>';
    echo '<p style="margin-bottom:4px"><b>График</b> — ден и час, разделени със запетая (напр. <code>mon 06:00, fri 18:00</code> или <code>пон 06:00, пет 18:00</code>).</p>';
    echo '<input type="text" name="babh6_sync_slots" value="' . esc_attr($slots) . '" style="width:100%;max-width:300px">';
    echo '<p style="margin:14px 0 4px"><b>Адрес на регистъра в БАБХ</b></p>';
    echo '<input type="url" name="babh6_sync_url" value="' . esc_attr(babh6_sync_url()) . '" style="width:100%;max-width:460px">';
    echo '<p style="margin-top:14px"><label><input type="checkbox" name="babh6_sync_notify" value="1"' . checked((int)get_option('babh6_sync_notify', 1), 1, false) . '> Изпращай имейл до ' . esc_html(get_option('admin_email')) . ' при обновяване или грешка</label></p>';
    echo '<p style="margin-top:14px">';
    submit_button('Запази графика', 'secondary', 'submit', false);
    echo '</p></form></div>';
}

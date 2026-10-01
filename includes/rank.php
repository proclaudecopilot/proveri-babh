<?php
if (!defined('ABSPATH')) exit;

/**
 * Pro: класация на производители и търговци по брой регистрации + „изпреварвания“ (v6.8).
 *
 *   GET /rank?kind=p|t&period=all|12m|year|month&year=ГГГГ&month=ГГГГ-ММ&all=1&limit=20
 *       — топ фирми за периода (брой регистрационни номера по дата на уведомление), промяна и
 *         позиция спрямо предходния период, нови насрещни фирми, кумулативна серия по месеци и
 *         списък „изпреварвания“: кой кога е минал пред кого в общата класация.
 *   GET /rank/move?kind=p|t&norm=…&month=ГГГГ-ММ&vs=…
 *       — подробности за едно изпреварване: насрещните фирми (клиенти / доставчици) с нови
 *         продукти през месеца, кои от тях са нови, и самите продукти.
 *
 * Брои се „един запис = един регистрационен номер“ в последните данни (deleted_at IS NULL).
 * Периодите са по „дата на уведомление“; записи без дата влизат само в „целият период“ и
 * се третират като най-стари при кумулативните сравнения.
 */

add_action('rest_api_init', function () {
    register_rest_route('babh6/v1', '/rank', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_rank', 'permission_callback' => 'babh6_rest_permission_pro',
    ));
    register_rest_route('babh6/v1', '/rank/move', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_rank_move', 'permission_callback' => 'babh6_rest_permission_pro',
    ));
});

/* Отговорите са Pro и лични — без кеш от хостинга/CDN (както /party) */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (preg_match('#^/babh6/v1/rank#', (string)$request->get_route()) && $response instanceof WP_REST_Response) {
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Cookie');
    }
    return $response;
}, 10, 3);

/** Версия на данните за кеша: последният приет импорт + правилата + версията на плъгина. */
function babh6_rank_data_rev() {
    global $wpdb;
    static $rev = null;
    if ($rev === null) {
        $last = (int)$wpdb->get_var("SELECT MAX(id) FROM " . babh6_table('uploads') . " WHERE status = 'done'");
        $rev = $last . '|' . (defined('BABH6_RULES_VERSION') ? BABH6_RULES_VERSION : '') . '|' . BABH6_VERSION . '|' . (string)get_option('babh6_rules_version', '');
    }
    return $rev;
}

/** SQL изрази за фирмата („own“) и насрещната страна („partner“) според вида. Същият модел като /party. */
function babh6_rank_firm_sql($kind) {
    $eff = babh6_eff_trader_sql();
    $eff_norm = $eff['norm']; $eff_name = $eff['name'];
    if ($kind === 'p') {
        return array(
            'norm'  => 'producer_norm', 'name' => 'producer_name',
            'where' => "producer_kind = 'firm' AND producer_norm <> ''",
            'pnorm' => $eff_norm, 'pname' => $eff_name,
            'pok'   => "($eff_norm <> '' AND $eff_norm <> producer_norm)",
        );
    }
    return array(
        'norm'  => $eff_norm, 'name' => $eff_name,
        'where' => "$eff_norm <> ''",
        'pnorm' => 'producer_norm', 'pname' => 'producer_name',
        'pok'   => "(producer_kind = 'firm' AND producer_norm <> '' AND producer_norm <> $eff_norm)",
    );
}

/** Списък от месеци ГГГГ-ММ от $from (вкл.) до $to (изкл.). */
function babh6_rank_month_keys($from, $to) {
    $out = array();
    $d = new DateTime(substr($from, 0, 7) . '-01', new DateTimeZone('UTC'));
    $end = new DateTime(substr($to, 0, 7) . '-01', new DateTimeZone('UTC'));
    $guard = 0;
    while ($d < $end && $guard++ < 400) { $out[] = $d->format('Y-m'); $d->modify('+1 month'); }
    return $out;
}

/**
 * Период на класацията.
 * @return array period, from, to (изкл.), prev_from, prev_to, months (за серията и изпреварванията), year, month
 */
function babh6_rank_range($req) {
    $period = (string)$req->get_param('period');
    if (!in_array($period, array('all', '12m', 'year', 'month'), true)) $period = '12m';
    $tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);
    $cur_y = (int)$now->format('Y'); $cur_m = $now->format('Y-m');
    $rr = babh6_recent_range();
    $out = array('period' => $period, 'from' => null, 'to' => null, 'prev_from' => null, 'prev_to' => null, 'months' => array(), 'year' => null, 'month' => null, 'now' => $cur_m);
    if ($period === '12m') {
        $out['from'] = $rr['from']; $out['to'] = $rr['to'];
        $pf = new DateTime($rr['from'], $tz); $pf->modify('-12 months');
        $out['prev_from'] = $pf->format('Y-m-d'); $out['prev_to'] = $rr['from'];
        $out['months'] = $rr['keys'];
    } elseif ($period === 'year') {
        $y = (int)$req->get_param('year');
        if ($y < 2000 || $y > $cur_y) $y = $cur_y;
        $out['year'] = $y;
        $out['from'] = $y . '-01-01'; $out['to'] = ($y + 1) . '-01-01';
        $out['prev_from'] = ($y - 1) . '-01-01'; $out['prev_to'] = $y . '-01-01';
        $out['months'] = babh6_rank_month_keys($out['from'], $y === $cur_y ? $rr['to'] : $out['to']);
    } elseif ($period === 'month') {
        $m = (string)$req->get_param('month');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12 || (int)$mm[1] < 2000 || $m > $cur_m) $m = $cur_m;
        $out['month'] = $m;
        $d = new DateTime($m . '-01', $tz);
        $out['from'] = $d->format('Y-m-d');
        $d2 = clone $d; $d2->modify('+1 month'); $out['to'] = $d2->format('Y-m-d');
        $d0 = clone $d; $d0->modify('-1 month'); $out['prev_from'] = $d0->format('Y-m-d'); $out['prev_to'] = $out['from'];
        $out['months'] = array($m);
    } else {
        /* целият период: промяната е спрямо състоянието преди 12 месеца; изпреварванията — за последните 24 месеца */
        $out['prev_to'] = $rr['from'];
        $d = new DateTime($rr['from'], $tz); $d->modify('-12 months');
        $out['months'] = babh6_rank_month_keys($d->format('Y-m-d'), $rr['to']);
    }
    return $out;
}

/**
 * Брой записи по фирма за интервал. $from/$to = null → без ограничение; само $to → „преди датата“
 * (записите без дата се броят като най-стари).
 * @return array [norm => ['norm','name','bg','c','partners']] подредено по c DESC, name ASC
 */
function babh6_rank_counts($kind, $all, $from, $to) {
    global $wpdb;
    $t = babh6_table('products'); $pt = babh6_table('parties');
    $f = babh6_rank_firm_sql($kind);
    $where = "deleted_at IS NULL AND {$f['where']}"; $args = array();
    if ($from !== null && $to !== null) { $where .= ' AND notif_date >= %s AND notif_date < %s'; $args[] = $from; $args[] = $to; }
    elseif ($to !== null) { $where .= ' AND (notif_date IS NULL OR notif_date < %s)'; $args[] = $to; }
    $args[] = $kind;
    $sql = "SELECT f.norm, pt.name, pt.is_bg, f.c, f.partners
            FROM (SELECT {$f['norm']} AS norm, COUNT(*) AS c, COUNT(DISTINCT CASE WHEN {$f['pok']} THEN {$f['pnorm']} END) AS partners
                  FROM $t WHERE $where GROUP BY {$f['norm']}) f
            JOIN $pt pt ON pt.kind = %s AND pt.norm = f.norm" . ($all ? '' : ' WHERE pt.is_bg = 1') . "
            ORDER BY f.c DESC, pt.name ASC";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
    $out = array();
    foreach ((array)$rows as $r) {
        $out[$r->norm] = array('norm' => $r->norm, 'name' => $r->name, 'bg' => (int)$r->is_bg, 'c' => (int)$r->c, 'partners' => (int)$r->partners);
    }
    return $out;
}

/* ============ GET /rank ============ */
function babh6_rest_rank($req) {
    global $wpdb;
    $t    = babh6_table('products');
    $kind = babh6_party_kind($req);
    $all  = (bool)$req->get_param('all');
    $limit = (int)$req->get_param('limit');
    if ($limit < 3 || $limit > 100) $limit = 20;
    $rg = babh6_rank_range($req);

    $ck = 'babh6_rank_' . md5(wp_json_encode(array($kind, $all ? 1 : 0, $limit, $rg['from'], $rg['to'], $rg['months'], babh6_rank_data_rev())));
    $cached = get_transient($ck);
    if (is_array($cached)) return rest_ensure_response($cached);

    $cur  = babh6_rank_counts($kind, $all, $rg['from'], $rg['to']);
    $prev = babh6_rank_counts($kind, $all, $rg['prev_from'], $rg['prev_to']);
    $prev_rank = array(); $i = 0;
    foreach ($prev as $n => $r) $prev_rank[$n] = ++$i;

    $total = 0; foreach ($cur as $r) $total += $r['c'];
    $prev_total = 0; foreach ($prev as $r) $prev_total += $r['c'];

    $items = array(); $rank = 0; $tops = array();
    foreach ($cur as $n => $r) {
        $rank++;
        if ($rank > $limit) break;
        $tops[] = $n;
        $pc = isset($prev[$n]) ? $prev[$n]['c'] : 0;
        $pr = isset($prev_rank[$n]) ? $prev_rank[$n] : null;
        $items[] = array(
            'rank' => $rank, 'norm' => $n, 'name' => $r['name'], 'bg' => $r['bg'],
            'count' => $r['c'], 'partners' => $r['partners'],
            'prev_count' => $pc, 'delta' => $r['c'] - $pc,
            'prev_rank' => $pr, 'move' => $pr === null ? null : $pr - $rank, 'is_new' => $pc === 0 ? 1 : 0,
            'share' => $total ? round(100 * $r['c'] / $total, 1) : 0,
            'new_partners' => 0,
        );
    }

    /* Нови насрещни фирми за периода (за „целият период“ — за последните 12 месеца) */
    $np_from = $rg['from'] !== null ? $rg['from'] : $rg['prev_to'];
    $np_to   = $rg['to'] !== null ? $rg['to'] : null;
    if ($tops && $np_from) {
        $f = babh6_rank_firm_sql($kind);
        $in = implode(',', array_fill(0, count($tops), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$f['norm']} AS norm, {$f['pnorm']} AS pn, MIN(notif_date) AS first
             FROM $t WHERE deleted_at IS NULL AND {$f['where']} AND {$f['pok']} AND {$f['norm']} IN ($in)
             GROUP BY {$f['norm']}, {$f['pnorm']}", $tops));
        $np = array();
        foreach ((array)$rows as $r) {
            if ($r->first === null || $r->first < $np_from || ($np_to !== null && $r->first >= $np_to)) continue;
            $np[$r->norm] = isset($np[$r->norm]) ? $np[$r->norm] + 1 : 1;
        }
        foreach ($items as &$it) { $it['new_partners'] = isset($np[$it['norm']]) ? $np[$it['norm']] : 0; }
        unset($it);
    }

    /* Кумулативна серия по месеци + изпреварвания (в общата класация, сред първите 40 по общ брой) */
    $race = babh6_rank_race($kind, $all, $rg);

    $out = array(
        'kind' => $kind, 'all' => $all ? 1 : 0, 'limit' => $limit,
        'range' => array('period' => $rg['period'], 'from' => $rg['from'], 'to' => $rg['to'], 'prev_from' => $rg['prev_from'], 'prev_to' => $rg['prev_to'], 'year' => $rg['year'], 'month' => $rg['month'], 'now' => $rg['now']),
        'total' => $total, 'prev_total' => $prev_total, 'firms' => count($cur), 'prev_firms' => count($prev),
        'items' => $items,
        'months' => $rg['months'], 'series' => $race['series'], 'moves' => $race['moves'], 'moves_scope' => $race['scope'],
        'years' => array_map('intval', (array)$wpdb->get_col("SELECT DISTINCT YEAR(notif_date) FROM $t WHERE notif_date IS NOT NULL AND YEAR(notif_date) >= 2000 ORDER BY 1 DESC")),
        'min_month' => (string)$wpdb->get_var("SELECT DATE_FORMAT(MIN(notif_date), '%Y-%m') FROM $t WHERE notif_date IS NOT NULL AND YEAR(notif_date) >= 2000"),
    );
    set_transient($ck, $out, 12 * HOUR_IN_SECONDS);
    return rest_ensure_response($out);
}

/**
 * Кумулативна „надпревара“ по месеци за най-големите фирми и изпреварванията между тях.
 * Изпреварване = през месец M фирма A има строго повече записи от B, а в края на предходния
 * месец е имала толкова или по-малко. Броят се всички записи в последните данни (без дата → най-стари).
 */
function babh6_rank_race($kind, $all, $rg) {
    global $wpdb;
    $t = babh6_table('products'); $pt = babh6_table('parties');
    $f = babh6_rank_firm_sql($kind);
    $months = $rg['months'];
    $empty = array('series' => array(), 'moves' => array(), 'scope' => 0);
    if (!$months) return $empty;
    $w_from = $months[0] . '-01';
    $d = new DateTime($months[count($months) - 1] . '-01', new DateTimeZone('UTC')); $d->modify('+1 month');
    $w_to = $d->format('Y-m-d');

    /* Най-големите K фирми по общ брой (в обхвата „български / всички“) */
    $K = 40;
    $tops = $wpdb->get_results($wpdb->prepare(
        "SELECT norm, name FROM $pt WHERE kind = %s" . ($all ? '' : ' AND is_bg = 1') . " ORDER BY product_count DESC, name ASC LIMIT %d", $kind, $K));
    if (!$tops) return $empty;
    $names = array(); $norms = array();
    foreach ($tops as $r) { $names[$r->norm] = $r->name; $norms[] = $r->norm; }
    $in = implode(',', array_fill(0, count($norms), '%s'));

    $base = array(); foreach ($norms as $n) $base[$n] = 0;
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT {$f['norm']} AS norm, COUNT(*) AS c FROM $t
         WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} IN ($in) AND (notif_date IS NULL OR notif_date < %s)
         GROUP BY {$f['norm']}", array_merge($norms, array($w_from)))) as $r) { $base[$r->norm] = (int)$r->c; }
    $monthly = array();
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT {$f['norm']} AS norm, DATE_FORMAT(notif_date, '%%Y-%%m') AS m, COUNT(*) AS c FROM $t
         WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} IN ($in) AND notif_date >= %s AND notif_date < %s
         GROUP BY {$f['norm']}, m", array_merge($norms, array($w_from, $w_to)))) as $r) { $monthly[$r->norm][$r->m] = (int)$r->c; }

    $cum = $base; $series = array(); foreach ($norms as $n) $series[$n] = array();
    $moves = array();
    foreach ($months as $m) {
        $prev = $cum;
        foreach ($norms as $n) { $cum[$n] += isset($monthly[$n][$m]) ? $monthly[$n][$m] : 0; $series[$n][] = $cum[$n]; }
        foreach ($norms as $a) {
            $gained = $cum[$a] - $prev[$a];
            if ($gained <= 0) continue;
            foreach ($norms as $b) {
                if ($a === $b) continue;
                if ($prev[$a] <= $prev[$b] && $cum[$a] > $cum[$b]) {
                    $moves[] = array(
                        'month' => $m, 'a' => $a, 'a_name' => $names[$a], 'b' => $b, 'b_name' => $names[$b],
                        'a_before' => $prev[$a], 'a_after' => $cum[$a], 'b_before' => $prev[$b], 'b_after' => $cum[$b],
                        'a_new' => $gained, 'b_new' => $cum[$b] - $prev[$b],
                    );
                }
            }
        }
    }
    /* Най-новите първи; при равен месец — по-големият (по общ брой след месеца) първи. Ограничение: 60. */
    usort($moves, function ($x, $y) {
        if ($x['month'] !== $y['month']) return strcmp($y['month'], $x['month']);
        if ($x['a_after'] !== $y['a_after']) return $y['a_after'] - $x['a_after'];
        return $y['b_after'] - $x['b_after'];
    });
    $moves = array_slice($moves, 0, 60);

    /* Насрещни фирми за всяко изпреварване: колко с нови продукти през месеца и колко от тях са нови */
    if ($moves) {
        $movers = array_values(array_unique(array_map(function ($mv) { return $mv['a']; }, $moves)));
        $in2 = implode(',', array_fill(0, count($movers), '%s'));
        $first = array();
        foreach ((array)$wpdb->get_results($wpdb->prepare(
            "SELECT {$f['norm']} AS norm, {$f['pnorm']} AS pn, MIN(notif_date) AS first FROM $t
             WHERE deleted_at IS NULL AND {$f['where']} AND {$f['pok']} AND {$f['norm']} IN ($in2)
             GROUP BY {$f['norm']}, {$f['pnorm']}", $movers)) as $r) { $first[$r->norm][$r->pn] = (string)$r->first; }
        $pm = array();
        foreach ((array)$wpdb->get_results($wpdb->prepare(
            "SELECT {$f['norm']} AS norm, {$f['pnorm']} AS pn, DATE_FORMAT(notif_date, '%%Y-%%m') AS m, COUNT(*) AS c FROM $t
             WHERE deleted_at IS NULL AND {$f['where']} AND {$f['pok']} AND {$f['norm']} IN ($in2) AND notif_date >= %s AND notif_date < %s
             GROUP BY {$f['norm']}, {$f['pnorm']}, m", array_merge($movers, array($w_from, $w_to)))) as $r) { $pm[$r->norm][$r->m][$r->pn] = (int)$r->c; }
        foreach ($moves as &$mv) {
            $ps = isset($pm[$mv['a']][$mv['month']]) ? $pm[$mv['a']][$mv['month']] : array();
            $mv['partners'] = count($ps);
            $np = 0; $withp = 0;
            foreach ($ps as $pn => $c) {
                $withp += $c;
                $fd = isset($first[$mv['a']][$pn]) ? $first[$mv['a']][$pn] : '';
                if ($fd !== '' && substr($fd, 0, 7) === $mv['month']) $np++;
            }
            $mv['new_partners'] = $np;
            $mv['own'] = max(0, $mv['a_new'] - $withp);
        }
        unset($mv);
    }

    /* Серията — само за първите 10 по общ брой, за графиката */
    $top_series = array();
    $i = 0;
    foreach ($norms as $n) { if ($i++ >= 10) break; $top_series[] = array('norm' => $n, 'name' => $names[$n], 'v' => $series[$n]); }
    return array('series' => $top_series, 'moves' => $moves, 'scope' => count($norms));
}

/* ============ GET /rank/move — подробности за едно изпреварване ============ */
function babh6_rest_rank_move($req) {
    global $wpdb;
    $t = babh6_table('products'); $pt = babh6_table('parties');
    $kind = babh6_party_kind($req);
    $f = babh6_rank_firm_sql($kind);
    $norm = mb_substr((string)$req->get_param('norm'), 0, 191, 'UTF-8');
    $vs   = mb_substr((string)$req->get_param('vs'), 0, 191, 'UTF-8');
    $m    = (string)$req->get_param('month');
    if ($norm === '' || !preg_match('/^(\d{4})-(\d{2})$/', $m, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) {
        return new WP_Error('babh6_bad', 'Невалидни параметри.', array('status' => 400));
    }
    $from = $m . '-01';
    $d = new DateTime($from, new DateTimeZone('UTC')); $d->modify('+1 month'); $to = $d->format('Y-m-d');

    $firm = function ($n) use ($wpdb, $pt, $t, $f, $kind, $from, $to) {
        if ($n === '') return null;
        $p = $wpdb->get_row($wpdb->prepare("SELECT norm, name, is_bg, product_count FROM $pt WHERE kind = %s AND norm = %s", $kind, $n));
        if (!$p) return null;
        $before = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} = %s AND (notif_date IS NULL OR notif_date < %s)", $n, $from));
        $new = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} = %s AND notif_date >= %s AND notif_date < %s", $n, $from, $to));
        return array('norm' => $p->norm, 'name' => $p->name, 'bg' => (int)$p->is_bg, 'total' => (int)$p->product_count, 'before' => $before, 'after' => $before + $new, 'new' => $new);
    };
    $a = $firm($norm);
    if (!$a) return new WP_Error('babh6_notfound', 'Фирмата не е намерена в наличните данни.', array('status' => 404));
    $b = $firm($vs);

    /* Насрещни фирми с продукти през месеца + кога за първи път се появяват с тази фирма */
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.pn, SUM(p.c) AS c, SUBSTRING_INDEX(MAX(p.pname), ',', 1) AS pname, MAX(fx.first) AS first, MAX(fx.total) AS total
         FROM (SELECT {$f['pnorm']} AS pn, {$f['pname']} AS pname, COUNT(*) AS c FROM $t
               WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} = %s AND {$f['pok']} AND notif_date >= %s AND notif_date < %s
               GROUP BY {$f['pnorm']}, {$f['pname']}) p
         JOIN (SELECT {$f['pnorm']} AS pn, MIN(notif_date) AS first, COUNT(*) AS total FROM $t
               WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} = %s AND {$f['pok']} GROUP BY {$f['pnorm']}) fx ON fx.pn = p.pn
         GROUP BY p.pn ORDER BY c DESC, pname ASC LIMIT 200", $norm, $from, $to, $norm));
    $partners = array(); $agg = array();
    foreach ((array)$rows as $r) {
        if (!isset($agg[$r->pn])) $agg[$r->pn] = array('norm' => $r->pn, 'name' => '', 'count' => 0, 'first' => $r->first, 'total' => (int)$r->total, 'is_new' => ($r->first !== null && substr((string)$r->first, 0, 7) === $m) ? 1 : 0);
        $agg[$r->pn]['count'] += (int)$r->c;
        $dn = babh6_display_firm($r->pname);
        if ($agg[$r->pn]['name'] === '') $agg[$r->pn]['name'] = $dn !== '' ? $dn : trim((string)$r->pname);
    }
    foreach ($agg as $p) $partners[] = $p;
    usort($partners, function ($x, $y) { if ($x['is_new'] !== $y['is_new']) return $y['is_new'] - $x['is_new']; if ($x['count'] !== $y['count']) return $y['count'] - $x['count']; return strcmp($x['name'], $y['name']); });

    /* Продуктите през месеца (карти), с ключ на насрещната фирма */
    $prod = $wpdb->get_results($wpdb->prepare(
        "SELECT id, reg, rtype, name, producer_name, producer_kind, producer_norm, trader_name, trader_kind, trader_norm, trader_inf_norm, notif_date, deletion, category, flag_count, deleted_at,
                CASE WHEN {$f['pok']} THEN {$f['pnorm']} ELSE '' END AS en
         FROM $t WHERE deleted_at IS NULL AND {$f['where']} AND {$f['norm']} = %s AND notif_date >= %s AND notif_date < %s
         ORDER BY notif_date DESC, id DESC LIMIT 200", $norm, $from, $to));
    $cards = array(); $own = 0;
    foreach ((array)$prod as $r) { $c = babh6_row_to_card($r); $c['en'] = (string)$r->en; if ($c['en'] === '') $own++; $cards[] = $c; }

    return rest_ensure_response(array(
        'kind' => $kind, 'month' => $m, 'from' => $from, 'to' => $to,
        'a' => $a, 'b' => $b,
        'partners' => $partners, 'new_partners' => count(array_filter($partners, function ($p) { return $p['is_new']; })),
        'own' => $a['new'] - array_sum(array_map(function ($p) { return $p['count']; }, $partners)),
        'products' => $cards, 'products_total' => $a['new'],
    ));
}

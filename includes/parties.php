<?php
if (!defined('ABSPATH')) exit;

/**
 * Pro: профили на производители и търговци.
 *
 *   GET /parties?kind=p|t&q=&sort=&page=&per=&all=1  — списък фирми с брой продукти, партньори, флагове;
 *                                                       по подразбиране само с българска регистрация (all=1 показва и чуждестранните)
 *   GET /party?kind=p|t&norm=…                       — профил: партньори (клиенти / доставчици) с брой продукти при всеки,
 *                                                       собствени продукти, категории, регистрации по години
 *
 * Достъп (Pro): логнат администратор ИЛИ сайтът е с парола и посетителят я е въвел.
 * Без парола на сайта (напълно публичен) Pro частта остава „Скоро" + waitlist.
 */

function babh6_pro_ok() {
    if (current_user_can('manage_options')) return true;
    return babh6_password() !== '' && babh6_gate_ok();
}

function babh6_rest_permission_pro() {
    if (!babh6_gate_ok()) return new WP_Error('babh6_locked', 'Достъпът изисква парола.', array('status' => 401));
    if (!babh6_pro_ok()) return new WP_Error('babh6_pro', 'Pro функция.', array('status' => 403));
    return true;
}

add_action('rest_api_init', function () {
    register_rest_route('babh6/v1', '/parties', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_parties', 'permission_callback' => 'babh6_rest_permission_pro',
    ));
    register_rest_route('babh6/v1', '/party', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_party', 'permission_callback' => 'babh6_rest_permission_pro',
    ));
});

function babh6_party_kind($req) {
    $k = (string)$req->get_param('kind');
    return $k === 't' ? 't' : 'p';
}

/* ============ GET /parties ============ */
function babh6_rest_parties($req) {
    global $wpdb;
    $pt   = babh6_table('parties');
    $kind = babh6_party_kind($req);

    $where = array('kind = %s');
    $args  = array($kind);
    /* По подразбиране само фирми с българска регистрация и седалище; all=1 показва всички */
    $all = (bool)$req->get_param('all') && !$req->get_param('bg');
    if (!$all) $where[] = 'is_bg = 1';
    $q = trim((string)$req->get_param('q'));
    if ($q !== '' && mb_strlen($q, 'UTF-8') >= 2) {
        $ors = array();
        foreach (babh6_query_variants($q) as $v) {
            $ors[] = 'name LIKE %s'; $args[] = '%' . $wpdb->esc_like($v) . '%';
            $ors[] = 'norm LIKE %s'; $args[] = '%' . $wpdb->esc_like(babh6_norm_firm($v)) . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    $wsql = implode(' AND ', $where);

    $sorts = array(
        'products' => 'product_count DESC, name ASC',
        'partners' => 'partner_count DESC, product_count DESC',
        'flagged'  => 'flagged_count DESC, product_count DESC',
        'name'     => 'name ASC',
        'newest'   => 'last_year DESC, product_count DESC',
    );
    $sort  = (string)$req->get_param('sort');
    $order = isset($sorts[$sort]) ? $sorts[$sort] : $sorts['products'];

    $page = max(1, (int)$req->get_param('page'));
    $per  = (int)$req->get_param('per');
    if ($per < 1 || $per > 100) $per = 40;
    $offset = ($page - 1) * $per;

    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pt WHERE $wsql", $args));
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT norm, name, is_bg, product_count, flagged_count, partner_count, inferred_count, first_year, last_year
         FROM $pt WHERE $wsql ORDER BY $order LIMIT %d OFFSET %d",
        array_merge($args, array($per, $offset))
    ));
    $items = array();
    foreach ((array)$rows as $r) {
        $items[] = array(
            'norm' => $r->norm, 'name' => $r->name, 'bg' => (int)$r->is_bg,
            'products' => (int)$r->product_count, 'flagged' => (int)$r->flagged_count, 'partners' => (int)$r->partner_count,
            'inferred' => (int)$r->inferred_count,
            'y1' => $r->first_year ? (int)$r->first_year : null, 'y2' => $r->last_year ? (int)$r->last_year : null,
        );
    }
    $sums = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, SUM(is_bg) AS bg FROM $pt WHERE kind = %s", $kind));
    return rest_ensure_response(array(
        'kind' => $kind, 'total' => $total, 'page' => $page, 'per' => $per, 'items' => $items,
        'all' => (int)$sums->n, 'all_bg' => (int)$sums->bg, 'bg_only' => $all ? 0 : 1,
    ));
}

/* ============ GET /party/{kind}/{norm} ============ */
function babh6_rest_party($req) {
    global $wpdb;
    $t    = babh6_table('products');
    $pt   = babh6_table('parties');
    $kind = babh6_party_kind($req);
    $norm = mb_substr((string)$req->get_param('norm'), 0, 191, 'UTF-8');

    $party = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE kind = %s AND norm = %s", $kind, $norm));
    if (!$party) return new WP_Error('babh6_notfound', 'Фирмата не е намерена в наличните данни.', array('status' => 404));

    /* own = колоната на фирмата; other = насрещната страна.
       Търговецът е „ефективен“ (babh6_eff_trader_sql): предположен по името → посочен → самият производител. */
    $eff = babh6_eff_trader_sql();
    $eff_norm = $eff['norm'];
    $eff_name = $eff['name'];
    if ($kind === 'p') {
        $own_cond   = 'producer_norm = %s';
        $other_norm = $eff_norm;
        $other_name = $eff_name;
        $other_ok   = "($eff_norm <> '' AND $eff_norm <> producer_norm)";
        $none_cond  = "($eff_norm = '' OR $eff_norm = producer_norm)";
    } else {
        $own_cond   = "$eff_norm = %s";
        $other_norm = 'producer_norm';
        $other_name = 'producer_name';
        $other_ok   = "(producer_kind = 'firm' AND producer_norm <> '' AND producer_norm <> $eff_norm)";
        $none_cond  = "(producer_kind <> 'firm' OR producer_norm = '' OR producer_norm = $eff_norm)";
    }

    $partners = $wpdb->get_results($wpdb->prepare(
        "SELECT $other_norm AS norm, SUBSTRING_INDEX(MAX($other_name), ',', 1) AS name,
                COUNT(*) AS c, SUM(CASE WHEN flag_count > 0 THEN 1 ELSE 0 END) AS f,
                SUM(CASE WHEN trader_inf_norm <> '' THEN 1 ELSE 0 END) AS inf,
                MIN(notif_date) AS d1, MAX(notif_date) AS d2, MAX(ryear) AS y2
         FROM $t
         WHERE deleted_at IS NULL AND $own_cond AND $other_ok AND $other_norm <> ''
         GROUP BY $other_norm ORDER BY c DESC, name ASC LIMIT 500",
        $norm
    ));
    $total = (int)$party->product_count;
    /* Пълен брой контрагенти — списъкът е ограничен до 500 (CO-05) */
    $partners_total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT $other_norm) FROM $t WHERE deleted_at IS NULL AND $own_cond AND $other_ok AND $other_norm <> ''", $norm));
    $partners_src = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT $other_norm) FROM $t WHERE deleted_at IS NULL AND $own_cond AND $other_ok AND $other_norm <> '' AND trader_inf_norm = ''", $norm));
    $plist = array();
    foreach ((array)$partners as $p) {
        $dn = babh6_display_firm($p->name);
        $plist[] = array(
            'norm' => $p->norm, 'name' => $dn !== '' ? $dn : trim((string)$p->name), 'count' => (int)$p->c, 'flagged' => (int)$p->f,
            'inferred' => (int)$p->inf,
            'first' => $p->d1, 'last' => $p->d2, 'y2' => $p->y2 ? (int)$p->y2 : null,
            'share' => $total ? round(100 * (int)$p->c / $total, 1) : 0,
        );
    }

    /* Без друга насрещна фирма: собствена марка на производителя / собствено производство на търговеца */
    $own_row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS c, SUM(CASE WHEN flag_count > 0 THEN 1 ELSE 0 END) AS f, MIN(notif_date) AS d1, MAX(notif_date) AS d2, MAX(ryear) AS y2
         FROM $t WHERE deleted_at IS NULL AND $own_cond AND $none_cond", $norm));
    $own = $own_row ? (int)$own_row->c : 0;
    /* v6.8.1: самата фирма е ред в списъка с насрещните фирми („собствена марка“), подреден по брой —
       регистърът не вписва търговец при собствена марка и иначе тези продукти „изчезват“ от списъка */
    if ($own > 0) {
        $plist[] = array(
            'norm' => $party->norm, 'name' => $party->name, 'count' => $own, 'flagged' => (int)$own_row->f, 'inferred' => 0,
            'first' => $own_row->d1, 'last' => $own_row->d2, 'y2' => $own_row->y2 ? (int)$own_row->y2 : null,
            'share' => $total ? round(100 * $own / $total, 1) : 0, 'self' => 1,
        );
        usort($plist, function ($a, $b) { if ($a['count'] !== $b['count']) return $b['count'] - $a['count']; return strcmp($a['name'], $b['name']); });
    }
    $inferred = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND $own_cond AND trader_inf_norm <> ''", $norm));

    $labels = array('other' => 'Други');
    foreach (babh6_categories() as $c) $labels[$c[0]] = $c[1];
    $cats = array();
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT category, COUNT(*) AS c FROM $t WHERE deleted_at IS NULL AND $own_cond GROUP BY category ORDER BY c DESC LIMIT 8", $norm)) as $r) {
        $code = $r->category ? $r->category : 'other';
        $cats[] = array('code' => $code, 'label' => isset($labels[$code]) ? $labels[$code] : $code, 'count' => (int)$r->c);
    }
    $years = array();
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT ryear AS y, COUNT(*) AS c FROM $t WHERE deleted_at IS NULL AND $own_cond AND ryear IS NOT NULL GROUP BY ryear ORDER BY ryear ASC", $norm)) as $r) {
        $years[] = array('y' => (int)$r->y, 'c' => (int)$r->c);
    }
    $deleted = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE deleted_at IS NOT NULL AND $own_cond", $norm));
    $name_col  = $kind === 'p' ? 'producer_name' : $eff_name;
    $full_name = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT $name_col FROM $t WHERE $own_cond ORDER BY CHAR_LENGTH($name_col) DESC LIMIT 1", $norm));

    /* Марки по първата дума в наименованието (автоматично) — кои марки прави производителят /
       продава търговецът, и с коя насрещна фирма е най-често всяка марка. */
    $brands = array();
    $cp_norm = $kind === 'p' ? $eff_norm : 'producer_norm';
    $cp_name = $kind === 'p' ? $eff_name : 'producer_name';
    $brows = $wpdb->get_results($wpdb->prepare(
        "SELECT name, $cp_norm AS cn, SUBSTRING_INDEX($cp_name, ',', 1) AS cname, ($other_ok) AS ok
         FROM $t WHERE deleted_at IS NULL AND $own_cond", $norm));
    $counts = array();
    foreach ((array)$brows as $r) {
        $tok = babh6_brand_token($r->name);
        if ($tok === '') continue;
        $key = babh6_translit_bg2lat($tok);
        if (!isset($counts[$key])) $counts[$key] = array('n' => 0, 'label' => $tok, 'cp' => array());
        $counts[$key]['n']++;
        if (mb_strlen($tok, 'UTF-8') > mb_strlen($counts[$key]['label'], 'UTF-8')) $counts[$key]['label'] = $tok;
        if ((int)$r->ok && $r->cn !== '') {
            if (!isset($counts[$key]['cp'][$r->cn])) { $dn = babh6_display_firm($r->cname); $counts[$key]['cp'][$r->cn] = array('n' => 0, 'name' => $dn !== '' ? $dn : trim((string)$r->cname)); }
            $counts[$key]['cp'][$r->cn]['n']++;
        }
    }
    uasort($counts, function ($a, $b) { return $b['n'] - $a['n']; });
    $i = 0;
    foreach ($counts as $key => $c) {
        if ($i >= 40) break;
        $i++;
        $top = null;
        foreach ($c['cp'] as $cn => $cc) { if ($top === null || $cc['n'] > $top['n']) $top = array('norm' => $cn, 'name' => $cc['name'], 'n' => $cc['n']); }
        $brands[] = array('token' => $c['label'], 'key' => $key, 'count' => (int)$c['n'],
            'match' => $top ? array('kind' => $kind === 'p' ? 't' : 'p', 'norm' => $top['norm'], 'name' => $top['name'], 'count' => (int)$top['n']) : null);
    }

    /* v6.8: преглед на профила — позиция в класацията, последните 24 месеца, най-новите продукти */
    $rank_all = 1 + (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pt WHERE kind = %s AND product_count > %d", $kind, (int)$party->product_count));
    $rank_bg  = $party->is_bg ? 1 + (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pt WHERE kind = %s AND is_bg = 1 AND product_count > %d", $kind, (int)$party->product_count)) : null;
    $rr = babh6_recent_range();
    $m0 = new DateTime($rr['from'], new DateTimeZone('UTC')); $m0->modify('-12 months');
    $mkeys = array(); $mk = clone $m0;
    for ($i = 0; $i < 24; $i++) { $mkeys[] = $mk->format('Y-m'); $mk->modify('+1 month'); }
    $mmap = array();
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(notif_date, '%%Y-%%m') AS m, COUNT(*) AS c FROM $t
         WHERE deleted_at IS NULL AND $own_cond AND notif_date >= %s AND notif_date < %s GROUP BY m", $norm, $m0->format('Y-m-d'), $rr['to'])) as $r) { $mmap[$r->m] = (int)$r->c; }
    $monthly = array(); $recent12 = 0; $prev12 = 0;
    foreach ($mkeys as $i => $k) { $c = isset($mmap[$k]) ? $mmap[$k] : 0; $monthly[] = array('m' => $k, 'c' => $c); if ($i >= 12) $recent12 += $c; else $prev12 += $c; }
    $latest = array();
    foreach ((array)$wpdb->get_results($wpdb->prepare(
        "SELECT id, reg, rtype, name, producer_name, producer_kind, producer_norm, trader_name, trader_kind, trader_norm, trader_inf_norm, notif_date, deletion, category, flag_count, deleted_at
         FROM $t WHERE deleted_at IS NULL AND $own_cond ORDER BY (notif_date IS NULL) ASC, notif_date DESC, ryear DESC, id DESC LIMIT 6", $norm)) as $r) { $latest[] = babh6_row_to_card($r); }

    return rest_ensure_response(array(
        'kind' => $kind, 'norm' => $party->norm, 'name' => $party->name, 'full' => $full_name, 'bg' => (int)$party->is_bg,
        'products' => $total, 'flagged' => (int)$party->flagged_count, 'deleted' => $deleted,
        'y1' => $party->first_year ? (int)$party->first_year : null, 'y2' => $party->last_year ? (int)$party->last_year : null,
        'own' => $own, 'inferred' => $inferred, 'partners' => $plist, 'partners_total' => $partners_total, 'partners_src' => $partners_src,
        'cats' => $cats, 'years' => $years,
        'brands' => $brands, 'brands_total' => count($counts),
        'rank_all' => $rank_all, 'rank_bg' => $rank_bg,
        'monthly' => $monthly, 'recent12' => $recent12, 'prev12' => $prev12, 'latest' => $latest,
    ));
}

/** Първата дума на наименование/фирма като „марка"; '' ако е обща дума, кратка или число. */
function babh6_brand_token($name) {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(array('витамин','vitamin','vitamins','витамини','магнезий','magnesium','омега','omega','протеин','protein','колаген','collagen',
            'цинк','zinc','калций','calcium','желязо','iron','селен','коензим','coenzyme','хранителна','добавка','капсули','таблетки','capsules','tablets',
            'complex','комплекс','био','bio','натурален','natural','organic','органик','super','супер','пробиотик','probiotic','мултивитамин','multivitamin',
            'екстракт','extract','масло','oil','чай','tea','прах','powder','сироп','syrup','капки','drops','детски','kids','kid','baby','бебе','men','women',
            'формула','formula','пакет','набор','set','плюс','plus','форте','forte','актив','active','ultra','ултра','max','макс','pro','про','daily','дейли',
            'immune','имун','имуно','имунитет','sport','спорт','sports','whey','bcaa','creatine','креатин','d3','k2','b12','c','d','e','the','на','за','от','и'));
    }
    $s = mb_strtolower(trim((string)$name), 'UTF-8');
    $s = preg_replace('/^[\s„“"\'«»(\[\-–—]+/u', '', $s);
    if (!preg_match('/^([\p{L}\p{N}][\p{L}\p{N}&+\-]*)/u', $s, $m)) return '';
    $tok = rtrim($m[1], '-+&');
    if (mb_strlen($tok, 'UTF-8') < 3 || preg_match('/^[\p{N}.,]+$/u', $tok)) return '';
    if (isset($stop[$tok])) return '';
    return $tok;
}

/** LIKE шаблони за име, започващо с марката (с кавички отпред и кирилица/латиница). */
function babh6_brand_like_variants($tok) {
    global $wpdb;
    $out = array();
    foreach (babh6_query_variants($tok) as $v) {
        $e = $wpdb->esc_like($v);
        foreach (array('', '„', '"', '«', "'") as $q) { $out[] = $q . $e . '%'; }
    }
    return array_values(array_unique($out));
}

/** Фирма, чието име започва с марката (предпочита търговец). */
function babh6_brand_match_firm($key, $variants, $exclude_norm) {
    global $wpdb;
    $pt = babh6_table('parties');
    $conds = array(); $args = array();
    $all = $variants; $all[] = $key;
    foreach (array_unique($all) as $v) {
        foreach (babh6_query_variants($v) as $vv) { $conds[] = 'norm LIKE %s'; $args[] = $wpdb->esc_like($vv) . '%'; }
    }
    if (!$conds) return null;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT kind, norm, name, product_count FROM $pt WHERE (" . implode(' OR ', $conds) . ") ORDER BY (kind = 't') DESC, product_count DESC LIMIT 20", $args));
    foreach ((array)$rows as $r) {
        if ($r->norm === $exclude_norm) continue;
        $first = preg_split('/[\s\-]+/u', $r->norm);
        if (!$first || babh6_translit_bg2lat($first[0]) !== $key) continue;
        return array('kind' => $r->kind, 'norm' => $r->norm, 'name' => $r->name);
    }
    return null;
}

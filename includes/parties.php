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

    /* Марки по наименованието (v6.9.1, babh6_brand_groups): началото на името — една до три думи според
       повторенията при фирмата, кирилица ≈ латиница, двуезичните имена („X … / Y …“) сливат изписванията.
       Веднъж за всички продукти на фирмата (статистиката за дължината на марката е по цялата фирма),
       после отделно за собствената марка (без друга насрещна фирма). */
    $brands = array();
    $cp_norm = $kind === 'p' ? $eff_norm : 'producer_norm';
    $cp_name = $kind === 'p' ? $eff_name : 'producer_name';
    $brows = $wpdb->get_results($wpdb->prepare(
        "SELECT name, $cp_norm AS cn, SUBSTRING_INDEX($cp_name, ',', 1) AS cname, ($other_ok) AS ok, ($none_cond) AS own
         FROM $t WHERE deleted_at IS NULL AND $own_cond", $norm));
    $bnames = array();
    foreach ((array)$brows as $i => $r) $bnames[$i] = (string)$r->name;
    $bg = babh6_brand_groups($bnames);
    $counts = array();     /* всички продукти: key → n, cp (насрещни фирми) */
    $own_counts = array(); /* само собствена марка: key → n */
    $own_nobrand = 0;
    foreach ((array)$brows as $i => $r) {
        $key = isset($bg['items'][$i]) ? $bg['items'][$i] : '';
        if ($key === '') { if ((int)$r->own) $own_nobrand++; continue; }
        if (!isset($counts[$key])) $counts[$key] = array('n' => 0, 'cp' => array());
        $counts[$key]['n']++;
        if ((int)$r->own) $own_counts[$key] = isset($own_counts[$key]) ? $own_counts[$key] + 1 : 1;
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
        $g = $bg['groups'][$key];
        $top = null;
        foreach ($c['cp'] as $cn => $cc) { if ($top === null || $cc['n'] > $top['n']) $top = array('norm' => $cn, 'name' => $cc['name'], 'n' => $cc['n']); }
        $brands[] = array('token' => $g['label'], 'key' => $key, 'count' => (int)$c['n'], 'names' => $g['names'],
            'match' => $top ? array('kind' => $kind === 'p' ? 't' : 'p', 'norm' => $top['norm'], 'name' => $top['name'], 'count' => (int)$top['n']) : null);
    }
    arsort($own_counts);
    $own_brands = array(); $i = 0;
    foreach ($own_counts as $key => $n) {
        if ($i >= 80) break;
        $i++;
        $g = $bg['groups'][$key];
        $own_brands[] = array('token' => $g['label'], 'key' => $key, 'count' => (int)$n, 'names' => $g['names']);
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
        'own_brands' => $own_brands, 'own_brands_total' => count($own_counts), 'own_nobrand' => $own_nobrand,
        'rank_all' => $rank_all, 'rank_bg' => $rank_bg,
        'monthly' => $monthly, 'recent12' => $recent12, 'prev12' => $prev12, 'latest' => $latest,
    ));
}

/** Общи думи, които не са марка (и не продължават марка): съставки, форми, единици, служебни думи. */
function babh6_brand_stop() {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(array('витамин','vitamin','vitamins','витамини','магнезий','magnesium','омега','omega','протеин','protein','колаген','collagen',
            'цинк','zinc','калций','calcium','желязо','iron','селен','selenium','коензим','coenzyme','хранителна','добавка','капсули','таблетки','capsules','tablets',
            'complex','комплекс','био','bio','натурален','natural','organic','органик','super','супер','пробиотик','probiotic','мултивитамин','multivitamin',
            'екстракт','extract','масло','oil','чай','tea','прах','powder','сироп','syrup','капки','drops','детски','kids','kid','baby','бебе','men','women',
            'формула','formula','пакет','набор','set','плюс','plus','форте','forte','актив','active','ultra','ултра','max','макс','pro','про','daily','дейли',
            'immune','имун','имуно','имунитет','sport','спорт','sports','whey','bcaa','creatine','креатин','d3','k2','b12','c','d','e','the','на','за','от','и',
            /* продължения, които са продукт, а не марка */
            'glycine','глицин','berberine','берберин','магнезиев','цитрат','citrate','bar','бар','shake','шейк','gel','гел','крем','cream','капсула','таблетка',
            'caps','капс','табл','tabs','tab','softgel','softgels','sachets','саше','пакетчета','packs','pack','бр','pcs','mg','мг','mcg','мкг','g','гр','г','kg','кг',
            'ml','мл','l','л','iu','ме','x','х','with','със','без','without','and','or','или','for','от','по','в','in','of','&','+',
            'liquid','течен','течност','спрей','spray','drink','напитка','порции','servings','serving'));
    }
    return $stop;
}

/** Първата дума на наименование/фирма като „марка"; '' ако е обща дума, кратка или число (ползва се за търговеца по името). */
function babh6_brand_token($name) {
    $stop = babh6_brand_stop();
    $s = mb_strtolower(trim((string)$name), 'UTF-8');
    $s = preg_replace('/^[\s„“"\'«»(\[\-–—]+/u', '', $s);
    if (!preg_match('/^([\p{L}\p{N}][\p{L}\p{N}&+\-]*)/u', $s, $m)) return '';
    $tok = rtrim($m[1], '-+&');
    if (mb_strlen($tok, 'UTF-8') < 3 || preg_match('/^[\p{N}.,]+$/u', $tok)) return '';
    if (isset($stop[$tok])) return '';
    return $tok;
}

/* ============ Марки по наименованието (v6.9.1) ============ */

/**
 * Ключ на дума за сравнение между изписвания: малки букви, кирилица → латиница, после
 * c/q → k, ph → f, w → v, x → ks, y → i, th → t, двойни букви → една, само букви и цифри.
 * „Naturalico“ и „Натуралико“ → naturaliko; „BIOnetic“ и „Бионетик“ → bionetik; „Koloff“ и „Колоф“ → kolof.
 */
function babh6_brand_word_key($w) {
    $k = babh6_translit_bg2lat(trim((string)$w));
    $k = str_replace('ch', "\x01", $k);
    $k = strtr($k, array('ph' => 'f', 'th' => 't', 'ck' => 'k', 'c' => 'k', 'q' => 'k', 'w' => 'v', 'x' => 'ks', 'y' => 'i', 'j' => 'y'));
    $k = str_replace("\x01", 'ch', $k);
    $k = preg_replace('/[^a-z0-9]+/', '', $k);
    return preg_replace('/(.)\1+/', '$1', $k);
}

/**
 * Думите на началото на едно наименование: до първия „силен“ разделител (запетая, „/“, „|“, „;“, „:“, скоба,
 * „+“, тире с интервали). Връща [display, key, start, end] (отмествания в байтове спрямо $name) — най-много 4 думи.
 */
function babh6_brand_words($name, $from = 0) {
    $seg = (string)$name;
    if ($from > 0) $seg = substr($seg, $from);
    if (preg_match('/[,\/|;:()\[\]+]|\s[-–—]\s/u', $seg, $m, PREG_OFFSET_CAPTURE)) $seg = substr($seg, 0, $m[0][1]);
    $out = array();
    if (!preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’&\-]*/u', $seg, $mm, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($mm[0] as $w) {
        $disp = rtrim($w[0], "-&'’");
        if ($disp === '') continue;
        $out[] = array($disp, babh6_brand_word_key($disp), $from + $w[1], $from + $w[1] + strlen($disp));
        if (count($out) >= 4) break;
    }
    return $out;
}

/** Може ли думата да е (част от) марка: ключ с буква, поне 2 знака, не е стоп-дума, не е число/единица. */
function babh6_brand_word_ok($w, $stop) {
    $lw = mb_strtolower($w[0], 'UTF-8');
    if (isset($stop[$lw]) || $w[1] === '' || strlen($w[1]) < 2 || mb_strlen($w[0], 'UTF-8') < 2) return false;
    if (!preg_match('/\p{L}/u', $w[0])) return false;               /* само цифри */
    if (preg_match('/^\p{N}+[\p{L}]{1,3}$/u', $lw)) return false;    /* „300g“, „60caps“ */
    return true;
}

/** Писменост на дума: 'c' кирилица, 'l' латиница, '' друго. */
function babh6_brand_script($w) {
    if (preg_match('/^[\p{Cyrillic}]/u', $w)) return 'c';
    if (preg_match('/^[A-Za-z]/', $w)) return 'l';
    return '';
}

/**
 * Разпределя наименования по марки (автоматично, не поле на БАБХ).
 *
 * Марката е началото на наименованието: първата дума, удължена с втора/трета, когато удълженото начало
 * се повтаря при фирмата (поне 2 продукта и поне 80 % от продуктите с по-късото начало) и следващата
 * дума не е съставка/форма/единица („Health Upgrade“, „Dan Koloff“, но „BIOnetic“, не „BIOnetic Glycine“).
 * Кирилица и латиница се приравняват по ключ на думата (babh6_brand_word_key). При двуезични имена
 * („Naturalico … / Натуралико …“, „… , Натуралико …“) второто изписване се запомня като синоним и
 * продуктите само с него влизат в същата група.
 *
 * @param array $names  id → наименование
 * @return array {items: id → ключ на марката ('' ако няма), groups: ключ → {label, names (изписвания за LIKE), n, ids}}
 */
function babh6_brand_groups($names) {
    $stop = babh6_brand_stop();
    $items = array(); $cnt = array(array(), array(), array());
    foreach ($names as $id => $name) {
        $name = (string)$name;
        $w = babh6_brand_words($name);
        $sw = array();
        if ($w) {
            /* второ изписване: след „/“, или първата дума с друга писменост след разделител */
            $pos = strpos($name, '/');
            if ($pos !== false) { $sw = babh6_brand_words($name, $pos + 1); }
            else {
                $sc = babh6_brand_script($w[0][0]);
                if ($sc !== '' && preg_match_all('/[\p{L}][\p{L}\'’&\-]*/u', $name, $mm, PREG_OFFSET_CAPTURE)) {
                    $prev_end = $w[0][3];
                    foreach ($mm[0] as $m) {
                        if ($m[1] <= $w[0][2]) continue;
                        $between = substr($name, $prev_end, max(0, $m[1] - $prev_end));
                        if (babh6_brand_script($m[0]) !== $sc && babh6_brand_script($m[0]) !== '' && mb_strlen($m[0], 'UTF-8') >= 2 && preg_match('/[,\/|;:–—\-]/u', $between)) {
                            $sw = babh6_brand_words($name, $m[1]); break;
                        }
                        $prev_end = $m[1] + strlen($m[0]);
                    }
                }
            }
        }
        $items[$id] = array('w' => $w, 's' => $sw, 'key' => '', 'L' => 0);
        $pk = '';
        for ($k = 0; $k < 3 && $k < count($w); $k++) {
            if (!babh6_brand_word_ok($w[$k], $stop)) break;
            $pk = $pk === '' ? $w[$k][1] : $pk . ' ' . $w[$k][1];
            $cnt[$k][$pk] = isset($cnt[$k][$pk]) ? $cnt[$k][$pk] + 1 : 1;
        }
    }
    /* дължина на марката за всеки продукт + синоними от второто изписване */
    $pairs = array();
    foreach ($items as $id => &$it) {
        $w = $it['w']; $pk = ''; $L = 0;
        for ($k = 0; $k < 3 && $k < count($w); $k++) {
            if (!babh6_brand_word_ok($w[$k], $stop)) break;
            $npk = $pk === '' ? $w[$k][1] : $pk . ' ' . $w[$k][1];
            if ($k > 0) {
                $n = isset($cnt[$k][$npk]) ? $cnt[$k][$npk] : 0; $m = isset($cnt[$k - 1][$pk]) ? $cnt[$k - 1][$pk] : 0;
                if ($n < 2 || $m < 1 || $n / $m < 0.8) break;
            }
            $pk = $npk; $L = $k + 1;
        }
        $it['key'] = $pk; $it['L'] = $L;
        if ($L > 0 && count($it['s']) >= $L) {
            $sk = '';
            for ($k = 0; $k < $L; $k++) { if (!babh6_brand_word_ok($it['s'][$k], $stop)) { $sk = ''; break; } $sk .= ($sk === '' ? '' : ' ') . $it['s'][$k][1]; }
            if ($sk !== '' && $sk !== $pk) { if (!isset($pairs[$sk][$pk])) $pairs[$sk][$pk] = 0; $pairs[$sk][$pk]++; }
        }
    }
    unset($it);
    $alias = array();
    foreach ($pairs as $sk => $to) {
        arsort($to); $total = array_sum($to);
        if (current($to) / $total >= 0.6) $alias[$sk] = key($to);
    }
    $groups = array(); $out = array();
    foreach ($items as $id => $it) {
        $key = $it['key'];
        if ($key === '') { $out[$id] = ''; continue; }
        if (isset($alias[$key]) && !isset($pairs[$alias[$key]][$key])) $key = $alias[$key];
        $out[$id] = $key;
        $raw = substr($names[$id], $it['w'][0][2], $it['w'][$it['L'] - 1][3] - $it['w'][0][2]);
        $lraw = mb_strtolower($raw, 'UTF-8');
        if (!isset($groups[$key])) $groups[$key] = array('n' => 0, 'ids' => array(), 'spell' => array(), 'disp' => array());
        $groups[$key]['n']++;
        $groups[$key]['ids'][] = $id;
        $groups[$key]['spell'][$lraw] = isset($groups[$key]['spell'][$lraw]) ? $groups[$key]['spell'][$lraw] + 1 : 1;
        if (!isset($groups[$key]['disp'][$lraw])) $groups[$key]['disp'][$lraw] = $raw;
    }
    foreach ($groups as $key => &$g) {
        arsort($g['spell']);
        $g['label'] = $g['disp'][key($g['spell'])];
        $g['names'] = array_slice(array_keys($g['spell']), 0, 8);
        unset($g['spell'], $g['disp']);
    }
    unset($g);
    return array('items' => $out, 'groups' => $groups);
}

/** LIKE шаблони за име, започващо с марката (с кавички отпред и кирилица/латиница). */
function babh6_brand_like_variants($tok, $translit = true) {
    global $wpdb;
    $out = array();
    foreach ($translit ? babh6_query_variants($tok) : array(mb_strtolower(trim($tok), 'UTF-8')) as $v) {
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

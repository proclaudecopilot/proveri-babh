<?php
if (!defined('ABSPATH')) exit;

/**
 * Публично REST API за фронтенда: /wp-json/babh6/v1/...
 * Само четене на регистъра + запис в waitlist по функция.
 */

add_action('rest_api_init', function () {
    register_rest_route('babh6/v1', '/products', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_products', 'permission_callback' => 'babh6_rest_permission',
    ));
    register_rest_route('babh6/v1', '/product/(?P<reg>[^/]+)', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_product', 'permission_callback' => 'babh6_rest_permission',
    ));
    register_rest_route('babh6/v1', '/stats', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_stats', 'permission_callback' => 'babh6_rest_permission',
    ));
    /* v6.7.3: CSV съдържа състава на хиляди записи наведнъж — само с Pro достъп */
    register_rest_route('babh6/v1', '/export', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_export', 'permission_callback' => 'babh6_rest_permission_pro',
    ));
    register_rest_route('babh6/v1', '/waitlist', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_waitlist', 'permission_callback' => 'babh6_rest_permission',
    ));
});

/* v6.7.3: отговорите с данни не се кешират от хостинга/CDN — иначе детайл, отворен от един
   посетител (или Pro отговор), може да се покаже на друг */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    $route = (string)$request->get_route();
    if (preg_match('#^/babh6/v1/(products|product/|party|parties|export)#', $route) && $response instanceof WP_REST_Response) {
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Cookie');
    }
    return $response;
}, 10, 3);

/* ============ Транслитерация за търсене (BG↔EN) ============ */
function babh6_translit_bg2lat($s) {
    static $m = array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sht','ъ'=>'a','ь'=>'y','ю'=>'yu','я'=>'ya');
    return strtr(mb_strtolower($s, 'UTF-8'), $m);
}
function babh6_translit_lat2bg($s) {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, array('sht'=>'щ','zh'=>'ж','ch'=>'ч','sh'=>'ш','yu'=>'ю','ya'=>'я','ts'=>'ц'));
    return strtr($s, array('a'=>'а','b'=>'б','v'=>'в','g'=>'г','d'=>'д','e'=>'е','z'=>'з','i'=>'и','y'=>'й','k'=>'к','l'=>'л','m'=>'м','n'=>'н','o'=>'о','p'=>'п','r'=>'р','s'=>'с','t'=>'т','u'=>'у','f'=>'ф','h'=>'х','c'=>'ц','q'=>'к','w'=>'в','x'=>'кс','j'=>'дж'));
}
function babh6_query_variants($s) {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $out = array($s);
    if (preg_match('/[А-Яа-я]/u', $s)) $out[] = babh6_translit_bg2lat($s);
    if (preg_match('/[A-Za-z]/', $s)) $out[] = babh6_translit_lat2bg($s);
    $out = array_values(array_unique(array_filter($out, function ($v) { return mb_strlen($v, 'UTF-8') >= 2; })));
    return $out ? $out : array($s);
}

/**
 * Общ интервал „последните 12 месеца“ (OV-02, OV-03): 12 последователни календарни
 * месеца, включително текущия (непълен), в часовата зона на сайта. Един и същ
 * интервал за брояча, графиката, филтъра „recent“ и CSV.
 * @return array {from: Y-m-d (вкл.), to: Y-m-d (изкл.), keys: [Y-m × 12]}
 */
function babh6_recent_range($now_ts = null) {
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now = new DateTime('@' . ($now_ts === null ? time() : (int)$now_ts));
    $now->setTimezone($tz);
    $first = new DateTime($now->format('Y-m-01') . ' 00:00:00', $tz);
    $from = clone $first; $from->modify('-11 months');
    $to   = clone $first; $to->modify('+1 month');
    $keys = array();
    for ($i = 0; $i < 12; $i++) { $d = clone $from; if ($i) $d->modify('+' . $i . ' months'); $keys[] = $d->format('Y-m'); }
    return array('from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'keys' => $keys);
}

/* ============ Общ query builder за products/export ============ */
/**
 * @return true|WP_Error
 * Статус (status=): '' наличен в последните данни | deleted — липсва в последния пълен файл |
 *                   delnote — в източника има бележка за заличаване | all — всички записи.
 * flagged=1 е независим филтър (само с автоматична бележка) и се комбинира с всеки статус.
 */
function babh6_build_where($req, &$where, &$args) {
    global $wpdb;
    $status = (string)$req->get_param('status');
    if ($status === '' && $req->get_param('deleted')) $status = 'deleted'; /* стар параметър */
    if ($status === 'deleted')      $where = array('deleted_at IS NOT NULL');
    elseif ($status === 'delnote')  $where = array("deletion <> ''");
    elseif ($status === 'all')      $where = array('1=1');
    else                            $where = array('deleted_at IS NULL');
    $args  = array();

    /* „За проверка“ е Pro функция (v6.7): без Pro достъп филтърът се игнорира */
    if ($req->get_param('flagged') && babh6_flags_visible()) $where[] = 'flag_count > 0';
    /* Тип на рег. номер: rt=П|Т (старият параметър bg=1 е „П“) */
    $rt = (string)$req->get_param('rt');
    if ($rt === '' && $req->get_param('bg')) $rt = 'П';
    if (in_array($rt, array('П', 'Т'), true)) { $where[] = 'rtype = %s'; $args[] = $rt; }

    /* Категории: една или няколко през запетая (продукти от поне една от тях) */
    $cats = array_filter(array_map('sanitize_key', explode(',', (string)$req->get_param('cat'))));
    if ($cats) {
        $where[] = 'category IN (' . implode(',', array_fill(0, count($cats), '%s')) . ')';
        foreach ($cats as $c) $args[] = $c;
    }

    $year = (int)$req->get_param('year');
    if ($year >= 1990 && $year <= 2100) { $where[] = 'ryear = %d'; $args[] = $year; }

    $obl = sanitize_text_field((string)$req->get_param('obl'));
    if ($obl !== '') { $where[] = 'oblast = %s'; $args[] = $obl; }

    /* Филтри по фирма (от профилите в Pro) — по нормализирания ключ */
    $pn = (string)$req->get_param('producer');
    if ($pn !== '') { $where[] = 'producer_norm = %s'; $args[] = mb_substr($pn, 0, 191, 'UTF-8'); }
    /* Търговец: един модел навсякъде (CO-07) — „ефективният“ търговец: определеният по името,
       ако има такъв, иначе посоченият в регистъра; inferred=0 ограничава до посочените. */
    $tn = (string)$req->get_param('trader');
    if ($tn !== '') {
        if ((string)$req->get_param('inferred') === '0') { $where[] = 'trader_norm = %s'; }
        else { $where[] = "IF(trader_inf_norm <> '', trader_inf_norm, trader_norm) = %s"; }
        $args[] = mb_substr($tn, 0, 191, 'UTF-8');
    }
    if ((string)$req->get_param('inferred') === '1') $where[] = "trader_inf_norm <> ''";
    if ($req->get_param('own')) {
        /* продукти без насрещна фирма (без посочен търговец / производител), и нищо определено по името */
        if ($pn !== '') $where[] = "trader_inf_norm = '' AND (trader_kind <> 'firm' OR trader_norm = '' OR trader_norm = producer_norm)";
        elseif ($tn !== '') $where[] = "(producer_kind <> 'firm' OR producer_norm = '' OR producer_norm = IF(trader_inf_norm <> '', trader_inf_norm, trader_norm))";
    }
    $brand = trim((string)$req->get_param('brand'));
    if ($brand !== '' && function_exists('babh6_brand_like_variants')) {
        $ors = array();
        foreach (babh6_brand_like_variants(mb_substr($brand, 0, 60, 'UTF-8')) as $v) { $ors[] = 'name LIKE %s'; $args[] = $v; }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    if ($req->get_param('recent')) {
        $rr = babh6_recent_range();
        $where[] = 'notif_date >= %s AND notif_date < %s';
        $args[]  = $rr['from']; $args[] = $rr['to'];
    }

    $q = trim((string)$req->get_param('q'));
    if ($q !== '' && mb_strlen($q, 'UTF-8') < 2) {
        return new WP_Error('babh6_query_short', 'Въведи поне 2 знака за търсене.', array('status' => 400));
    }
    if ($q !== '') {
        $regq = preg_replace('/\s+/u', '', $q);
        if (preg_match('/^([ПТптPTpt]?)(\d{3,})(.*)$/u', $regq, $rm)) {
            /* Търсене по рег. номер: префиксът (П/Т) и суфиксът се запазват (PR-04) */
            $prefix = mb_strtoupper(strtr($rm[1], array('P' => 'П', 'p' => 'П', 'T' => 'Т', 't' => 'Т')), 'UTF-8');
            $like = ($prefix !== '' ? $wpdb->esc_like($prefix) : '_') . '%' . $wpdb->esc_like($rm[2]) . '%' . ($rm[3] !== '' ? $wpdb->esc_like($rm[3]) . '%' : '');
            $where[] = 'reg LIKE %s';
            $args[]  = $like;
        } else {
            $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
            $tokens = array_slice($tokens, 0, 6);
            $short  = false;
            foreach ($tokens as $tk) { if (mb_strlen($tk, 'UTF-8') < 3) { $short = true; break; } }
            $use_ft = ((int)get_option('babh6_fulltext', 0) === 1) && !$short;

            if ($use_ft) {
                $expr = array();
                foreach ($tokens as $tk) {
                    $vs = babh6_query_variants($tk);
                    $grp = array();
                    foreach ($vs as $v) {
                        $v = preg_replace('/[+\-<>()~*"@]/u', '', $v);
                        if (mb_strlen($v, 'UTF-8') >= 3) $grp[] = $v . '*';
                    }
                    if ($grp) $expr[] = '+(' . implode(' ', $grp) . ')';
                }
                if ($expr) {
                    /* Същите полета като в LIKE режима: индексът + определеният търговец и рег. № (PR-04) */
                    $ors = array('MATCH(name, composition, producer_name, trader_name) AGAINST (%s IN BOOLEAN MODE)');
                    $args[] = implode(' ', $expr);
                    foreach (babh6_query_variants($q) as $v) { $ors[] = 'trader_inf_name LIKE %s'; $args[] = '%' . $wpdb->esc_like($v) . '%'; }
                    $where[] = '(' . implode(' OR ', $ors) . ')';
                }
            } else {
                foreach ($tokens as $tk) {
                    $vs  = babh6_query_variants($tk);
                    $ors = array();
                    foreach ($vs as $v) {
                        $like = '%' . $wpdb->esc_like($v) . '%';
                        foreach (array('name', 'composition', 'producer_name', 'trader_name', 'trader_inf_name', 'reg') as $col) {
                            $ors[]  = "$col LIKE %s";
                            $args[] = $like;
                        }
                    }
                    $where[] = '(' . implode(' OR ', $ors) . ')';
                }
            }
        }
    }
    return true;
}

function babh6_maybe_prepare($sql, $args) {
    global $wpdb;
    return $args ? $wpdb->prepare($sql, $args) : $sql;
}

/**
 * Подреждане по съвпадение при търсене: съвпадение в началото на името е най-силно,
 * после в името, после във фирмата; съставът е най-слаб. Кирилица/латиница са равностойни.
 * Връща SQL израз (с %s placeholders) и добавя аргументите в $args.
 */
function babh6_relevance_sql($q, &$args) {
    global $wpdb;
    $q = trim((string)$q);
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) return '';
    $parts = array();
    foreach (babh6_query_variants($q) as $v) {
        $like = '%' . $wpdb->esc_like($v) . '%';
        $parts[] = '(name LIKE %s) * 16'; $args[] = $wpdb->esc_like($v) . '%';
        $parts[] = '(name LIKE %s) * 8';  $args[] = $like;
        $parts[] = '(producer_name LIKE %s OR trader_name LIKE %s OR trader_inf_name LIKE %s) * 3'; $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $tokens = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 6);
    if (count($tokens) > 1) {
        foreach ($tokens as $tk) {
            if (mb_strlen($tk, 'UTF-8') < 2) continue;
            foreach (babh6_query_variants($tk) as $v) { $parts[] = '(name LIKE %s)'; $args[] = '%' . $wpdb->esc_like($v) . '%'; }
        }
    }
    return $parts ? '(' . implode(' + ', $parts) . ')' : '';
}

/** Стабилно подреждане: всяка сортировка има уникален вторичен ключ (PR-09). */
function babh6_order_sql($sort) {
    $map = array(
        /* v6.7.1: рег. № е Т/П + код на областта (2) + година (2) + пореден номер — сортирането по целия
           низ подреждаше по област. „Най-нови/най-стари“ е по годината от рег. №, после по дата на
           уведомление и поредния номер. */
        'new'     => '(ryear IS NULL) ASC, ryear DESC, (notif_date IS NULL) ASC, notif_date DESC, SUBSTRING(reg, 6) DESC, id DESC',
        'old'     => '(ryear IS NULL) ASC, ryear ASC, (notif_date IS NULL) ASC, notif_date ASC, SUBSTRING(reg, 6) ASC, id ASC',
        'name'    => 'name ASC, ryear DESC, id DESC',
        'flagged' => 'flag_count DESC, ryear DESC, notif_date DESC, id DESC',
        'date'    => '(notif_date IS NULL) ASC, notif_date DESC, ryear DESC, id DESC',
    );
    if ($sort === 'flagged' && !babh6_flags_visible()) $sort = 'new';
    return isset($map[$sort]) ? $map[$sort] : $map['new'];
}

/** Автоматичните бележки „За проверка“ се виждат само с Pro достъп (v6.7) — и в API-то, не само в интерфейса. */
function babh6_flags_visible() {
    return function_exists('babh6_pro_ok') && babh6_pro_ok();
}

/**
 * Общ избор на редове за /products и /export — един и същ набор и подреждане (EX-01).
 * Релевантност само при sort=rel или без сортировка (PR-09); „най-нови“ остава по рег. №.
 */
function babh6_products_select($req, $cols, $limit, $offset = 0) {
    global $wpdb;
    $t = babh6_table('products');
    $err = babh6_build_where($req, $where, $args);
    if (is_wp_error($err)) return $err;
    $wsql = implode(' AND ', $where);
    $sort = (string)$req->get_param('sort');
    $rel_args = array(); $rel = '';
    if ($sort === '' || $sort === 'rel') $rel = babh6_relevance_sql((string)$req->get_param('q'), $rel_args);
    if ($rel !== '') {
        $sql  = "SELECT $cols, $rel AS rel FROM $t WHERE $wsql ORDER BY rel DESC, ryear DESC, notif_date DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($rel_args, $args, array($limit, $offset))));
    } else {
        $order = babh6_order_sql($sort);
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT $cols FROM $t WHERE $wsql ORDER BY $order LIMIT %d OFFSET %d", array_merge($args, array($limit, $offset))));
    }
    if ($rows === null || $wpdb->last_error) {
        return new WP_Error('babh6_db', 'Грешка в базата данни при търсенето. Опитай отново.', array('status' => 500));
    }
    return array('rows' => $rows, 'where' => $wsql, 'args' => $args);
}

function babh6_row_to_item($r) {
    /* Бележките се преизчисляват при показване: така старите записи също носят
       намерения термин, полето и откъса (не само label от времето на качването). */
    $flags = (babh6_flags_visible() && (isset($r->composition) || isset($r->purpose)))
        ? babh6_find_flags_fields($r->name, isset($r->composition) ? $r->composition : '', isset($r->purpose) ? $r->purpose : '')
        : array();
    foreach ($flags as &$fl) { $fl['field_label'] = babh6_field_label($fl['field']); }
    unset($fl);
    /* Търговец, определен по името на продукта — отделно от посочения в регистъра (PR-11) */
    $inf = !empty($r->trader_inf_norm);
    return array(
        'reg'  => $r->reg,
        't'    => $r->rtype,
        'y'    => $r->ryear ? (int)$r->ryear : null,
        'o'    => $r->oblast,
        'n'    => $r->name,
        'p'    => $r->producer_name,
        'pk'   => $r->producer_kind,
        'pn'   => isset($r->producer_norm) ? $r->producer_norm : '',
        'tr'   => $r->trader_name,
        'tk'   => $r->trader_kind,
        'tn'   => isset($r->trader_norm) ? $r->trader_norm : '',
        'ti'   => $inf ? 1 : 0,
        'tin'  => $inf ? (string)$r->trader_inf_name : '',
        'tinn' => $inf ? (string)$r->trader_inf_norm : '',
        'c'    => $r->composition,
        'pp'   => $r->purpose,
        'st'   => $r->storage,
        'nn'   => isset($r->notif_no) ? (string)$r->notif_no : '',
        'nd'   => $r->notif_date,
        'ld'   => $r->launch_date,
        'ed'   => isset($r->entry_date) ? $r->entry_date : null,
        'cat'  => $r->category,
        'catl' => babh6_category_label($r->category ? $r->category : 'other'),
        'f'    => $flags,
        'x'    => !empty($r->deleted_at) ? 1 : 0,
        'xd'   => !empty($r->deleted_at) ? substr((string)$r->deleted_at, 0, 10) : '',
        'del'  => !empty($r->deletion),
        'dn'   => isset($r->deletion) ? (string)$r->deletion : '',
    );
}

/** Кратък запис за списъка: само каквото показва картата. */
function babh6_row_to_card($r) {
    $first = function ($v) { $p = explode(',', (string)$v); return trim($p[0]); };
    return array(
        'reg'  => $r->reg,
        't'    => $r->rtype,
        'n'    => $r->name,
        'p'    => $first($r->producer_name),
        'pk'   => $r->producer_kind,
        'pn'   => isset($r->producer_norm) ? $r->producer_norm : '',
        'tr'   => $first($r->trader_name),
        'tk'   => $r->trader_kind,
        'tn'   => isset($r->trader_norm) ? $r->trader_norm : '',
        'nd'   => $r->notif_date,
        'cat'  => $r->category,
        'catl' => babh6_category_label($r->category ? $r->category : 'other'),
        /* броят бележки (без текста им) — само с Pro */
        'fc'   => babh6_flags_visible() ? (int)$r->flag_count : 0,
        'x'    => !empty($r->deleted_at) ? 1 : 0,
        'del'  => !empty($r->deletion),
        'short' => 1,
    );
}

/* ============ GET /products ============ */
function babh6_rest_products($req) {
    global $wpdb;
    $t = babh6_table('products');

    $page = max(1, (int)$req->get_param('page'));
    $per  = (int)$req->get_param('per');
    if ($per < 1 || $per > 50) $per = 20;
    $offset = ($page - 1) * $per;

    $cols = 'id, reg, rtype, name, producer_name, producer_kind, producer_norm, trader_name, trader_kind, trader_norm, notif_date, deletion, category, flag_count, deleted_at';
    $sel = babh6_products_select($req, $cols, $per, $offset);
    if (is_wp_error($sel)) return $sel;

    $total = $wpdb->get_var(babh6_maybe_prepare("SELECT COUNT(*) FROM $t WHERE {$sel['where']}", $sel['args']));
    if ($total === null || $wpdb->last_error) return new WP_Error('babh6_db', 'Грешка в базата данни при търсенето. Опитай отново.', array('status' => 500));

    /* v6.7.3: списъкът връща само полетата на картата; съставът, предназначението, съхранението,
       адресите и бележките идват само от /product/{reg} (детайлът, който по-късно ще се брои) */
    $items = array_map('babh6_row_to_card', $sel['rows'] ? $sel['rows'] : array());

    return rest_ensure_response(array(
        'total' => (int)$total,
        'page'  => $page,
        'per'   => $per,
        'items' => $items,
    ));
}

/* ============ GET /product/{reg} — точен запис, включително липсващ в последния файл (PR-10) ============ */
function babh6_rest_product($req) {
    global $wpdb;
    $t   = babh6_table('products');
    $reg = sanitize_text_field(rawurldecode((string)$req['reg']));
    $reg = preg_replace('/\s+/u', '', $reg);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE reg = %s LIMIT 1", $reg));
    if (!$row) {
        $pr = babh6_parse_reg($reg);
        if ($pr && $pr['reg'] !== $reg) $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE reg = %s LIMIT 1", $pr['reg']));
    }
    if (!$row) return new WP_Error('babh6_notfound', 'Няма намерен запис с този регистрационен номер.', array('status' => 404));
    return rest_ensure_response(babh6_row_to_item($row));
}

/* ============ GET /stats ============ */
function babh6_rest_stats() {
    $cached = get_transient('babh6_stats');
    if (is_array($cached) && isset($cached['sync_result'])) return rest_ensure_response(babh6_stats_public($cached));

    global $wpdb;
    $t  = babh6_table('products');
    $pt = babh6_table('parties');
    $ut = babh6_table('uploads');

    $out = array(
        'total'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL"),
        'flagged'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND flag_count > 0"),
        'producers_bg' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 'p' AND is_bg = 1"),
        'traders_bg'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 't' AND is_bg = 1"),
        'deleted'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted_at IS NOT NULL"),
        'delnote'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deletion <> ''"),
        'producers'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 'p'"),
        'traders'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 't'"),
        'inferred'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND trader_inf_norm <> ''"),
    );

    /* Актуалност (OV-06): дата на последния пълен импорт, дата на източника, кога е проверявано и с какъв резултат */
    $last = $wpdb->get_row("SELECT uploaded_at, notes FROM $ut WHERE status = 'done' ORDER BY id DESC LIMIT 1");
    $out['last_update']    = $last ? date_i18n('d.m.Y', strtotime($last->uploaded_at)) : null;
    $out['last_update_at'] = $last ? date_i18n('d.m.Y H:i', strtotime($last->uploaded_at)) : null;
    $out['last_partial']   = ($last && !empty($last->notes)) ? 1 : 0;
    $st = function_exists('babh6_sync_state') ? babh6_sync_state() : array();
    $out['source_date']  = !empty($st['portal_updated']) ? date_i18n('d.m.Y', strtotime($st['portal_updated'])) : null;
    $out['checked_at']   = !empty($st['last_check']) ? date_i18n('d.m.Y H:i', strtotime($st['last_check'])) : null;
    $out['sync_result']  = isset($st['last_result']) ? (string)$st['last_result'] : '';
    $out['sync_enabled'] = function_exists('babh6_sync_enabled') && babh6_sync_enabled() ? 1 : 0;
    $out['check_overdue'] = 0;
    if ($out['sync_enabled'] && !empty($st['last_check']) && time() - strtotime($st['last_check']) > 8 * DAY_IN_SECONDS) $out['check_overdue'] = 1;

    /* Месечна серия — точно 12 календарни месеца, същия интервал като филтъра „recent“ */
    $rr = babh6_recent_range();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(notif_date, '%%Y-%%m') AS m, COUNT(*) AS c
         FROM $t WHERE deleted_at IS NULL AND notif_date >= %s AND notif_date < %s
         GROUP BY m", $rr['from'], $rr['to']
    ));
    $map = array();
    foreach ($rows as $r) $map[$r->m] = (int)$r->c;
    $monthly = array();
    foreach ($rr['keys'] as $k) $monthly[] = array('m' => $k, 'c' => isset($map[$k]) ? $map[$k] : 0);
    $out['monthly']  = $monthly;
    $out['recent12'] = array_sum(array_map(function ($x) { return $x['c']; }, $monthly));
    $out['recent_from'] = $rr['from'];
    $out['recent_to']   = $rr['to'];

    /* Категории */
    $cats = array();
    $crows = $wpdb->get_results("SELECT category, COUNT(*) AS c FROM $t WHERE deleted_at IS NULL GROUP BY category ORDER BY c DESC");
    foreach ($crows as $r) {
        $code = $r->category ? $r->category : 'other';
        $cats[] = array('code' => $code, 'label' => babh6_category_label($code), 'count' => (int)$r->c);
    }
    $out['cats'] = $cats;

    /* Опции за филтрите покриват и архивните записи (PR-06) */
    $out['years']   = array_map('intval', (array)$wpdb->get_col("SELECT DISTINCT ryear FROM $t WHERE ryear IS NOT NULL ORDER BY ryear DESC"));
    $out['oblasti'] = (array)$wpdb->get_col("SELECT DISTINCT oblast FROM $t WHERE oblast <> '' ORDER BY oblast ASC");
    $out['rules']   = defined('BABH6_RULES_VERSION') ? BABH6_RULES_VERSION : '';

    set_transient('babh6_stats', $out, HOUR_IN_SECONDS);
    return rest_ensure_response(babh6_stats_public($out));
}
/* Кешът е общ; броят „За проверка“ се маха на изхода, ако посетителят няма Pro достъп */
function babh6_stats_public($out) {
    if (!babh6_flags_visible()) unset($out['flagged']);
    return $out;
}

/* ============ CSV ============ */
/**
 * Безопасна клетка за spreadsheet (EX-02): стойностите от външния източник остават текст —
 * формулен префикс (=, +, -, @, таб) се неутрализира, а CR/LF се нормализират.
 */
function babh6_csv_cell($v) {
    $v = (string)$v;
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    if ($v !== '' && !is_numeric($v) && preg_match('/^[=+\-@\t]/', $v)) $v = "'" . $v;
    return $v;
}
function babh6_csv_row($fh, $row) {
    fputcsv($fh, array_map('babh6_csv_cell', $row), ';', '"', '');
}

/* ============ GET /export — CSV на текущите филтри, същия набор и подреждане като списъка ============ */
function babh6_rest_export($req) {
    global $wpdb;
    $limit = (int)apply_filters('babh6_export_limit', 5000);
    $sel = babh6_products_select($req, 'id, reg, name, producer_name, producer_kind, trader_name, trader_kind, trader_inf_name, notif_no, notif_date, entry_date, category, composition, purpose, deletion, deleted_at', $limit, 0);
    if (is_wp_error($sel)) return $sel;
    $rows  = $sel['rows'];
    $total = (int)$wpdb->get_var(babh6_maybe_prepare("SELECT COUNT(*) FROM " . babh6_table('products') . " WHERE {$sel['where']}", $sel['args']));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="babh-register-' . date_i18n('Y-m-d') . '.csv"');
    header('X-Babh6-Total: ' . $total);
    header('X-Babh6-Rows: ' . count((array)$rows));
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    $show_flags = babh6_flags_visible();
    $head = array('Регистрационен №', 'Наименование на продукта', 'Наличност в последния пълен файл', 'Бележка за заличаване в източника',
        'Производител', 'Тип на полето „Производител“', 'Търговец (посочен в регистъра)', 'Тип на полето „Търговец“',
        'Възможен търговец по наименованието (автоматично предположение, не е поле на БАБХ)',
        'Номер на уведомление', 'Дата на уведомление', 'Дата на вписване', 'Автоматична категория',
        'Бележки за проверка (автоматични съвпадения по дума, не становище)', 'Състав (текст от регистъра)', 'Предназначение (текст от регистъра)');
    if (!$show_flags) array_splice($head, 13, 1);
    babh6_csv_row($fh, $head);
    $kinds = array('firm' => 'фирма', 'country' => 'посочена държава', '' => '');
    foreach ((array)$rows as $r) {
        $flags = $show_flags ? implode('; ', array_map(function ($f) { return $f['label'] . ' (в ' . babh6_field_label($f['field']) . ': ' . $f['term'] . ')'; },
            babh6_find_flags_fields($r->name, $r->composition, $r->purpose))) : null;
        $row = array($r->reg, $r->name,
            $r->deleted_at ? 'липсва от ' . substr($r->deleted_at, 0, 10) : 'наличен',
            (string)$r->deletion,
            $r->producer_name, isset($kinds[$r->producer_kind]) ? $kinds[$r->producer_kind] : $r->producer_kind,
            $r->trader_name, isset($kinds[$r->trader_kind]) ? $kinds[$r->trader_kind] : $r->trader_kind,
            (string)$r->trader_inf_name,
            (string)$r->notif_no, (string)$r->notif_date, (string)$r->entry_date,
            babh6_category_label($r->category ? $r->category : 'other'), $flags,
            (string)$r->composition, (string)$r->purpose);
        if (!$show_flags) array_splice($row, 13, 1); /* без Pro колоната с бележките липсва изцяло */
        babh6_csv_row($fh, $row);
    }
    /* Обхватът е явен в самия файл, когато лимитът е достигнат (EX-01) */
    if ($total > count((array)$rows)) {
        babh6_csv_row($fh, array('# Изнесени са първите ' . count((array)$rows) . ' от общо ' . $total . ' резултата според избраното подреждане. Стесни търсенето за пълен списък.'));
    }
    fclose($fh);
    exit;
}

/* ============ POST /waitlist — интерес по функция (WL-01) ============ */
function babh6_rest_waitlist($req) {
    global $wpdb;

    /* honeypot */
    if ((string)$req->get_param('website') !== '') {
        return rest_ensure_response(array('ok' => 1));
    }

    $email = sanitize_email((string)$req->get_param('email'));
    if (!is_email($email) || strlen($email) > 191) {
        return new WP_Error('babh6_email', 'Въведи валиден имейл адрес, например name@example.com.', array('status' => 400));
    }

    /* лек rate limit по IP */
    $key = 'babh6_wl_' . md5(babh6_client_ip());
    $n   = (int)get_transient($key);
    if ($n > 10) return new WP_Error('babh6_rate', 'Направени са твърде много опити. Опитай отново по-късно.', array('status' => 429));
    set_transient($key, $n + 1, HOUR_IN_SECONDS);

    $allowed = array('pro', 'producers', 'traders', 'novel', 'inspector', 'watchlist', 'rank');
    $source = sanitize_key((string)$req->get_param('source'));
    if (!in_array($source, $allowed, true)) $source = 'pro';
    $wt = babh6_table('waitlist');
    $res = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $wt (email, source, created_at) VALUES (%s, %s, %s)",
        $email, $source, current_time('mysql')
    ));
    if ($res === false) {
        return new WP_Error('babh6_db', 'Имейлът не е записан поради технически проблем. Опитай отново.', array('status' => 500));
    }
    /* 0 засегнати реда = същият имейл вече е записан за същата функция (UNIQUE email+source) */
    return rest_ensure_response(array('ok' => 1, 'source' => $source, 'exists' => ((int)$wpdb->rows_affected === 0) ? 1 : 0));
}

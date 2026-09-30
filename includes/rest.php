<?php
if (!defined('ABSPATH')) exit;

/**
 * Публично REST API за фронтенда: /wp-json/babh6/v1/...
 * Само четене на регистъра + запис в Pro waitlist.
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
    register_rest_route('babh6/v1', '/export', array(
        'methods' => 'GET', 'callback' => 'babh6_rest_export', 'permission_callback' => 'babh6_rest_permission',
    ));
    register_rest_route('babh6/v1', '/waitlist', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_waitlist', 'permission_callback' => 'babh6_rest_permission',
    ));
});

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

/* ============ Общ query builder за products/export ============ */
function babh6_build_where($req, &$where, &$args) {
    global $wpdb;
    $where = array('deleted_at IS NULL');
    $args  = array();

    if ($req->get_param('flagged')) $where[] = 'flag_count > 0';
    /* Тип на рег. номер: rt=П|Т (старият параметър bg=1 е „П“) */
    $rt = (string)$req->get_param('rt');
    if ($rt === '' && $req->get_param('bg')) $rt = 'П';
    if (in_array($rt, array('П', 'Т'), true)) { $where[] = 'rtype = %s'; $args[] = $rt; }

    $cat = sanitize_key((string)$req->get_param('cat'));
    if ($cat !== '') { $where[] = 'category = %s'; $args[] = $cat; }

    $year = (int)$req->get_param('year');
    if ($year >= 1990 && $year <= 2100) { $where[] = 'ryear = %d'; $args[] = $year; }

    $obl = sanitize_text_field((string)$req->get_param('obl'));
    if ($obl !== '') { $where[] = 'oblast = %s'; $args[] = $obl; }

    /* Филтри по фирма (от профилите в Pro) — по нормализирания ключ */
    $pn = (string)$req->get_param('producer');
    if ($pn !== '') { $where[] = 'producer_norm = %s'; $args[] = mb_substr($pn, 0, 191, 'UTF-8'); }
    /* Търговец: посоченият в регистъра ИЛИ определеният по името на продукта (trader_inf_norm) */
    $tn = (string)$req->get_param('trader');
    if ($tn !== '') { $where[] = '(trader_norm = %s OR trader_inf_norm = %s)'; $args[] = mb_substr($tn, 0, 191, 'UTF-8'); $args[] = mb_substr($tn, 0, 191, 'UTF-8'); }
    if ($req->get_param('inferred')) $where[] = "trader_inf_norm <> ''";
    if ($req->get_param('own')) {
        /* продукти без насрещна фирма (собствена марка / без търговец), и нищо определено по името */
        if ($pn !== '') $where[] = "trader_inf_norm = '' AND (trader_kind <> 'firm' OR trader_norm = '' OR trader_norm = producer_norm)";
        elseif ($tn !== '') $where[] = "(producer_kind <> 'firm' OR producer_norm = '' OR producer_norm = IF(trader_inf_norm <> '', trader_inf_norm, trader_norm))";
    }
    if ($req->get_param('deleted')) { $where[0] = 'deleted_at IS NOT NULL'; }
    $brand = trim((string)$req->get_param('brand'));
    if ($brand !== '' && function_exists('babh6_brand_like_variants')) {
        $ors = array();
        foreach (babh6_brand_like_variants(mb_substr($brand, 0, 60, 'UTF-8')) as $v) { $ors[] = 'name LIKE %s'; $args[] = $v; }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    if ($req->get_param('recent')) {
        $where[] = 'notif_date >= %s';
        $args[]  = gmdate('Y-m-d', strtotime('-12 months'));
    }

    $q = trim((string)$req->get_param('q'));
    if ($q !== '' && mb_strlen($q, 'UTF-8') >= 2) {
        $regq = preg_replace('/\s+/u', '', $q);
        if (preg_match('/^[ПТптPTpt]?\d{3,}$/u', $regq)) {
            /* Търсене по рег. номер */
            $digits = preg_replace('/^[ПТптPTpt]/u', '', $regq);
            $where[] = 'reg LIKE %s';
            $args[]  = '%' . $wpdb->esc_like($digits) . '%';
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
                    $where[] = 'MATCH(name, composition, producer_name, trader_name) AGAINST (%s IN BOOLEAN MODE)';
                    $args[]  = implode(' ', $expr);
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

function babh6_order_sql($sort) {
    $map = array(
        'new'     => 'reg DESC',
        'old'     => 'reg ASC',
        'name'    => 'name ASC',
        'flagged' => 'flag_count DESC, reg DESC',
        'date'    => '(notif_date IS NULL) ASC, notif_date DESC, reg DESC',
    );
    return isset($map[$sort]) ? $map[$sort] : $map['new'];
}

function babh6_row_to_item($r) {
    /* Бележките се преизчисляват при показване: така старите записи също носят
       намерения термин, полето и откъса (не само label от времето на качването). */
    $flags = (isset($r->composition) || isset($r->purpose))
        ? babh6_find_flags_fields($r->name, isset($r->composition) ? $r->composition : '', isset($r->purpose) ? $r->purpose : '')
        : array();
    foreach ($flags as &$fl) { $fl['field_label'] = babh6_field_label($fl['field']); }
    unset($fl);
    /* Търговец, определен по името на продукта (регистърът не го посочва) — вж. includes/infer.php */
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
        'tr'   => $inf ? $r->trader_inf_name : $r->trader_name,
        'tk'   => $inf ? 'firm' : $r->trader_kind,
        'tn'   => $inf ? $r->trader_inf_norm : (isset($r->trader_norm) ? $r->trader_norm : ''),
        'ti'   => $inf ? 1 : 0,
        'tro'  => $inf ? (string)$r->trader_name : '',
        'c'    => $r->composition,
        'pp'   => $r->purpose,
        'st'   => $r->storage,
        'nd'   => $r->notif_date,
        'ld'   => $r->launch_date,
        'cat'  => $r->category,
        'catl' => babh6_category_label($r->category ? $r->category : 'other'),
        'f'    => $flags,
        'del'  => !empty($r->deletion),
        'dn'   => isset($r->deletion) ? (string)$r->deletion : '',
    );
}

/* ============ GET /products ============ */
function babh6_rest_products($req) {
    global $wpdb;
    $t = babh6_table('products');

    babh6_build_where($req, $where, $args);
    $wsql = implode(' AND ', $where);

    $total = (int)$wpdb->get_var(babh6_maybe_prepare("SELECT COUNT(*) FROM $t WHERE $wsql", $args));

    $page = max(1, (int)$req->get_param('page'));
    $per  = (int)$req->get_param('per');
    if ($per < 1 || $per > 50) $per = 20;
    $offset = ($page - 1) * $per;

    $sort  = (string)$req->get_param('sort');
    $cols  = 'reg, rtype, ryear, oblast, name, purpose, composition, producer_name, producer_kind, producer_norm, trader_name, trader_kind, trader_norm, trader_inf_norm, trader_inf_name, storage, notif_date, launch_date, deletion, category, flags, flag_count';

    /* При търсене без изрично подреждане: по съвпадение (име > фирма > състав), после по рег. № */
    $rel_args = array(); $rel = '';
    if (in_array($sort, array('', 'rel', 'new'), true)) $rel = babh6_relevance_sql((string)$req->get_param('q'), $rel_args);
    if ($rel !== '') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $cols, $rel AS rel FROM $t WHERE $wsql ORDER BY rel DESC, reg DESC LIMIT %d OFFSET %d",
            array_merge($rel_args, $args, array($per, $offset))
        ));
    } else {
        $order = babh6_order_sql($sort);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $cols FROM $t WHERE $wsql ORDER BY $order LIMIT %d OFFSET %d",
            array_merge($args, array($per, $offset))
        ));
    }

    $items = array_map('babh6_row_to_item', $rows ? $rows : array());

    return rest_ensure_response(array(
        'total' => $total,
        'page'  => $page,
        'per'   => $per,
        'items' => $items,
    ));
}

/* ============ GET /product/{reg} ============ */
function babh6_rest_product($req) {
    global $wpdb;
    $t   = babh6_table('products');
    $reg = sanitize_text_field(urldecode((string)$req['reg']));
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE reg = %s LIMIT 1", $reg));
    if (!$row) return new WP_Error('babh6_notfound', 'Няма намерен запис с този регистрационен номер.', array('status' => 404));
    return rest_ensure_response(babh6_row_to_item($row));
}

/* ============ GET /stats ============ */
function babh6_rest_stats() {
    $cached = get_transient('babh6_stats');
    if (is_array($cached)) return rest_ensure_response($cached);

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
        'producers'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 'p'"),
        'traders'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE kind = 't'"),
        'inferred'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted_at IS NULL AND trader_inf_norm <> ''"),
    );

    $last = $wpdb->get_var("SELECT uploaded_at FROM $ut WHERE status = 'done' ORDER BY id DESC LIMIT 1");
    $out['last_update'] = $last ? date_i18n('d.m.Y', strtotime($last)) : null;

    /* Месечна серия — последните 12 месеца по notif_date */
    $keys = array();
    for ($i = 11; $i >= 0; $i--) $keys[] = gmdate('Y-m', strtotime("-$i months"));
    $from = $keys[0] . '-01';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(notif_date, '%%Y-%%m') AS m, COUNT(*) AS c
         FROM $t WHERE deleted_at IS NULL AND notif_date >= %s
         GROUP BY m", $from
    ));
    $map = array();
    foreach ($rows as $r) $map[$r->m] = (int)$r->c;
    $monthly = array();
    foreach ($keys as $k) $monthly[] = array('m' => $k, 'c' => isset($map[$k]) ? $map[$k] : 0);
    $out['monthly']  = $monthly;
    $out['recent12'] = array_sum(array_map(function ($x) { return $x['c']; }, $monthly));

    /* Категории */
    $cats = array();
    $crows = $wpdb->get_results("SELECT category, COUNT(*) AS c FROM $t WHERE deleted_at IS NULL GROUP BY category ORDER BY c DESC");
    foreach ($crows as $r) {
        $code = $r->category ? $r->category : 'other';
        $cats[] = array('code' => $code, 'label' => babh6_category_label($code), 'count' => (int)$r->c);
    }
    $out['cats'] = $cats;

    $out['years']   = array_map('intval', (array)$wpdb->get_col("SELECT DISTINCT ryear FROM $t WHERE deleted_at IS NULL AND ryear IS NOT NULL ORDER BY ryear DESC"));
    $out['oblasti'] = (array)$wpdb->get_col("SELECT DISTINCT oblast FROM $t WHERE deleted_at IS NULL AND oblast <> '' ORDER BY oblast ASC");

    set_transient('babh6_stats', $out, HOUR_IN_SECONDS);
    return rest_ensure_response($out);
}

/* ============ GET /export — CSV на текущите филтри ============ */
function babh6_rest_export($req) {
    global $wpdb;
    $t = babh6_table('products');

    babh6_build_where($req, $where, $args);
    $wsql  = implode(' AND ', $where);
    $order = babh6_order_sql((string)$req->get_param('sort'));

    $sql  = "SELECT reg, name, producer_name, producer_kind, trader_name, trader_kind, trader_inf_name, notif_date, category, composition, purpose FROM $t WHERE $wsql ORDER BY $order LIMIT 5000";
    $rows = $wpdb->get_results(babh6_maybe_prepare($sql, $args));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="babh-register-export.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    fputcsv($fh, array('Регистрационен №', 'Наименование на продукта', 'Производител', 'Тип на полето „Производител“', 'Търговец', 'Тип на полето „Търговец“', 'Търговец по името на продукта (автоматично, не данни на БАБХ)', 'Дата на уведомление', 'Автоматична категория', 'Бележки за проверка (автоматични съвпадения по дума, не становище)'));
    $kinds = array('firm' => 'фирма', 'country' => 'посочена държава', '' => '');
    foreach ((array)$rows as $r) {
        $flags = implode('; ', array_map(function ($f) { return $f['label'] . ' (в ' . babh6_field_label($f['field']) . ': ' . $f['term'] . ')'; },
            babh6_find_flags_fields($r->name, $r->composition, $r->purpose)));
        fputcsv($fh, array($r->reg, $r->name, $r->producer_name, isset($kinds[$r->producer_kind]) ? $kinds[$r->producer_kind] : $r->producer_kind,
            $r->trader_name, isset($kinds[$r->trader_kind]) ? $kinds[$r->trader_kind] : $r->trader_kind,
            (string)$r->trader_inf_name,
            (string)$r->notif_date, babh6_category_label($r->category ? $r->category : 'other'), $flags));
    }
    fclose($fh);
    exit;
}

/* ============ POST /waitlist ============ */
function babh6_rest_waitlist($req) {
    global $wpdb;

    /* honeypot */
    if ((string)$req->get_param('website') !== '') {
        return rest_ensure_response(array('ok' => 1));
    }

    $email = sanitize_email((string)$req->get_param('email'));
    if (!is_email($email)) {
        return new WP_Error('babh6_email', 'Въведи валиден имейл адрес, например name@example.com.', array('status' => 400));
    }

    /* лек rate limit по IP */
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $key = 'babh6_wl_' . md5($ip);
    $n   = (int)get_transient($key);
    if ($n > 10) return new WP_Error('babh6_rate', 'Направени са твърде много опити. Опитай отново по-късно.', array('status' => 429));
    set_transient($key, $n + 1, HOUR_IN_SECONDS);

    $source = sanitize_key((string)$req->get_param('source'));
    $wt = babh6_table('waitlist');
    $res = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $wt (email, source, created_at) VALUES (%s, %s, %s)",
        $email, $source ? $source : 'pro', current_time('mysql')
    ));
    if ($res === false) {
        return new WP_Error('babh6_db', 'Имейлът не е записан поради технически проблем. Опитай отново.', array('status' => 500));
    }
    /* 0 засегнати реда = имейлът вече е в списъка (UNIQUE email) */
    return rest_ensure_response(array('ok' => 1, 'exists' => ((int)$wpdb->rows_affected === 0) ? 1 : 0));
}

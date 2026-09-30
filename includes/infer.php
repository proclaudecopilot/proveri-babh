<?php
if (!defined('ABSPATH')) exit;

/**
 * Търговец по името на продукта.
 *
 * При вписване в регистъра търговецът често липсва, посочен е като държава или
 * като самия производител. Марката обаче стои в наименованието („АНСА Витамин C“).
 * Тук се учим от ПРАВИЛНО вписаните продукти (с реален търговец, различен от
 * производителя): „марка → търговец“, и попълваме trader_inf_norm / trader_inf_name
 * за продуктите без търговец. Така в профила на производителя клиентът получава
 * и продуктите, направени за неговата марка, но вписани без търговец.
 *
 * Източници на връзката (по приоритет):
 *   1. същият производител вече има продукти с тази марка и посочен търговец;
 *   2. други производители имат продукти с тази марка и един доминиращ търговец
 *      (поне 2 продукта и поне 75% от всички с тази марка);
 *   3. марката съвпада с първата дума от името на търговец в регистъра.
 * Никога не се приписва самият производител. Оригиналното поле не се променя —
 * изводът е отделна колона и се показва като „по името“, не като данни на БАБХ.
 *
 * Изпълнява се при babh6_rebuild_parties() (след всяко качване и при смяна на версията).
 */
function babh6_infer_traders() {
    global $wpdb;
    $t = babh6_table('products');

    $wpdb->query("UPDATE $t SET trader_inf_norm = '', trader_inf_name = '' WHERE trader_inf_norm <> '' OR trader_inf_name <> ''");

    /* 1+2. Учене от продуктите с реален, различен от производителя, търговец */
    $rows = $wpdb->get_results(
        "SELECT name, producer_norm, trader_norm, SUBSTRING_INDEX(trader_name, ',', 1) AS tname
         FROM $t
         WHERE deleted_at IS NULL AND trader_kind = 'firm' AND trader_norm <> '' AND trader_norm <> producer_norm");
    $global = array();   /* brand key → [trader_norm → n] */
    $local  = array();   /* producer|brand key → [trader_norm → n] */
    $tnames = array();   /* trader_norm → показвано име */
    foreach ((array)$rows as $r) {
        $tok = babh6_brand_token($r->name);
        if ($tok === '') continue;
        $key = babh6_translit_bg2lat($tok);
        $tn  = $r->trader_norm;
        if (!isset($tnames[$tn]) || mb_strlen($r->tname, 'UTF-8') > mb_strlen($tnames[$tn], 'UTF-8')) $tnames[$tn] = trim((string)$r->tname);
        if (!isset($global[$key][$tn])) $global[$key][$tn] = 0;
        $global[$key][$tn]++;
        $lk = $r->producer_norm . '|' . $key;
        if (!isset($local[$lk][$tn])) $local[$lk][$tn] = 0;
        $local[$lk][$tn]++;
    }
    $pick = function ($counts, $min_n, $min_share) {
        if (!$counts) return null;
        arsort($counts);
        $total = array_sum($counts);
        $norm  = key($counts);
        $n     = current($counts);
        return ($n >= $min_n && $n / $total >= $min_share) ? $norm : null;
    };
    $global_pick = array();
    foreach ($global as $key => $counts) { $g = $pick($counts, 2, 0.75); if ($g !== null) $global_pick[$key] = $g; }
    $local_pick = array();
    foreach ($local as $lk => $counts) { $g = $pick($counts, 1, 0.6); if ($g !== null) $local_pick[$lk] = $g; }

    /* 3. Търговци, чието име започва с марката */
    $firms = array();
    $trows = $wpdb->get_results(
        "SELECT trader_norm AS norm, SUBSTRING_INDEX(MAX(trader_name), ',', 1) AS name, COUNT(*) AS c
         FROM $t WHERE deleted_at IS NULL AND trader_kind = 'firm' AND trader_norm <> ''
         GROUP BY trader_norm");
    foreach ((array)$trows as $r) {
        $tok = babh6_brand_token($r->norm);
        if ($tok === '') continue;
        $key = babh6_translit_bg2lat($tok);
        if (!isset($firms[$key]) || (int)$r->c > $firms[$key]['c']) $firms[$key] = array('norm' => $r->norm, 'name' => trim((string)$r->name), 'c' => (int)$r->c);
        if (!isset($tnames[$r->norm])) $tnames[$r->norm] = trim((string)$r->name);
    }

    /* Прилагане върху продуктите без реален търговец */
    $rows = $wpdb->get_results(
        "SELECT id, name, producer_norm FROM $t
         WHERE deleted_at IS NULL AND (trader_kind <> 'firm' OR trader_norm = '' OR trader_norm = producer_norm)");
    $assign = array(); /* trader_norm → [ids] */
    $n = 0;
    foreach ((array)$rows as $r) {
        $tok = babh6_brand_token($r->name);
        if ($tok === '') continue;
        $key = babh6_translit_bg2lat($tok);
        /* марката е първата дума на самия производител → собствена марка, не търговец */
        $ptok = babh6_brand_token($r->producer_norm);
        if ($ptok !== '' && babh6_translit_bg2lat($ptok) === $key) continue;

        $norm = null;
        $lk = $r->producer_norm . '|' . $key;
        if (isset($local_pick[$lk])) $norm = $local_pick[$lk];
        elseif (isset($global_pick[$key])) $norm = $global_pick[$key];
        elseif (isset($firms[$key])) $norm = $firms[$key]['norm'];
        if ($norm === null || $norm === '' || $norm === $r->producer_norm) continue;
        $assign[$norm][] = (int)$r->id;
        $n++;
    }
    foreach ($assign as $norm => $ids) {
        $name = isset($tnames[$norm]) ? $tnames[$norm] : $norm;
        foreach (array_chunk($ids, 500) as $chunk) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $t SET trader_inf_norm = %s, trader_inf_name = %s WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ")",
                $norm, mb_substr($name, 0, 490, 'UTF-8')));
        }
    }
    update_option('babh6_inferred', array('count' => $n, 'traders' => count($assign), 'at' => current_time('mysql')), false);
    return $n;
}

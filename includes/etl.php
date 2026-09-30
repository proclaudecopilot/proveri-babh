<?php
if (!defined('ABSPATH')) exit;

/**
 * Chunked ETL: обработва регистъра на порции през AJAX или WP-Cron, за да не
 * удря ограниченията на shared hosting (30-60s max request). Състоянието живее
 * в option 'babh6_job' → при прекъсване продължава от същото място.
 *
 * Един job може да съдържа няколко .xlsx файла (БАБХ публикува регистъра на
 * части) — те се четат последователно, а заличаването на липсващите продукти
 * става чак след последния файл. Така „част 2" не заличава „част 1".
 */

/* ============ Job lifecycle ============ */

/** Създава нова import задача от един качен файл (обратна съвместимост). */
function babh6_job_create($tmp_path, $filename, $source = 'manual') {
    return babh6_job_create_files(array(array('src' => $tmp_path, 'name' => $filename)), $source);
}

/**
 * Създава import задача от един или повече файла.
 * @param array  $files  [['src' => път (качен tmp или локален), 'name' => оригинално име], ...]
 * @param string $source 'manual' | 'auto'
 * @param array  $meta   произволни данни (напр. подписът на линковете при auto sync)
 * @return array|WP_Error
 */
function babh6_job_create_files($files, $source = 'manual', $meta = array()) {
    global $wpdb;
    $files = array_values(array_filter((array)$files, function ($f) { return !empty($f['src']); }));
    if (!$files) return new WP_Error('babh6_nofiles', 'Не е получен файл за обработка.');

    /* Маркирай закъсали стари задачи */
    $uploads_t = babh6_table('uploads');
    $old = get_option('babh6_job');
    if ($old && !empty($old['files'])) foreach ((array)$old['files'] as $f) @unlink($f);
    $wpdb->query("UPDATE $uploads_t SET status = 'failed', notes = 'Обработката е прекратена, защото е започнато ново качване.' WHERE status = 'processing'");
    delete_option('babh6_job');
    delete_transient('babh6_step_lock');

    $dir = wp_upload_dir();
    $base = trailingslashit($dir['basedir']) . 'babh6';
    if (!wp_mkdir_p($base)) {
        return new WP_Error('babh6_dir', 'Не може да се създаде папката за качвания. Провери правата за запис. (' . $base . ')');
    }

    $paths = array(); $names = array(); $stamp = time();
    foreach ($files as $i => $f) {
        $dest = $base . '/import-' . $stamp . '-' . ($i + 1) . '.xlsx';
        $moved = false;
        if (function_exists('is_uploaded_file') && @is_uploaded_file($f['src'])) $moved = @move_uploaded_file($f['src'], $dest);
        if (!$moved) $moved = @rename($f['src'], $dest);
        if (!$moved && @copy($f['src'], $dest)) { @unlink($f['src']); $moved = true; }
        if (!$moved) {
            foreach ($paths as $p) @unlink($p);
            return new WP_Error('babh6_move', 'Файлът не може да бъде записан на сървъра. Провери свободното място и правата за запис. (' . $base . ')');
        }
        $paths[] = $dest;
        $names[] = isset($f['name']) && $f['name'] !== '' ? $f['name'] : basename($dest);
    }
    $filename = implode(' + ', $names);

    $wpdb->insert($uploads_t, array(
        'filename'    => mb_substr($filename, 0, 250, 'UTF-8'),
        'uploaded_at' => current_time('mysql'),
        'status'      => 'processing',
        'source'      => $source === 'auto' ? 'auto' : 'manual',
    ));

    $job = array(
        'upload_id' => (int)$wpdb->insert_id,
        'files'     => $paths,
        'names'     => $names,
        'fi'        => 0,          /* индекс на текущия файл */
        'file'      => $paths[0],  /* текущ файл (обратна съвместимост) */
        'filename'  => $filename,
        'source'    => $source === 'auto' ? 'auto' : 'manual',
        'meta'      => (array)$meta,
        'created'   => time(),
        'totals'    => array(),    /* редове по файл */
        'total'     => 0,          /* сумарно */
        'done_rows' => 0,          /* редове от вече приключилите файлове */
        'cursor'    => 0,          /* ред в текущия файл */
        'parsed'    => 0,
        'added'     => 0,
        'updated'   => 0,
        'restored'  => 0,
    );
    update_option('babh6_job', $job, false);
    return $job;
}

/** Отменя текущата задача. */
function babh6_job_cancel() {
    global $wpdb;
    $job = get_option('babh6_job');
    if ($job) {
        $wpdb->update(babh6_table('uploads'),
            array('status' => 'failed', 'notes' => 'Обработката е спряна от администратор. Вече записаните промени остават.'),
            array('id' => (int)$job['upload_id']));
        foreach (babh6_job_files($job) as $f) @unlink($f);
    }
    delete_option('babh6_job');
    delete_transient('babh6_step_lock');
}

/** Списък с файловете на job-а (поддържа и стария формат с единичен 'file'). */
function babh6_job_files($job) {
    if (!empty($job['files'])) return (array)$job['files'];
    return !empty($job['file']) ? array($job['file']) : array();
}

/** Прекратява job-а с грешка: маркира upload-а, чисти файловете, пуска hook. */
function babh6_job_fail($job, $message) {
    global $wpdb;
    $wpdb->update(babh6_table('uploads'), array('status' => 'failed', 'notes' => $message), array('id' => (int)$job['upload_id']));
    foreach (babh6_job_files($job) as $f) @unlink($f);
    delete_option('babh6_job');
    delete_transient('babh6_step_lock');
    do_action('babh6_import_failed', (int)$job['upload_id'], $message, $job);
}

/**
 * Изпълнява една стъпка от import-а (~1500 реда). Вика се многократно през AJAX
 * или от WP-Cron (auto sync). Lock (transient) пази двата да не работят едновременно.
 * @return array|WP_Error {done, phase, progress, total, added, updated, restored, removed?}
 */
function babh6_run_step() {
    global $wpdb;
    @set_time_limit(120);
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');

    $job = get_option('babh6_job');
    if (!$job) return new WP_Error('babh6_nojob', 'Няма обработка, която да продължим.');

    /* Стар формат (job от версия преди 6.1) */
    if (empty($job['files']) && !empty($job['file'])) {
        $job['files'] = array($job['file']); $job['names'] = array($job['filename']);
        $job['fi'] = 0; $job['done_rows'] = 0; $job['totals'] = array();
        if (!empty($job['total'])) $job['totals'] = array((int)$job['total']);
    }
    foreach (array('fi' => 0, 'done_rows' => 0, 'totals' => array(), 'source' => 'manual', 'meta' => array(), 'names' => array()) as $k => $v) {
        if (!isset($job[$k])) $job[$k] = $v;
    }

    /* Lock срещу паралелна обработка (AJAX + cron) */
    if (get_transient('babh6_step_lock')) {
        return array('done' => false, 'phase' => 'busy',
            'progress' => (int)$job['done_rows'] + (int)$job['cursor'], 'total' => (int)$job['total'],
            'added' => (int)$job['added'], 'updated' => (int)$job['updated'], 'restored' => (int)$job['restored']);
    }
    set_transient('babh6_step_lock', 1, 2 * MINUTE_IN_SECONDS);

    $res = babh6_run_step_locked($job);
    delete_transient('babh6_step_lock');
    return $res;
}

function babh6_run_step_locked($job) {
    global $wpdb;
    $products_t = babh6_table('products');
    $uploads_t  = babh6_table('uploads');
    $upload_id  = (int)$job['upload_id'];
    $files      = (array)$job['files'];
    $nfiles     = count($files);

    foreach ($files as $f) {
        if (empty($f) || !file_exists($f)) {
            babh6_job_fail($job, 'Файлът за обработка липсва на сървъра.');
            return new WP_Error('babh6_nofile', 'Файлът за обработка липсва. Качи го отново.');
        }
    }

    /* Фаза 1: преброяване на всички файлове (една отделна бърза стъпка за progress bar) */
    if (empty($job['total'])) {
        $totals = array(); $sum = 0;
        foreach ($files as $f) {
            $total = BABH6_XLSX_Reader::count_data_rows($f);
            if (is_wp_error($total)) {
                babh6_job_fail($job, $total->get_error_message() . ' (' . basename($f) . ')');
                return $total;
            }
            $totals[] = (int)$total; $sum += (int)$total;
        }
        $job['totals'] = $totals;
        $job['total']  = max(1, $sum);
        update_option('babh6_job', $job, false);
        return array('done' => false, 'phase' => 'count', 'progress' => 0, 'total' => $job['total'],
                     'added' => 0, 'updated' => 0, 'restored' => 0);
    }

    /* Фаза 2: порция редове от текущия файл */
    $fi    = (int)$job['fi'];
    $file  = $files[$fi];
    $chunk = (int)apply_filters('babh6_chunk_size', 1500);
    $rows = BABH6_XLSX_Reader::read_rows_chunk($file, (int)$job['cursor'], $chunk);
    if (is_wp_error($rows)) {
        babh6_job_fail($job, $rows->get_error_message() . ' (' . basename($file) . ')');
        return $rows;
    }

    $now = current_time('mysql');

    if ($rows) {
        /* Нормализирай; дубликати в порцията — първият печели */
        $items = array();
        foreach ($rows as $row) {
            $p = babh6_normalize_row($row);
            if ($p === null) continue;
            if (!isset($items[$p['reg']])) $items[$p['reg']] = $p;
        }

        if ($items) {
            $regs = array_keys($items);
            $ph = implode(',', array_fill(0, count($regs), '%s'));
            $existing = $wpdb->get_results($wpdb->prepare(
                "SELECT id, reg, comp_hash, deleted_at, last_upload FROM $products_t WHERE reg IN ($ph)", $regs
            ));
            $map = array();
            foreach ($existing as $e) $map[$e->reg] = $e;

            $to_insert = array();
            $to_bump   = array();

            foreach ($items as $reg => $p) {
                if (!isset($map[$reg])) {
                    $to_insert[] = $p;
                    $job['parsed']++;
                    $job['added']++;
                    continue;
                }
                $e = $map[$reg];
                /* Същият рег. номер вече мина в този import (напр. дублиран в част 1 и част 2) — първият печели */
                if ((int)$e->last_upload === $upload_id) continue;
                $job['parsed']++;
                $changed     = ($e->comp_hash !== $p['comp_hash']);
                $was_deleted = !empty($e->deleted_at);
                if ($changed || $was_deleted) {
                    $wpdb->update($products_t, array(
                        'rtype' => $p['rtype'], 'ryear' => $p['ryear'], 'oblast' => $p['oblast'],
                        'name' => $p['name'], 'purpose' => $p['purpose'], 'composition' => $p['composition'],
                        'comp_hash' => $p['comp_hash'],
                        'producer_name' => $p['producer_name'], 'producer_norm' => $p['producer_norm'], 'producer_kind' => $p['producer_kind'],
                        'trader_name' => $p['trader_name'], 'trader_norm' => $p['trader_norm'], 'trader_kind' => $p['trader_kind'],
                        'storage' => $p['storage'], 'notif_no' => $p['notif_no'],
                        'notif_date' => $p['notif_date'], 'launch_date' => $p['launch_date'], 'entry_date' => $p['entry_date'],
                        'deletion' => $p['deletion'], 'category' => $p['category'],
                        'flags' => $p['flags'], 'flag_count' => $p['flag_count'],
                        'last_upload' => $upload_id, 'deleted_at' => null, 'updated_at' => $now,
                    ), array('id' => $e->id));
                    if ($was_deleted) $job['restored']++;
                    elseif ($changed) $job['updated']++;
                } else {
                    $to_bump[] = (int)$e->id;
                }
            }

            foreach (array_chunk($to_insert, 200) as $b) {
                babh6_insert_batch($b, $upload_id, $now);
            }
            foreach (array_chunk($to_bump, 500) as $b) {
                $ids = implode(',', $b);
                $wpdb->query($wpdb->prepare("UPDATE $products_t SET last_upload = %d WHERE id IN ($ids)", $upload_id));
            }
        }

        $job['cursor'] += count($rows);
        update_option('babh6_job', $job, false);
    }

    $file_done = (!$rows || count($rows) < $chunk);

    /* Следващ файл (част 2, 3, …) */
    if ($file_done && $fi + 1 < $nfiles) {
        $job['done_rows'] = (int)$job['done_rows'] + (int)$job['cursor'];
        $job['fi']        = $fi + 1;
        $job['file']      = $files[$fi + 1];
        $job['cursor']    = 0;
        update_option('babh6_job', $job, false);
        return array('done' => false, 'phase' => 'rows', 'file' => $fi + 2, 'files' => $nfiles,
            'progress' => (int)$job['done_rows'], 'total' => (int)$job['total'],
            'added' => (int)$job['added'], 'updated' => (int)$job['updated'], 'restored' => (int)$job['restored']);
    }

    /* Фаза 3: финализиране след последния файл */
    if ($file_done) {
        $removed = 0; $notes = null; $removed_checked = false;
        if ((int)$job['parsed'] > 100) {
            $removed_checked = true;
            /* Предпазител: ако липсват над X% от активните продукти, файловете
               най-вероятно са непълни (напр. само част 1) — не заличаваме. */
            $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL");
            $would  = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL AND (last_upload IS NULL OR last_upload <> %d)", $upload_id));
            $ratio  = (float)apply_filters('babh6_max_remove_ratio', 0.5);
            if ($active > 0 && $would > $active * $ratio) {
                $notes = sprintf('Проверката за липсващи записи е пропусната: %d от %d записа липсват в качените файлове (над %d%%). Вероятно файловете не са пълни.',
                    $would, $active, (int)round($ratio * 100));
                $removed_checked = false;
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $products_t SET deleted_at = %s
                     WHERE deleted_at IS NULL AND (last_upload IS NULL OR last_upload <> %d)",
                    $now, $upload_id
                ));
                $removed = (int)$wpdb->rows_affected;
            }
        }

        babh6_rebuild_parties();
        delete_transient('babh6_stats');

        $wpdb->update($uploads_t, array(
            'status'     => 'done',
            'row_count'  => (int)$job['parsed'],
            'added'      => (int)$job['added'],
            'updated_ct' => (int)$job['updated'],
            'removed'    => $removed,
            'restored'   => (int)$job['restored'],
            'notes'      => $notes,
        ), array('id' => $upload_id));

        foreach ($files as $f) @unlink($f);
        delete_option('babh6_job');

        if (!$removed_checked && $notes === null) $notes = 'Проверка за липсващи записи не е извършена (под 100 обработени записа).';
        $result = array('done' => true, 'phase' => 'done', 'notes' => $notes, 'removed_checked' => $removed_checked,
            'progress' => (int)$job['total'], 'total' => (int)$job['total'],
            'parsed' => (int)$job['parsed'], 'added' => (int)$job['added'],
            'updated' => (int)$job['updated'], 'removed' => $removed, 'restored' => (int)$job['restored']);
        do_action('babh6_import_done', $upload_id, $result, $job);
        return $result;
    }

    return array('done' => false, 'phase' => 'rows', 'file' => $fi + 1, 'files' => $nfiles,
        'progress' => (int)$job['done_rows'] + (int)$job['cursor'], 'total' => (int)$job['total'],
        'added' => (int)$job['added'], 'updated' => (int)$job['updated'], 'restored' => (int)$job['restored']);
}

/* ============ Row normalize + insert (без промяна от s1) ============ */

function babh6_normalize_row($row) {
    $reg_info = babh6_parse_reg(isset($row[0]) ? $row[0] : '');
    if ($reg_info === null) return null;

    $name = preg_replace('/\s+/u', ' ', trim((string)(isset($row[9]) ? $row[9] : '')));
    if ($name === '') return null;

    $producer_raw = trim((string)(isset($row[4]) ? $row[4] : ''));
    $trader_raw   = trim((string)(isset($row[7]) ? $row[7] : ''));
    $composition  = preg_replace('/\s+/u', ' ', trim((string)(isset($row[11]) ? $row[11] : '')));
    $purpose      = preg_replace('/\s+/u', ' ', trim((string)(isset($row[10]) ? $row[10] : '')));
    $storage      = trim((string)(isset($row[8]) ? $row[8] : ''));
    $deletion     = trim((string)(isset($row[13]) ? $row[13] : ''));

    $flags = babh6_find_flags_fields($name, $composition, $purpose);

    return array(
        'reg'           => $reg_info['reg'],
        'rtype'         => $reg_info['rtype'],
        'ryear'         => $reg_info['ryear'],
        'oblast'        => $reg_info['oblast'],
        'name'          => $name,
        'purpose'       => mb_substr($purpose, 0, 2000, 'UTF-8'),
        'composition'   => mb_substr($composition, 0, 5000, 'UTF-8'),
        'comp_hash'     => md5($composition . '|' . $name),
        'producer_name' => mb_substr($producer_raw, 0, 490, 'UTF-8'),
        'producer_norm' => babh6_norm_firm($producer_raw),
        'producer_kind' => $producer_raw === '' ? '' : (babh6_is_country($producer_raw) ? 'country' : 'firm'),
        'trader_name'   => mb_substr($trader_raw, 0, 490, 'UTF-8'),
        'trader_norm'   => babh6_norm_firm($trader_raw),
        'trader_kind'   => $trader_raw === '' ? '' : (babh6_is_country($trader_raw) ? 'country' : 'firm'),
        'storage'       => mb_substr($storage, 0, 2000, 'UTF-8'),
        'notif_no'      => mb_substr(trim((string)(isset($row[2]) ? $row[2] : '')), 0, 95, 'UTF-8'),
        'notif_date'    => babh6_parse_date_any(isset($row[3]) ? $row[3] : ''),
        'launch_date'   => babh6_parse_date_any(isset($row[12]) ? $row[12] : ''),
        'entry_date'    => babh6_parse_date_any(isset($row[1]) ? $row[1] : ''),
        'deletion'      => mb_substr($deletion, 0, 2000, 'UTF-8'),
        'category'      => babh6_categorize($name, $composition),
        'flags'         => $flags ? wp_json_encode($flags, JSON_UNESCAPED_UNICODE) : null,
        'flag_count'    => count($flags),
    );
}

function babh6_insert_batch($batch, $upload_id, $now) {
    global $wpdb;
    $t = babh6_table('products');
    $rows_sql = array();
    foreach ($batch as $p) {
        $rows_sql[] = '(' . implode(',', array(
            $wpdb->prepare('%s', $p['reg']),
            $wpdb->prepare('%s', $p['rtype']),
            $p['ryear'] ? intval($p['ryear']) : 'NULL',
            $wpdb->prepare('%s', $p['oblast']),
            $wpdb->prepare('%s', $p['name']),
            $wpdb->prepare('%s', $p['purpose']),
            $wpdb->prepare('%s', $p['composition']),
            $wpdb->prepare('%s', $p['comp_hash']),
            $wpdb->prepare('%s', $p['producer_name']),
            $wpdb->prepare('%s', $p['producer_norm']),
            $wpdb->prepare('%s', $p['producer_kind']),
            $wpdb->prepare('%s', $p['trader_name']),
            $wpdb->prepare('%s', $p['trader_norm']),
            $wpdb->prepare('%s', $p['trader_kind']),
            $wpdb->prepare('%s', $p['storage']),
            $wpdb->prepare('%s', $p['notif_no']),
            $p['notif_date'] ? $wpdb->prepare('%s', $p['notif_date']) : 'NULL',
            $p['launch_date'] ? $wpdb->prepare('%s', $p['launch_date']) : 'NULL',
            $p['entry_date'] ? $wpdb->prepare('%s', $p['entry_date']) : 'NULL',
            $wpdb->prepare('%s', $p['deletion']),
            $wpdb->prepare('%s', $p['category']),
            $p['flags'] !== null ? $wpdb->prepare('%s', $p['flags']) : 'NULL',
            intval($p['flag_count']),
            intval($upload_id),
            intval($upload_id),
            $wpdb->prepare('%s', $now),
            $wpdb->prepare('%s', $now),
        )) . ')';
    }
    $cols = 'reg,rtype,ryear,oblast,name,purpose,composition,comp_hash,producer_name,producer_norm,producer_kind,trader_name,trader_norm,trader_kind,storage,notif_no,notif_date,launch_date,entry_date,deletion,category,flags,flag_count,first_upload,last_upload,created_at,updated_at';
    $wpdb->query("INSERT INTO $t ($cols) VALUES " . implode(',', $rows_sql));
}

function babh6_rebuild_parties() {
    global $wpdb;
    $products_t = babh6_table('products');
    $parties_t  = babh6_table('parties');
    $now = current_time('mysql');

    /* Първо: търговец по името на продукта (includes/infer.php), за да влезе в броенето */
    if (function_exists('babh6_infer_traders')) babh6_infer_traders();

    $wpdb->query("TRUNCATE TABLE $parties_t");

    /* Ефективен търговец: посоченият в регистъра, а ако липсва — определеният по името */
    $eff_norm = "IF(trader_inf_norm <> '', trader_inf_norm, trader_norm)";
    $eff_name = "IF(trader_inf_norm <> '', trader_inf_name, trader_name)";
    $eff_ok   = "(trader_inf_norm <> '' OR (trader_kind = 'firm' AND trader_norm <> ''))";
    $longest  = function ($col) { return "SUBSTRING(MAX(CONCAT(LPAD(CHAR_LENGTH($col), 5, '0'), $col)), 6)"; };

    $kinds = array(
        'p' => array(
            'norm'     => 'producer_norm',
            'name'     => 'producer_name',
            'where'    => "producer_kind = 'firm' AND producer_norm <> ''",
            'partners' => "COUNT(DISTINCT CASE WHEN $eff_ok AND $eff_norm <> '' AND $eff_norm <> producer_norm THEN $eff_norm END)",
        ),
        't' => array(
            'norm'     => $eff_norm,
            'name'     => $eff_name,
            'where'    => $eff_ok . " AND $eff_norm <> ''",
            'partners' => "COUNT(DISTINCT CASE WHEN producer_kind = 'firm' AND producer_norm <> '' AND producer_norm <> $eff_norm THEN producer_norm END)",
        ),
    );
    foreach ($kinds as $kind => $k) {
        $full = $longest($k['name']);
        $agg = $wpdb->get_results(
            "SELECT {$k['norm']} AS norm,
                    SUBSTRING_INDEX(MAX({$k['name']}), ',', 1) AS name,
                    $full AS full_name,
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN flag_count > 0 THEN 1 ELSE 0 END) AS flagged,
                    {$k['partners']} AS partners,
                    SUM(CASE WHEN trader_inf_norm <> '' THEN 1 ELSE 0 END) AS inferred,
                    MIN(ryear) AS y1, MAX(ryear) AS y2
             FROM $products_t
             WHERE {$k['where']} AND deleted_at IS NULL
             GROUP BY {$k['norm']}"
        );
        $batch = array();
        foreach ((array)$agg as $a) {
            $batch[] = '(' . implode(',', array(
                $wpdb->prepare('%s', $kind),
                $wpdb->prepare('%s', $a->norm),
                $wpdb->prepare('%s', trim((string)$a->name)),
                babh6_is_bg_firm((string)$a->full_name) ? 1 : 0,
                intval($a->cnt),
                intval($a->flagged),
                intval($a->partners),
                intval($a->inferred),
                $a->y1 ? intval($a->y1) : 'NULL',
                $a->y2 ? intval($a->y2) : 'NULL',
                $wpdb->prepare('%s', $now),
            )) . ')';
            if (count($batch) >= 300) {
                $wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,partner_count,inferred_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch));
                $batch = array();
            }
        }
        if ($batch) {
            $wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,partner_count,inferred_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch));
        }
    }
}

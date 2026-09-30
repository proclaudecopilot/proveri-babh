<?php
if (!defined('ABSPATH')) exit;

/**
 * Chunked ETL: обработва регистъра на порции през AJAX, за да не удря
 * ограниченията на shared hosting (30-60s max request). Състоянието живее
 * в option 'babh6_job' → при прекъсване продължава от същото място.
 */

/* ============ Job lifecycle ============ */

/** Създава нова import задача от качен файл. Връща job масив или WP_Error. */
function babh6_job_create($tmp_path, $filename) {
    global $wpdb;

    /* Маркирай закъсали стари задачи */
    $uploads_t = babh6_table('uploads');
    $wpdb->query("UPDATE $uploads_t SET status = 'failed', notes = 'Прекъснат — заменен от нов import' WHERE status = 'processing'");
    delete_option('babh6_job');

    $dir = wp_upload_dir();
    $base = trailingslashit($dir['basedir']) . 'babh6';
    if (!wp_mkdir_p($base)) {
        return new WP_Error('babh6_dir', 'Не мога да създам папка ' . $base);
    }
    $dest = $base . '/import-' . time() . '.xlsx';
    if (!@move_uploaded_file($tmp_path, $dest)) {
        if (!@copy($tmp_path, $dest)) {
            return new WP_Error('babh6_move', 'Не мога да запиша файла в ' . $base);
        }
    }

    $wpdb->insert($uploads_t, array(
        'filename'    => $filename,
        'uploaded_at' => current_time('mysql'),
        'status'      => 'processing',
    ));

    $job = array(
        'upload_id' => (int)$wpdb->insert_id,
        'file'      => $dest,
        'filename'  => $filename,
        'total'     => 0,
        'cursor'    => 0,
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
            array('status' => 'failed', 'notes' => 'Отменен от потребителя'),
            array('id' => (int)$job['upload_id']));
        if (!empty($job['file'])) @unlink($job['file']);
    }
    delete_option('babh6_job');
}

/**
 * Изпълнява една стъпка от import-а (~1500 реда). Вика се многократно през AJAX.
 * @return array|WP_Error {done, phase, progress, total, added, updated, restored, removed?}
 */
function babh6_run_step() {
    global $wpdb;
    @set_time_limit(120);
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');

    $job = get_option('babh6_job');
    if (!$job) return new WP_Error('babh6_nojob', 'Няма активна import задача.');

    $products_t = babh6_table('products');
    $uploads_t  = babh6_table('uploads');
    $upload_id  = (int)$job['upload_id'];

    if (empty($job['file']) || !file_exists($job['file'])) {
        $wpdb->update($uploads_t, array('status' => 'failed', 'notes' => 'Файлът липсва'), array('id' => $upload_id));
        delete_option('babh6_job');
        return new WP_Error('babh6_nofile', 'Import файлът липсва — качи отново.');
    }

    /* Фаза 1: преброяване (една отделна бърза стъпка за progress bar) */
    if (empty($job['total'])) {
        $total = BABH6_XLSX_Reader::count_data_rows($job['file']);
        if (is_wp_error($total)) {
            $wpdb->update($uploads_t, array('status' => 'failed', 'notes' => $total->get_error_message()), array('id' => $upload_id));
            delete_option('babh6_job');
            return $total;
        }
        $job['total'] = max(1, (int)$total);
        update_option('babh6_job', $job, false);
        return array('done' => false, 'phase' => 'count', 'progress' => 0, 'total' => $job['total'],
                     'added' => 0, 'updated' => 0, 'restored' => 0);
    }

    /* Фаза 2: порция редове */
    $chunk = (int)apply_filters('babh6_chunk_size', 1500);
    $rows = BABH6_XLSX_Reader::read_rows_chunk($job['file'], (int)$job['cursor'], $chunk);
    if (is_wp_error($rows)) {
        $wpdb->update($uploads_t, array('status' => 'failed', 'notes' => $rows->get_error_message()), array('id' => $upload_id));
        delete_option('babh6_job');
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
                "SELECT id, reg, comp_hash, deleted_at FROM $products_t WHERE reg IN ($ph)", $regs
            ));
            $map = array();
            foreach ($existing as $e) $map[$e->reg] = $e;

            $to_insert = array();
            $to_bump   = array();

            foreach ($items as $reg => $p) {
                $job['parsed']++;
                if (!isset($map[$reg])) {
                    $to_insert[] = $p;
                    $job['added']++;
                    continue;
                }
                $e = $map[$reg];
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

    /* Фаза 3: финализиране, когато редовете свършат */
    if (!$rows || count($rows) < $chunk) {
        $removed = 0;
        if ((int)$job['parsed'] > 100) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $products_t SET deleted_at = %s
                 WHERE deleted_at IS NULL AND (last_upload IS NULL OR last_upload <> %d)",
                $now, $upload_id
            ));
            $removed = (int)$wpdb->rows_affected;
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
        ), array('id' => $upload_id));

        @unlink($job['file']);
        delete_option('babh6_job');

        return array('done' => true, 'phase' => 'done',
            'progress' => (int)$job['cursor'], 'total' => (int)$job['total'],
            'parsed' => (int)$job['parsed'], 'added' => (int)$job['added'],
            'updated' => (int)$job['updated'], 'removed' => $removed, 'restored' => (int)$job['restored']);
    }

    return array('done' => false, 'phase' => 'rows',
        'progress' => (int)$job['cursor'], 'total' => (int)$job['total'],
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

    $flags = babh6_find_flags($name . ' ' . $composition . ' ' . $purpose);

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

    $wpdb->query("TRUNCATE TABLE $parties_t");

    foreach (array('p' => array('producer_norm', 'producer_name', 'producer_kind'),
                   't' => array('trader_norm', 'trader_name', 'trader_kind')) as $kind => $cols) {
        list($norm_col, $name_col, $kind_col) = $cols;
        $agg = $wpdb->get_results(
            "SELECT $norm_col AS norm,
                    SUBSTRING_INDEX(MAX($name_col), ',', 1) AS name,
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN flag_count > 0 THEN 1 ELSE 0 END) AS flagged,
                    MIN(ryear) AS y1, MAX(ryear) AS y2
             FROM $products_t
             WHERE $kind_col = 'firm' AND $norm_col <> '' AND deleted_at IS NULL
             GROUP BY $norm_col"
        );
        $batch = array();
        foreach ($agg as $a) {
            $batch[] = '(' . implode(',', array(
                $wpdb->prepare('%s', $kind),
                $wpdb->prepare('%s', $a->norm),
                $wpdb->prepare('%s', trim((string)$a->name)),
                babh6_is_bg_firm((string)$a->name) ? 1 : 0,
                intval($a->cnt),
                intval($a->flagged),
                $a->y1 ? intval($a->y1) : 'NULL',
                $a->y2 ? intval($a->y2) : 'NULL',
                $wpdb->prepare('%s', $now),
            )) . ')';
            if (count($batch) >= 300) {
                $wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch));
                $batch = array();
            }
        }
        if ($batch) {
            $wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch));
        }
    }
}

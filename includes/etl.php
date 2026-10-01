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

/* ============ Атомарни заключвания (IM-05, SY-04) ============
 * Transient „прочети, после запиши“ не е атомарно. Тук lock-ът е ред в wp_options
 * (уникален option_name): INSERT IGNORE успява само за един процес; изтекъл lock
 * се поема с условен UPDATE върху старата стойност, така че пак само един печели.
 */
function babh6_lock_acquire($name, $ttl) {
    global $wpdb;
    $opt = 'babh6_lock_' . $name; $now = time();
    $r = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, (string)($now + (int)$ttl)));
    if ($r) return true;
    $exp = (int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
    if ($exp > 0 && $exp < $now) {
        $r = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string)($now + (int)$ttl), $opt, (string)$exp));
        return (bool)$r;
    }
    return false;
}
function babh6_lock_refresh($name, $ttl) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string)(time() + (int)$ttl), 'babh6_lock_' . $name));
}
function babh6_lock_release($name) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'babh6_lock_' . $name));
    wp_cache_delete('babh6_lock_' . $name, 'options');
}
/** Активен ли е lock-ът (не изтекъл). */
function babh6_lock_held($name) {
    global $wpdb;
    $exp = (int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'babh6_lock_' . $name));
    return $exp > time();
}

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
    if ($old) {
        /* Стара задача, която още работи, не се прекъсва мълчаливо (IM-05) */
        if (babh6_lock_held('step')) return new WP_Error('babh6_busy', 'В момента тече друга обработка. Изчакай да приключи или я спри от таблото.');
        foreach (babh6_job_files($old) as $f) @unlink($f);
        $wpdb->update($uploads_t, array('status' => 'cancelled', 'notes' => 'Обработката е прекратена, защото е започнато ново качване.'), array('id' => (int)$old['upload_id']));
        do_action('babh6_import_cancelled', (int)$old['upload_id'], $old);
    }
    $wpdb->query("UPDATE $uploads_t SET status = 'failed', notes = 'Обработката не е приключила (прекъсната преди ново качване).' WHERE status = 'processing'");
    delete_option('babh6_job');
    delete_option('babh6_job_cancel');
    babh6_lock_release('step');

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
        'skipped'   => 0,          /* редове без валиден рег. № / наименование (IM-07) */
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
    /* Флаг за отказ: работник, който още изпълнява стъпка, го проверява преди да запише checkpoint */
    update_option('babh6_job_cancel', time(), false);
    if ($job) {
        $wpdb->update(babh6_table('uploads'),
            array('status' => 'cancelled', 'notes' => 'Обработката е спряна от администратор. Вече записаните промени остават; публикуваната дата на обновяване не е променена.'),
            array('id' => (int)$job['upload_id']));
        foreach (babh6_job_files($job) as $f) @unlink($f);
        do_action('babh6_import_cancelled', (int)$job['upload_id'], $job);
    }
    delete_option('babh6_job');
    babh6_lock_release('step');
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
    babh6_lock_release('step');
    do_action('babh6_import_failed', (int)$job['upload_id'], $message, $job);
}

/** Записва checkpoint само ако задачата не е отменена междувременно (IM-05). */
function babh6_job_checkpoint($job) {
    if (get_option('babh6_job_cancel')) return false;
    update_option('babh6_job', $job, false);
    return true;
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
    foreach (array('fi' => 0, 'done_rows' => 0, 'totals' => array(), 'source' => 'manual', 'meta' => array(), 'names' => array(), 'skipped' => 0) as $k => $v) {
        if (!isset($job[$k])) $job[$k] = $v;
    }

    /* Атомарен lock срещу паралелна обработка (AJAX + cron + loopback) */
    if (!babh6_lock_acquire('step', 3 * MINUTE_IN_SECONDS)) {
        return array('done' => false, 'phase' => 'busy',
            'progress' => (int)$job['done_rows'] + (int)$job['cursor'], 'total' => (int)$job['total'],
            'added' => (int)$job['added'], 'updated' => (int)$job['updated'], 'restored' => (int)$job['restored']);
    }

    $res = babh6_run_step_locked($job);
    babh6_lock_release('step');
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
            /* Структурата се проверява, не се предполага (IM-06): сред първите редове трябва да има
               валиден регистрационен номер в колона A и наименование в колона J. */
            $probe = BABH6_XLSX_Reader::read_rows_chunk($f, 0, 40);
            if (is_wp_error($probe)) { babh6_job_fail($job, $probe->get_error_message() . ' (' . basename($f) . ')'); return $probe; }
            $valid = 0;
            foreach ((array)$probe as $row) { if (babh6_normalize_row($row) !== null) $valid++; }
            if ($valid === 0) {
                $e = new WP_Error('babh6_format', 'Файлът „' . basename($f) . '“ не изглежда като регистъра на БАБХ: в първите 40 реда няма ред с валиден регистрационен номер (колона A) и наименование (колона J). Обработката не е започната.');
                babh6_job_fail($job, $e->get_error_message());
                return $e;
            }
        }
        $job['totals'] = $totals;
        $job['total']  = max(1, $sum);
        if (!babh6_job_checkpoint($job)) return new WP_Error('babh6_cancelled', 'Обработката е спряна.');
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
            if ($p === null) {
                /* заглавен/празен ред не се брои; ред с текст в колона A без валиден № — да (IM-07) */
                if (trim((string)(isset($row[0]) ? $row[0] : '')) !== '' && preg_match('/\d{5,}/u', (string)$row[0])) $job['skipped']++;
                continue;
            }
            if (!isset($items[$p['reg']])) $items[$p['reg']] = $p;
        }

        if ($items) {
            $regs = array_keys($items);
            $ph = implode(',', array_fill(0, count($regs), '%s'));
            $existing = $wpdb->get_results($wpdb->prepare(
                "SELECT id, reg, comp_hash, src_hash, deleted_at, last_upload FROM $products_t WHERE reg IN ($ph)", $regs
            ));
            if ($existing === null || $wpdb->last_error) {
                babh6_job_fail($job, 'Грешка в базата данни при четене на записите: ' . $wpdb->last_error);
                return new WP_Error('babh6_db', 'Грешка в базата данни при четене на записите.');
            }
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
                /* Всяко източниково поле участва в сравнението (IM-01): промяна във фирма, дата,
                   предназначение, съхранение или бележка за заличаване също се записва. */
                $changed     = ((string)$e->src_hash !== $p['src_hash']);
                $was_deleted = !empty($e->deleted_at);
                if ($changed || $was_deleted) {
                    $ok = $wpdb->update($products_t, array(
                        'rtype' => $p['rtype'], 'ryear' => $p['ryear'], 'oblast' => $p['oblast'],
                        'name' => $p['name'], 'purpose' => $p['purpose'], 'composition' => $p['composition'],
                        'comp_hash' => $p['comp_hash'], 'src_hash' => $p['src_hash'],
                        'producer_name' => $p['producer_name'], 'producer_norm' => $p['producer_norm'], 'producer_kind' => $p['producer_kind'],
                        'trader_name' => $p['trader_name'], 'trader_norm' => $p['trader_norm'], 'trader_kind' => $p['trader_kind'],
                        'storage' => $p['storage'], 'notif_no' => $p['notif_no'],
                        'notif_date' => $p['notif_date'], 'launch_date' => $p['launch_date'], 'entry_date' => $p['entry_date'],
                        'deletion' => $p['deletion'], 'category' => $p['category'],
                        'flags' => $p['flags'], 'flag_count' => $p['flag_count'],
                        'last_upload' => $upload_id, 'deleted_at' => null, 'updated_at' => $now,
                    ), array('id' => $e->id));
                    if ($ok === false) {
                        babh6_job_fail($job, 'Грешка в базата данни при обновяване на запис ' . $reg . ' (' . basename($file) . ', ред ~' . ((int)$job['cursor'] + 1) . '): ' . $wpdb->last_error);
                        return new WP_Error('babh6_db', 'Грешка в базата данни при обновяване на запис ' . $reg . '.');
                    }
                    if ($was_deleted) $job['restored']++;
                    elseif ($changed) $job['updated']++;
                } else {
                    $to_bump[] = (int)$e->id;
                }
            }

            foreach (array_chunk($to_insert, 200) as $b) {
                if (!babh6_insert_batch($b, $upload_id, $now)) {
                    babh6_job_fail($job, 'Грешка в базата данни при запис на нови записи (' . basename($file) . ', ред ~' . ((int)$job['cursor'] + 1) . '): ' . $wpdb->last_error);
                    return new WP_Error('babh6_db', 'Грешка в базата данни при запис на нови записи.');
                }
            }
            foreach (array_chunk($to_bump, 500) as $b) {
                $ids = implode(',', $b);
                if ($wpdb->query($wpdb->prepare("UPDATE $products_t SET last_upload = %d WHERE id IN ($ids)", $upload_id)) === false) {
                    babh6_job_fail($job, 'Грешка в базата данни при потвърждаване на записи: ' . $wpdb->last_error);
                    return new WP_Error('babh6_db', 'Грешка в базата данни при потвърждаване на записи.');
                }
            }
        }

        $job['cursor'] += count($rows);
        if (!babh6_job_checkpoint($job)) return new WP_Error('babh6_cancelled', 'Обработката е спряна.');
    }

    $file_done = (!$rows || count($rows) < $chunk);

    /* Следващ файл (част 2, 3, …) */
    if ($file_done && $fi + 1 < $nfiles) {
        $job['done_rows'] = (int)$job['done_rows'] + (int)$job['cursor'];
        $job['fi']        = $fi + 1;
        $job['file']      = $files[$fi + 1];
        $job['cursor']    = 0;
        if (!babh6_job_checkpoint($job)) return new WP_Error('babh6_cancelled', 'Обработката е спряна.');
        return array('done' => false, 'phase' => 'rows', 'file' => $fi + 2, 'files' => $nfiles,
            'progress' => (int)$job['done_rows'], 'total' => (int)$job['total'],
            'added' => (int)$job['added'], 'updated' => (int)$job['updated'], 'restored' => (int)$job['restored']);
    }

    /* Фаза 3: финализиране след последния файл */
    if ($file_done) {
        /* Нула валидни записа не е успешен импорт (IM-09) */
        if ((int)$job['parsed'] === 0) {
            $e = new WP_Error('babh6_empty', 'Във файловете няма нито един валиден запис (пропуснати редове: ' . (int)$job['skipped'] . '). Регистърът не е променен.');
            babh6_job_fail($job, $e->get_error_message());
            return $e;
        }
        $removed = 0; $notes = array(); $removed_checked = false;
        if ((int)$job['parsed'] > 100) {
            $removed_checked = true;
            /* Предпазител: ако липсват X% или повече от активните продукти, файловете
               най-вероятно са непълни (напр. само част 1) — не отбелязваме липси (IM-03). */
            $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL");
            $would  = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL AND (last_upload IS NULL OR last_upload <> %d)", $upload_id));
            $ratio  = (float)apply_filters('babh6_max_remove_ratio', 0.5);
            if ($active > 0 && $would >= $active * $ratio) {
                $notes[] = sprintf('Проверката за липсващи записи е пропусната: %d от %d записа липсват в качените файлове (%d%% или повече). Вероятно файловете не са пълни; версията не е приета за пълна.',
                    $would, $active, (int)round($ratio * 100));
                $removed_checked = false;
            } else {
                $r = $wpdb->query($wpdb->prepare(
                    "UPDATE $products_t SET deleted_at = %s
                     WHERE deleted_at IS NULL AND (last_upload IS NULL OR last_upload <> %d)",
                    $now, $upload_id
                ));
                if ($r === false) {
                    babh6_job_fail($job, 'Грешка в базата данни при отбелязване на липсващите записи: ' . $wpdb->last_error);
                    return new WP_Error('babh6_db', 'Грешка в базата данни при отбелязване на липсващите записи.');
                }
                $removed = (int)$r;
            }
        } else {
            $notes[] = 'Проверка за липсващи записи не е извършена (под 100 обработени записа); версията не е приета за пълна.';
        }
        if ((int)$job['skipped'] > 0) $notes[] = 'Пропуснати редове без валиден рег. № или наименование: ' . (int)$job['skipped'] . '.';

        $ok = babh6_rebuild_parties();
        if ($ok === false) {
            babh6_job_fail($job, 'Грешка при преизчисляване на фирмите: ' . $wpdb->last_error);
            return new WP_Error('babh6_db', 'Грешка при преизчисляване на фирмите.');
        }
        delete_transient('babh6_stats');

        $notes_s = $notes ? implode(' ', $notes) : null;
        $r = $wpdb->update($uploads_t, array(
            'status'     => 'done',
            'row_count'  => (int)$job['parsed'],
            'added'      => (int)$job['added'],
            'updated_ct' => (int)$job['updated'],
            'removed'    => $removed,
            'restored'   => (int)$job['restored'],
            'notes'      => $notes_s,
        ), array('id' => $upload_id));
        if ($r === false) {
            babh6_job_fail($job, 'Грешка в базата данни при записване на историята: ' . $wpdb->last_error);
            return new WP_Error('babh6_db', 'Грешка в базата данни при записване на историята.');
        }

        foreach ($files as $f) @unlink($f);
        delete_option('babh6_job');

        $result = array('done' => true, 'phase' => 'done', 'notes' => $notes_s, 'removed_checked' => $removed_checked,
            'progress' => (int)$job['total'], 'total' => (int)$job['total'],
            'parsed' => (int)$job['parsed'], 'skipped' => (int)$job['skipped'], 'added' => (int)$job['added'],
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
    /* Празно поле „Производител“ → фирмата от „адрес на възложено производство“ (G), после „собствено производство“ (F) */
    if ($producer_raw === '' || $producer_raw === '-') {
        foreach (array(6, 5) as $ci) {
            $v = trim((string)(isset($row[$ci]) ? $row[$ci] : ''));
            if ($v !== '' && babh6_looks_like_firm($v)) { $producer_raw = $v; break; }
        }
        if ($producer_raw === '-') $producer_raw = '';
    }
    $trader_raw   = trim((string)(isset($row[7]) ? $row[7] : ''));
    $composition  = preg_replace('/\s+/u', ' ', trim((string)(isset($row[11]) ? $row[11] : '')));
    $purpose      = preg_replace('/\s+/u', ' ', trim((string)(isset($row[10]) ? $row[10] : '')));
    $storage      = trim((string)(isset($row[8]) ? $row[8] : ''));
    $deletion     = trim((string)(isset($row[13]) ? $row[13] : ''));

    if (strlen($reg_info['reg']) > 20) return null; /* колоната reg е VARCHAR(20) (IM-07) */
    $flags = babh6_find_flags_fields($name, $composition, $purpose);
    $notif_no = mb_substr(trim((string)(isset($row[2]) ? $row[2] : '')), 0, 95, 'UTF-8');
    $notif_date  = babh6_parse_date_any(isset($row[3]) ? $row[3] : '');
    $launch_date = babh6_parse_date_any(isset($row[12]) ? $row[12] : '');
    $entry_date  = babh6_parse_date_any(isset($row[1]) ? $row[1] : '');
    /* Hash на всички източникови полета (IM-01) — не само име и състав */
    $src_hash = md5(implode("\x1f", array($name, $composition, $purpose, $producer_raw, $trader_raw, $storage, $deletion, $notif_no, (string)$notif_date, (string)$launch_date, (string)$entry_date)));

    return array(
        'reg'           => $reg_info['reg'],
        'rtype'         => $reg_info['rtype'],
        'ryear'         => $reg_info['ryear'],
        'oblast'        => $reg_info['oblast'],
        'name'          => $name,
        'purpose'       => mb_substr($purpose, 0, 2000, 'UTF-8'),
        'composition'   => mb_substr($composition, 0, 5000, 'UTF-8'),
        'comp_hash'     => md5($composition . '|' . $name),
        'src_hash'      => $src_hash,
        'producer_name' => mb_substr($producer_raw, 0, 490, 'UTF-8'),
        'producer_norm' => babh6_norm_firm($producer_raw),
        'producer_kind' => $producer_raw === '' ? '' : (babh6_is_country($producer_raw) ? 'country' : 'firm'),
        'trader_name'   => mb_substr($trader_raw, 0, 490, 'UTF-8'),
        'trader_norm'   => babh6_norm_firm($trader_raw),
        'trader_kind'   => $trader_raw === '' ? '' : (babh6_is_country($trader_raw) ? 'country' : 'firm'),
        'storage'       => mb_substr($storage, 0, 2000, 'UTF-8'),
        'notif_no'      => $notif_no,
        'notif_date'    => $notif_date,
        'launch_date'   => $launch_date,
        'entry_date'    => $entry_date,
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
            $wpdb->prepare('%s', $p['src_hash']),
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
    $cols = 'reg,rtype,ryear,oblast,name,purpose,composition,comp_hash,src_hash,producer_name,producer_norm,producer_kind,trader_name,trader_norm,trader_kind,storage,notif_no,notif_date,launch_date,entry_date,deletion,category,flags,flag_count,first_upload,last_upload,created_at,updated_at';
    $r = $wpdb->query("INSERT INTO $t ($cols) VALUES " . implode(',', $rows_sql));
    return $r !== false; /* резултатът се проверява от извикващия (IM-02) */
}

function babh6_rebuild_parties() {
    global $wpdb;
    $products_t = babh6_table('products');
    $parties_t  = babh6_table('parties');
    $now = current_time('mysql');

    /* Първо: сливане на групи с печатна грешка в името, после търговец по името на продукта */
    babh6_merge_norm_aliases();
    if (function_exists('babh6_infer_traders')) babh6_infer_traders();

    /* Новите обобщения се строят в отделна таблица и се разменят атомарно (IM-04):
       посетителите виждат или старите, или новите фирми — никога празна/частична таблица. */
    $new_t = $parties_t . '_new'; $old_t = $parties_t . '_old';
    $wpdb->query("DROP TABLE IF EXISTS $new_t");
    $wpdb->query("DROP TABLE IF EXISTS $old_t");
    if ($wpdb->query("CREATE TABLE $new_t LIKE $parties_t") === false) return false;
    $parties_t_live = $parties_t; $parties_t = $new_t;

    /* Ефективен търговец (v6.8.1): предположеният по името → посоченият в регистъра → самият производител (собствена марка) */
    $eff = babh6_eff_trader_sql();
    $eff_norm = $eff['norm']; $eff_name = $eff['name'];
    $longest  = function ($col) { return "SUBSTRING(MAX(CONCAT(LPAD(CHAR_LENGTH($col), 5, '0'), $col)), 6)"; };

    $kinds = array(
        'p' => array(
            'norm'     => 'producer_norm',
            'name'     => 'producer_name',
            'where'    => "producer_kind = 'firm' AND producer_norm <> ''",
            'partners' => "COUNT(DISTINCT CASE WHEN $eff_norm <> '' AND $eff_norm <> producer_norm THEN $eff_norm END)",
        ),
        't' => array(
            'norm'     => $eff_norm,
            'name'     => $eff_name,
            'where'    => "$eff_norm <> ''",
            'partners' => "COUNT(DISTINCT CASE WHEN producer_kind = 'firm' AND producer_norm <> '' AND producer_norm <> $eff_norm THEN producer_norm END)",
        ),
    );
    foreach ($kinds as $kind => $k) {
        $full = $longest($k['name']);
        /* Показвано име: най-честото изчистено изписване сред записите, чието име дава точно този ключ
           (слетите печатни грешки не участват), без кавички и адрес — „Флай Фиш ЕООД“ */
        $best_name = array(); $fallback_name = array(); $votes = array();
        foreach ((array)$wpdb->get_results(
            "SELECT {$k['norm']} AS norm, SUBSTRING_INDEX({$k['name']}, ',', 1) AS nm, COUNT(*) AS c
             FROM $products_t WHERE {$k['where']} AND deleted_at IS NULL
             GROUP BY norm, nm ORDER BY c DESC") as $bn) {
            $disp = babh6_display_firm($bn->nm);
            if ($disp === '') continue;
            if (!isset($fallback_name[$bn->norm])) $fallback_name[$bn->norm] = $disp;
            if (babh6_norm_firm($bn->nm) !== $bn->norm) continue;
            $key = mb_strtolower($disp, 'UTF-8');
            if (!isset($votes[$bn->norm][$key])) $votes[$bn->norm][$key] = array('n' => 0, 'disp' => $disp);
            $votes[$bn->norm][$key]['n'] += (int)$bn->c;
        }
        foreach ($votes as $norm => $vs) {
            $top = null;
            foreach ($vs as $v) { if ($top === null || $v['n'] > $top['n']) $top = $v; }
            $best_name[$norm] = $top['disp'];
        }
        foreach ($fallback_name as $norm => $d) { if (!isset($best_name[$norm])) $best_name[$norm] = $d; }
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
                $wpdb->prepare('%s', !empty($best_name[$a->norm]) ? $best_name[$a->norm] : trim((string)$a->name)),
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
                if ($wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,partner_count,inferred_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch)) === false) { $wpdb->query("DROP TABLE IF EXISTS $new_t"); return false; }
                $batch = array();
            }
        }
        if ($batch) {
            if ($wpdb->query("INSERT INTO $parties_t (kind,norm,name,is_bg,product_count,flagged_count,partner_count,inferred_count,first_year,last_year,updated_at) VALUES " . implode(',', $batch)) === false) { $wpdb->query("DROP TABLE IF EXISTS $new_t"); return false; }
        }
    }
    if ($wpdb->query("RENAME TABLE $parties_t_live TO $old_t, $new_t TO $parties_t_live") === false) { $wpdb->query("DROP TABLE IF EXISTS $new_t"); return false; }
    $wpdb->query("DROP TABLE IF EXISTS $old_t");
    return true;
}

/**
 * Печатни грешки в имената на фирмите („Биохерба Ррайхенбах“ / „Адифарма“ / „Флай Феш“)
 * правят отделни групи. Групи с почти еднакъв ключ (латиница без интервали, разстояние
 * на Левенщайн ≤ 1 при ≥ 7 знака, ≤ 2 при ≥ 12) се сливат в по-голямата, като
 * producer_norm / trader_norm на продуктите се пренасочва към нея.
 */
function babh6_merge_norm_aliases() {
    global $wpdb;
    $t = babh6_table('products');
    foreach (array('producer_norm' => 'producer_kind', 'trader_norm' => 'trader_kind') as $col => $kcol) {
        $rows = $wpdb->get_results("SELECT $col AS norm, COUNT(*) AS c FROM $t WHERE $col <> '' AND $kcol = 'firm' GROUP BY $col ORDER BY c DESC");
        if (!$rows) continue;
        $groups = array(); $buckets = array();
        foreach ($rows as $r) {
            $key = babh6_norm_alias_key($r->norm);
            if (strlen($key) < 7) continue;
            $groups[$r->norm] = array('key' => $key, 'c' => (int)$r->c);
            $buckets[$key[0]][] = $r->norm;
        }
        $map = array();
        foreach ($groups as $norm => $g) {
            $best = null; $bestc = $g['c'];
            $len = strlen($g['key']); $maxd = $len >= 12 ? 2 : 1;
            foreach ((array)$buckets[$g['key'][0]] as $other) {
                if ($other === $norm) continue;
                $o = $groups[$other];
                if ($o['c'] < $bestc || ($o['c'] === $g['c'] && strcmp($other, $norm) > 0)) continue;
                if (abs(strlen($o['key']) - $len) > $maxd) continue;
                if (levenshtein($g['key'], $o['key']) <= $maxd) { $best = $other; $bestc = $o['c']; }
            }
            if ($best !== null) $map[$norm] = $best;
        }
        foreach ($map as $from => $to) {
            $guard = 0;
            while (isset($map[$to]) && $guard++ < 5) $to = $map[$to];
            $wpdb->query($wpdb->prepare("UPDATE $t SET $col = %s WHERE $col = %s", $to, $from));
        }
    }
}

/**
 * Преизчисляване на производните данни за всички записи след промяна на версията на
 * правилата (BABH6_RULES_VERSION): ключове на фирмите (producer_norm/trader_norm/kind),
 * категория и автоматични бележки (AD-01 — карти, филтри и статистика ползват една версия).
 * Работи на порции през WP-Cron (и по една порция при зареждане на админа); накрая
 * преизчислява фирмите.
 */
function babh6_renorm_start() {
    update_option('babh6_renorm', array('cursor' => 0, 'changed' => 0, 'started' => time()), false);
    if (!wp_next_scheduled('babh6_renorm_step')) wp_schedule_single_event(time() + 2, 'babh6_renorm_step');
}
add_action('babh6_renorm_step', 'babh6_renorm_step');
function babh6_renorm_step($budget = 20) {
    global $wpdb;
    $st = get_option('babh6_renorm');
    if (!$st) return;
    if (get_option('babh6_job') || get_transient('babh6_renorm_lock')) {
        if (!wp_next_scheduled('babh6_renorm_step')) wp_schedule_single_event(time() + 120, 'babh6_renorm_step');
        return;
    }
    set_transient('babh6_renorm_lock', 1, 2 * MINUTE_IN_SECONDS);
    @set_time_limit(120);
    $t  = babh6_table('products');
    $t0 = time();
    while (time() - $t0 < $budget) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, composition, purpose, category, flags, flag_count, producer_name, trader_name, producer_norm, trader_norm, producer_kind, trader_kind FROM $t WHERE id > %d ORDER BY id LIMIT 1000", (int)$st['cursor']));
        if (!$rows) {
            delete_option('babh6_renorm');
            delete_transient('babh6_renorm_lock');
            babh6_rebuild_parties();
            delete_transient('babh6_stats');
            if (defined('BABH6_RULES_VERSION')) update_option('babh6_rules_version', BABH6_RULES_VERSION);
            return;
        }
        foreach ($rows as $r) {
            $pn = babh6_norm_firm($r->producer_name);
            $pk = $r->producer_name === '' ? '' : (babh6_is_country($r->producer_name) ? 'country' : 'firm');
            $tn = babh6_norm_firm($r->trader_name);
            $tk = $r->trader_name === '' ? '' : (babh6_is_country($r->trader_name) ? 'country' : 'firm');
            $cat   = babh6_categorize($r->name, (string)$r->composition);
            $fl    = babh6_find_flags_fields($r->name, (string)$r->composition, (string)$r->purpose);
            $fl_j  = $fl ? wp_json_encode($fl, JSON_UNESCAPED_UNICODE) : null;
            if ($pn !== $r->producer_norm || $tn !== $r->trader_norm || $pk !== $r->producer_kind || $tk !== $r->trader_kind
                || $cat !== (string)$r->category || count($fl) !== (int)$r->flag_count || (string)$fl_j !== (string)$r->flags) {
                $wpdb->update($t, array('producer_norm' => $pn, 'producer_kind' => $pk, 'trader_norm' => $tn, 'trader_kind' => $tk,
                    'category' => $cat, 'flags' => $fl_j, 'flag_count' => count($fl)), array('id' => (int)$r->id));
                $st['changed']++;
            }
            $st['cursor'] = (int)$r->id;
        }
        update_option('babh6_renorm', $st, false);
    }
    delete_transient('babh6_renorm_lock');
    if (!wp_next_scheduled('babh6_renorm_step')) wp_schedule_single_event(time() + 5, 'babh6_renorm_step');
}
/* Една порция и при отваряне на админа — WP-Cron зависи от посещения */
add_action('admin_init', function () {
    if (get_option('babh6_renorm') && current_user_can('manage_options') && !wp_doing_ajax()) babh6_renorm_step(8);
});

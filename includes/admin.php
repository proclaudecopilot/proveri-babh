<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'БАБХ Регистър v6',
        'БАБХ v6',
        'manage_options',
        'babh6',
        'babh6_admin_dashboard',
        'dashicons-database',
        58
    );
});

/* ============ Upload handler: само записва файла и създава job ============ */
add_action('admin_post_babh6_upload', 'babh6_handle_upload_post');
function babh6_handle_upload_post() {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_upload');

    $redirect = admin_url('admin.php?page=babh6');

    if (empty($_FILES['babh6_file']['tmp_name']) || !is_uploaded_file($_FILES['babh6_file']['tmp_name'])) {
        wp_safe_redirect(add_query_arg('babh6_err', 'nofile', $redirect));
        exit;
    }
    $file = $_FILES['babh6_file'];
    $name = sanitize_file_name($file['name']);
    if (!preg_match('/\.xlsx$/i', $name)) {
        wp_safe_redirect(add_query_arg('babh6_err', 'type', $redirect));
        exit;
    }

    $job = babh6_job_create($file['tmp_name'], $name);
    if (is_wp_error($job)) {
        wp_safe_redirect(add_query_arg(array('babh6_err' => 'etl', 'babh6_msg' => rawurlencode($job->get_error_message())), $redirect));
        exit;
    }

    /* Обработката тръгва през AJAX на dashboard-а */
    wp_safe_redirect($redirect);
    exit;
}

/* ============ AJAX стъпка ============ */
add_action('wp_ajax_babh6_step', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Недостатъчни права.'));
    check_ajax_referer('babh6_step', 'nonce');
    $res = babh6_run_step();
    if (is_wp_error($res)) wp_send_json_error(array('message' => $res->get_error_message()));
    wp_send_json_success($res);
});

add_action('wp_ajax_babh6_cancel', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Недостатъчни права.'));
    check_ajax_referer('babh6_step', 'nonce');
    babh6_job_cancel();
    wp_send_json_success(array('ok' => 1));
});

/* ============ Dashboard ============ */
function babh6_admin_dashboard() {
    global $wpdb;
    $products_t = babh6_table('products');
    $parties_t  = babh6_table('parties');
    $uploads_t  = babh6_table('uploads');

    $total    = (int)$wpdb->get_var("SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL");
    $flagged  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NULL AND flag_count > 0");
    $deleted  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $products_t WHERE deleted_at IS NOT NULL");
    $prods_p  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $parties_t WHERE kind = 'p'");
    $prods_pb = (int)$wpdb->get_var("SELECT COUNT(*) FROM $parties_t WHERE kind = 'p' AND is_bg = 1");
    $trads_t  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $parties_t WHERE kind = 't'");
    $trads_tb = (int)$wpdb->get_var("SELECT COUNT(*) FROM $parties_t WHERE kind = 't' AND is_bg = 1");
    $history  = $wpdb->get_results("SELECT * FROM $uploads_t ORDER BY id DESC LIMIT 10");
    $fulltext = (int)get_option('babh6_fulltext', 0);
    $job      = get_option('babh6_job');

    echo '<div class="wrap"><h1>БАБХ Регистър v6 <span style="font-size:12px;color:#787c82;font-weight:400">v6 · DB + ETL + Frontend</span></h1>';

    if (isset($_GET['babh6_err'])) {
        $err = sanitize_text_field(wp_unslash($_GET['babh6_err']));
        $messages = array(
            'nofile' => 'Не е избран файл.',
            'type'   => 'Файлът трябва да е .xlsx (не .xls или .csv).',
            'etl'    => 'Грешка: ' . (isset($_GET['babh6_msg']) ? esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['babh6_msg'])))) : 'неизвестна'),
        );
        $msg = isset($messages[$err]) ? $messages[$err] : 'Неизвестна грешка.';
        echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
    }

    /* ===== Активна задача → progress UI ===== */
    if ($job) {
        $nonce = wp_create_nonce('babh6_step');
        echo '<div id="babh6-progress" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;max-width:640px;margin:18px 0">';
        echo '<h2 style="margin-top:0">Обработва се: ' . esc_html($job['filename']) . '</h2>';
        echo '<div style="background:#f0f0f1;border-radius:100px;height:14px;overflow:hidden;margin:10px 0">';
        echo '<div id="babh6-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#2271b1,#72aee6);border-radius:100px;transition:width .4s"></div></div>';
        echo '<p id="babh6-status" style="color:#787c82;margin:6px 0 12px">Стартирам…</p>';
        echo '<button type="button" class="button" id="babh6-cancel">Отмени import-а</button>';
        echo '</div>';
        ?>
        <script>
        (function(){
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var bar = document.getElementById('babh6-bar');
            var status = document.getElementById('babh6-status');
            var retries = 0;
            var stopped = false;

            document.getElementById('babh6-cancel').addEventListener('click', function(){
                if (!confirm('Сигурен ли си? Частично обработените данни остават в базата (следващ import ги изравнява).')) return;
                stopped = true;
                var fd = new FormData();
                fd.append('action', 'babh6_cancel');
                fd.append('nonce', nonce);
                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(){ location.reload(); });
            });

            function step(){
                if (stopped) return;
                var fd = new FormData();
                fd.append('action', 'babh6_step');
                fd.append('nonce', nonce);
                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'})
                    .then(function(r){
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function(j){
                        retries = 0;
                        if (!j.success) {
                            status.innerHTML = '<b style="color:#d63638">Грешка:</b> ' + (j.data && j.data.message ? j.data.message : 'неизвестна');
                            return;
                        }
                        var d = j.data;
                        if (d.done) {
                            bar.style.width = '100%';
                            status.innerHTML = '<b style="color:#00a32a">Готово.</b> Продукти: ' + d.parsed +
                                ' · Нови: ' + d.added + ' · Обновени: ' + d.updated +
                                ' · Заличени: ' + d.removed + ' · Възстановени: ' + d.restored;
                            setTimeout(function(){ location.reload(); }, 1800);
                            return;
                        }
                        if (d.phase === 'count') {
                            status.textContent = 'Преброени ' + d.total + ' реда. Обработвам…';
                        } else {
                            var pct = d.total ? Math.round(100 * d.progress / d.total) : 0;
                            bar.style.width = pct + '%';
                            status.textContent = d.progress + ' / ' + d.total + ' реда (' + pct + '%) · Нови: ' + d.added + ' · Обновени: ' + d.updated;
                        }
                        step();
                    })
                    .catch(function(e){
                        retries++;
                        if (retries > 5) {
                            status.innerHTML = '<b style="color:#d63638">Връзката прекъсна 5 пъти.</b> Презареди страницата — обработката продължава от същото място.';
                            return;
                        }
                        status.textContent = 'Прекъсване (' + e.message + ') — опит ' + retries + '/5 след 4 сек… Прогресът е запазен.';
                        setTimeout(step, 4000);
                    });
            }
            step();
        })();
        </script>
        <?php
        echo '</div>';
        return; /* по време на import не показваме останалото */
    }

    /* ===== Stats ===== */
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0">';
    $cards = array(
        array('Продукти (активни)', number_format_i18n($total)),
        array('С регулаторни флагове', number_format_i18n($flagged)),
        array('Заличени', number_format_i18n($deleted)),
        array('Производители (БГ / общо)', number_format_i18n($prods_pb) . ' / ' . number_format_i18n($prods_p)),
        array('Търговци (БГ / общо)', number_format_i18n($trads_tb) . ' / ' . number_format_i18n($trads_t)),
    );
    foreach ($cards as $c) {
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px">';
        echo '<div style="font-size:12px;color:#787c82">' . esc_html($c[0]) . '</div>';
        echo '<div style="font-size:24px;font-weight:600;margin-top:4px">' . esc_html($c[1]) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    /* ===== Upload form ===== */
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;max-width:640px;margin-bottom:20px">';
    echo '<h2 style="margin-top:0">Качи регистър от БАБХ (.xlsx)</h2>';
    echo '<p style="color:#787c82">Обработката е на порции (~1500 реда на стъпка) с progress bar — устойчива на слаби хостинги. При прекъсване продължава от същото място. ~28 000 реда отнемат 2-5 минути.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field('babh6_upload');
    echo '<input type="hidden" name="action" value="babh6_upload">';
    echo '<p><input type="file" name="babh6_file" accept=".xlsx" required></p>';
    submit_button('Качи и обработи', 'primary', 'submit', false);
    echo '</form></div>';

    /* ===== Health ===== */
    $upload_max = ini_get('upload_max_filesize');
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px;max-width:640px;margin-bottom:20px">';
    echo '<h2 style="margin-top:0">Здраве на системата</h2><ul style="margin:0">';
    echo '<li>FULLTEXT търсене: ' . ($fulltext ? '<b style="color:#00a32a">активно</b>' : '<b style="color:#d63638">недостъпно</b> — LIKE fallback в Сесия 2') . '</li>';
    echo '<li>PHP: ' . esc_html(PHP_VERSION) . ' · Memory: ' . esc_html(ini_get('memory_limit')) . ' · Upload max: ' . esc_html($upload_max) . '</li>';
    echo '<li>ZipArchive: ' . (class_exists('ZipArchive') ? 'да' : '<b style="color:#d63638">липсва</b>') . ' · XMLReader: ' . (class_exists('XMLReader') ? 'да' : '<b style="color:#d63638">липсва</b>') . '</li>';
    echo '</ul></div>';

    /* ===== History ===== */
    echo '<h2>История на качванията</h2>';
    if (!$history) {
        echo '<p style="color:#787c82">Още няма качвания.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        echo '<th>#</th><th>Файл</th><th>Дата</th><th>Статус</th><th>Редове</th><th>Нови</th><th>Обновени</th><th>Заличени</th><th>Възстановени</th>';
        echo '</tr></thead><tbody>';
        foreach ($history as $h) {
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int)$h->id,
                esc_html($h->filename),
                esc_html($h->uploaded_at),
                esc_html($h->status),
                number_format_i18n((int)$h->row_count),
                number_format_i18n((int)$h->added),
                number_format_i18n((int)$h->updated_ct),
                number_format_i18n((int)$h->removed),
                number_format_i18n((int)$h->restored)
            );
        }
        echo '</tbody></table>';
    }

    echo '<p style="color:#787c82;margin-top:20px"><b>Публичен фронтенд:</b> началната страница на сайта показва регистъра автоматично (standalone, без тема). Shortcode <code>[babh_register]</code> остава наличен за други страници. Плъгинът работи паралелно с v5.6 — отделни таблици <code>' . esc_html($wpdb->prefix) . 'babh6_*</code>.</p>';
    echo '</div>';
}

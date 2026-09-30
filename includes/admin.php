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

    /* Един или няколко файла (част 1 + част 2 → едно качване) */
    $files = array();
    if (!empty($_FILES['babh6_file']['tmp_name'])) {
        $tmp = $_FILES['babh6_file']['tmp_name'];
        $nm  = $_FILES['babh6_file']['name'];
        if (is_array($tmp)) {
            foreach ($tmp as $i => $t) {
                if ($t === '' || !is_uploaded_file($t)) continue;
                $files[] = array('src' => $t, 'name' => sanitize_file_name((string)$nm[$i]));
            }
        } elseif (is_uploaded_file($tmp)) {
            $files[] = array('src' => $tmp, 'name' => sanitize_file_name((string)$nm));
        }
    }
    if (!$files) {
        wp_safe_redirect(add_query_arg('babh6_err', 'nofile', $redirect));
        exit;
    }
    foreach ($files as $f) {
        if (!preg_match('/\.xlsx$/i', $f['name'])) {
            wp_safe_redirect(add_query_arg('babh6_err', 'type', $redirect));
            exit;
        }
    }
    /* Част 1 преди част 2 независимо от реда на избор */
    usort($files, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

    $job = babh6_job_create_files($files, 'manual');
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


/* ============ Settings save ============ */
add_action('admin_post_babh6_settings', function () {
    if (!current_user_can('manage_options')) wp_die('Недостатъчни права.');
    check_admin_referer('babh6_settings');
    update_option('babh6_anthropic_key', sanitize_text_field(wp_unslash($_POST['babh6_key'] ?? '')), false);
    update_option('babh6_password', sanitize_text_field(wp_unslash($_POST['babh6_pw'] ?? '')), false);
    wp_safe_redirect(add_query_arg('babh6_saved', 1, admin_url('admin.php?page=babh6')));
    exit;
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
        if (!empty($job['source']) && $job['source'] === 'auto') {
            echo '<p style="color:#787c82;margin:0 0 6px">Автоматично обновяване от БАБХ. Обработката продължава и на заден план (WP-Cron), дори да затвориш страницата.</p>';
        }
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
                                ' · Заличени: ' + d.removed + ' · Възстановени: ' + d.restored + (d.notes ? '<br><span style="color:#dba617">' + d.notes + '</span>' : '');
                            setTimeout(function(){ location.reload(); }, 1800);
                            return;
                        }
                        if (d.phase === 'busy') {
                            status.textContent = 'Обработва се на заден план… (' + d.progress + ' / ' + d.total + ' реда)';
                            setTimeout(step, 2500);
                            return;
                        }
                        if (d.phase === 'count') {
                            status.textContent = 'Преброени ' + d.total + ' реда. Обработвам…';
                        } else {
                            var pct = d.total ? Math.round(100 * d.progress / d.total) : 0;
                            bar.style.width = pct + '%';
                            var fpart = (d.files && d.files > 1) ? ' · файл ' + d.file + '/' + d.files : '';
                            status.textContent = d.progress + ' / ' + d.total + ' реда (' + pct + '%)' + fpart + ' · Нови: ' + d.added + ' · Обновени: ' + d.updated;
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
    echo '<p style="color:#787c82">Избери <b>всички части</b> на регистъра наведнъж (част 1 + част 2) — обработват се като едно качване, иначе втората част ще заличи продуктите от първата. Обработката е на порции (~1500 реда на стъпка) с progress bar — устойчива на слаби хостинги. При прекъсване продължава от същото място. ~28 000 реда отнемат 2-5 минути.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field('babh6_upload');
    echo '<input type="hidden" name="action" value="babh6_upload">';
    echo '<p><input type="file" name="babh6_file[]" accept=".xlsx" multiple required></p>';
    submit_button('Качи и обработи', 'primary', 'submit', false);
    echo '</form></div>';

    /* ===== Auto sync ===== */
    babh6_sync_admin_section();

    /* ===== Settings ===== */
    if (isset($_GET['babh6_saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Настройките са запазени.</p></div>';
    }
    $api_key = babh6_api_key();
    $pw      = babh6_password();
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;max-width:640px;margin-bottom:20px">';
    echo '<h2 style="margin-top:0">Настройки</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('babh6_settings');
    echo '<input type="hidden" name="action" value="babh6_settings">';
    echo '<p style="margin-bottom:4px"><b>Anthropic API ключ</b> — включва Novel Food AI проверката на фронтенда.</p>';
    echo '<input type="password" name="babh6_key" value="' . esc_attr($api_key) . '" placeholder="sk-ant-api03-…" style="width:100%;max-width:460px;font-family:monospace" autocomplete="new-password">';
    echo $api_key ? '<p style="color:#00a32a;font-size:12px;margin-top:4px">✓ Конфигуриран (' . esc_html(substr($api_key, 0, 12)) . '…)</p>' : '<p style="color:#787c82;font-size:12px;margin-top:4px">Без ключ Novel Food табът показва „Скоро“ + waitlist.</p>';
    echo '<p style="margin:14px 0 4px"><b>Парола за достъп до сайта</b> — празно = публичен. С парола целият фронтенд и API са заключени (cookie 30 дни).</p>';
    echo '<input type="text" name="babh6_pw" value="' . esc_attr($pw) . '" placeholder="Без парола (публичен)" style="width:100%;max-width:300px">';
    echo '<p style="margin-top:14px">';
    submit_button('Запази настройките', 'primary', 'submit', false);
    echo '</p></form></div>';

    /* ===== Health ===== */
    $upload_max = ini_get('upload_max_filesize');
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px;max-width:640px;margin-bottom:20px">';
    echo '<h2 style="margin-top:0">Здраве на системата</h2><ul style="margin:0">';
    echo '<li>FULLTEXT търсене: ' . ($fulltext ? '<b style="color:#00a32a">активно</b>' : '<b style="color:#d63638">недостъпно</b> — LIKE fallback в Сесия 2') . '</li>';
    echo '<li>PHP: ' . esc_html(PHP_VERSION) . ' · Memory: ' . esc_html(ini_get('memory_limit')) . ' · Upload max: ' . esc_html($upload_max) . '</li>';
    echo '<li>ZipArchive: ' . (class_exists('ZipArchive') ? 'да' : '<b style="color:#d63638">липсва</b>') . ' · XMLReader: ' . (class_exists('XMLReader') ? 'да' : '<b style="color:#d63638">липсва</b>') . '</li>';
    /* REST API самопроверка: така както го вика фронтендът (отвън, през HTTP) */
    $rest_urls = array(
        'wp-json'    => untrailingslashit(rest_url('babh6/v1')) . '/stats',
        'rest_route' => add_query_arg('rest_route', '/babh6/v1/stats', home_url('/')),
    );
    foreach ($rest_urls as $k => $u) {
        $r = wp_remote_get($u, array('timeout' => 10, 'sslverify' => apply_filters('https_local_ssl_verify', false)));
        if (is_wp_error($r)) {
            $txt = '<b style="color:#d63638">грешка</b> — ' . esc_html($r->get_error_message());
        } else {
            $code = (int)wp_remote_retrieve_response_code($r);
            $body = (string)wp_remote_retrieve_body($r);
            $json = json_decode($body, true);
            if ($code === 200 && is_array($json) && isset($json['total'])) $txt = '<b style="color:#00a32a">OK</b> (' . number_format_i18n((int)$json['total']) . ' продукта)';
            else $txt = '<b style="color:#d63638">HTTP ' . $code . '</b>' . (is_array($json) && isset($json['code']) ? ' — ' . esc_html($json['code']) . ': ' . esc_html(isset($json['message']) ? $json['message'] : '') : ' — ' . esc_html(mb_substr(wp_strip_all_tags($body), 0, 120, 'UTF-8')));
        }
        echo '<li>REST (' . esc_html($k) . '): <a href="' . esc_url($u) . '" target="_blank" rel="noopener"><code>' . esc_html(preg_replace('#^https?://[^/]+#', '', $u)) . '</code></a> → ' . $txt . '</li>';
    }
    echo '<li style="color:#787c82;font-size:12px">Фронтендът ползва <code>/wp-json/</code>; при 404 минава сам на <code>?rest_route=</code>. Ако и двата са 404 — REST API-то е изключено от друг плъгин/защита или permalink правилата са счупени (Settings → Permalinks → Save).</li>';
    echo '</ul></div>';

    /* ===== History ===== */
    echo '<h2>История на качванията</h2>';
    if (!$history) {
        echo '<p style="color:#787c82">Още няма качвания.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        echo '<th>#</th><th>Файл</th><th>Дата</th><th>Източник</th><th>Статус</th><th>Редове</th><th>Нови</th><th>Обновени</th><th>Заличени</th><th>Възстановени</th>';
        echo '</tr></thead><tbody>';
        foreach ($history as $h) {
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int)$h->id,
                esc_html($h->filename),
                esc_html($h->uploaded_at),
                (isset($h->source) && $h->source === 'auto') ? 'авто (БАБХ)' : 'ръчно',
                esc_html($h->status) . (!empty($h->notes) ? ' <span title="' . esc_attr($h->notes) . '" style="color:#d63638;cursor:help">ⓘ</span>' : ''),
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

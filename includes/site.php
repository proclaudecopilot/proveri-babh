<?php
if (!defined('ABSPATH')) exit;

/**
 * Плъгинът поема началната страница на сайта: proveribabh.com/ рендира
 * регистъра като самостоятелна страница — без нужда от страници, теми
 * или редактори. Изключва се с filter 'babh6_front_enabled' или
 * option 'babh6_front' = 0.
 */

add_action('template_redirect', function () {
    if (is_feed() || is_robots()) return;
    if (!(is_front_page() || is_home())) return;
    if (!apply_filters('babh6_front_enabled', (int)get_option('babh6_front', 1))) return;
    babh6_render_standalone();
    exit;
});

function babh6_render_standalone() {
    $v    = BABH6_VERSION;
    $css  = esc_url(BABH6_URL . 'assets/babh6.css?ver=' . $v);
    $js   = esc_url(BABH6_URL . 'assets/babh6.js?ver=' . $v);
    $rest = esc_url_raw(untrailingslashit(rest_url('babh6/v1')));
    $rest2 = esc_url_raw(add_query_arg('rest_route', '/babh6/v1', home_url('/')));

    $site  = get_bloginfo('name');
    $title = $site ? $site . ' — справка за хранителни добавки по данни на БАБХ' : 'Регистър на добавките — справка по данни на БАБХ';
    $desc  = 'Потърси хранителна добавка по име, фирма, съставка или регистрационен номер. Разгледай наличните данни от регистъра на БАБХ.';
    $home  = esc_url(home_url('/'));

    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0E1116">
<title><?php echo esc_html($title); ?></title>
<meta name="description" content="<?php echo esc_attr($desc); ?>">
<link rel="canonical" href="<?php echo $home; ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo esc_attr($title); ?>">
<meta property="og:description" content="<?php echo esc_attr($desc); ?>">
<meta property="og:url" content="<?php echo $home; ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='9' fill='%230E1116'/><circle cx='16' cy='16' r='5' fill='%23E66A3D'/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $css; ?>">
</head>
<body class="babh6-body">
<div class="b6-page">
  <div id="babh6-app" class="babh6"><div class="b6-boot">Регистърът се зарежда…</div><noscript><div class="b6-boot">За търсене в регистъра е необходим JavaScript. Включи го в браузъра и презареди страницата.</div></noscript></div>
  <footer class="b6-site-f">© <?php echo esc_html(date_i18n('Y')); ?> <?php echo esc_html($site ? $site : 'Регистър на добавките'); ?>. Данни от Българската агенция по безопасност на храните (БАБХ). Сайт за справки по данни от регистъра на БАБХ.</footer>
</div>
<script>window.BABH6_CFG = { rest: <?php echo wp_json_encode($rest); ?>, rest2: <?php echo wp_json_encode($rest2); ?>, locked: <?php echo babh6_gate_ok() ? '0' : '1'; ?>, hasAI: <?php echo babh6_api_key() !== '' ? '1' : '0'; ?>, pro: <?php echo babh6_pro_ok() ? '1' : '0'; ?>, nonce: <?php echo wp_json_encode(is_user_logged_in() ? wp_create_nonce('wp_rest') : ''); ?> };</script>
<script src="<?php echo $js; ?>"></script>
</body>
</html><?php
}

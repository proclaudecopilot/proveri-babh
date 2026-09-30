<?php
if (!defined('ABSPATH')) exit;

/**
 * Публичен фронтенд: shortcode [babh_register] рендира приложението,
 * което тегли данни от REST API-то (includes/rest.php).
 */

add_action('init', function () {
    wp_register_style(
        'babh6-fonts',
        'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap',
        array(),
        null
    );
    wp_register_style('babh6', BABH6_URL . 'assets/babh6.css', array('babh6-fonts'), BABH6_VERSION);
    wp_register_script('babh6', BABH6_URL . 'assets/babh6.js', array(), BABH6_VERSION, true);
});

function babh6_shortcode() {
    wp_enqueue_style('babh6');
    wp_enqueue_script('babh6');
    wp_localize_script('babh6', 'BABH6_CFG', array(
        'rest'   => esc_url_raw(untrailingslashit(rest_url('babh6/v1'))),
        'rest2'  => esc_url_raw(add_query_arg('rest_route', '/babh6/v1', home_url('/'))),
        'locked' => babh6_gate_ok() ? 0 : 1,
        'hasAI'  => babh6_api_key() !== '' ? 1 : 0,
        'pro'    => babh6_pro_ok() ? 1 : 0,
        'pw'     => babh6_password() !== '' ? 1 : 0,
        'nonce'  => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
    ));
    return '<div id="babh6-app" class="babh6"><div class="b6-boot">Регистърът се зарежда…</div><noscript><div class="b6-boot">За търсене в регистъра е необходим JavaScript. Включи го в браузъра и презареди страницата.</div></noscript></div>';
}
add_shortcode('babh_register', 'babh6_shortcode');
add_shortcode('babh_register_v6', 'babh6_shortcode');

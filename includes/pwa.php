<?php
if (!defined('ABSPATH')) exit;

/**
 * Приложение (PWA, v6.8): manifest, service worker и мета тагове, за да може регистърът да се
 * „инсталира“ на телефон/десктоп и да се усеща като приложение (цял екран, без адресна лента,
 * икона на началния екран, бързо зареждане на обвивката, работеща страница при кратко прекъсване).
 *
 *   /?babh6_manifest=1 — web app manifest (JSON)
 *   /?babh6_sw=1       — service worker (JS), сервиран от корена, за да обхваща целия сайт
 *
 * Данните от REST API-то НЕ се кешират от service worker-а (отговорите са лични — Pro/парола).
 */

function babh6_pwa_manifest_url() { return add_query_arg('babh6_manifest', '1', home_url('/')); }
function babh6_pwa_sw_url() { return add_query_arg('babh6_sw', '1', home_url('/')); }
function babh6_pwa_short_name() {
    $n = trim((string)get_option('babh6_pwa_name', ''));
    if ($n !== '') return $n;
    $site = trim((string)get_bloginfo('name'));
    return ($site !== '' && mb_strlen($site, 'UTF-8') <= 12) ? $site : 'Добавки';
}

/** Мета тагове за <head> (стандартна страница и shortcode режим). */
function babh6_pwa_head_tags() {
    $icons = BABH6_URL . 'assets/icons/';
    return
        '<link rel="manifest" href="' . esc_url(babh6_pwa_manifest_url()) . '">' . "\n" .
        '<meta name="theme-color" content="#0E1116">' . "\n" .
        '<meta name="color-scheme" content="light">' . "\n" .
        '<meta name="mobile-web-app-capable" content="yes">' . "\n" .
        '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n" .
        '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n" .
        '<meta name="apple-mobile-web-app-title" content="' . esc_attr(babh6_pwa_short_name()) . '">' . "\n" .
        '<link rel="apple-touch-icon" href="' . esc_url($icons . 'apple-touch-icon.png?ver=' . BABH6_VERSION) . '">' . "\n" .
        '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url($icons . 'icon-192.png?ver=' . BABH6_VERSION) . '">' . "\n";
}

/* Shortcode режим: таговете влизат през wp_head (стандартната страница ги печата сама) */
add_action('wp_head', function () {
    if (!apply_filters('babh6_pwa_enabled', true)) return;
    if (is_admin()) return;
    global $post;
    if (!($post instanceof WP_Post) || (!has_shortcode((string)$post->post_content, 'babh_register') && !has_shortcode((string)$post->post_content, 'babh_register_v6'))) return;
    echo babh6_pwa_head_tags();
}, 5);

add_action('init', function () {
    if (!apply_filters('babh6_pwa_enabled', true)) return;
    if (isset($_GET['babh6_manifest'])) { babh6_pwa_serve_manifest(); exit; }
    if (isset($_GET['babh6_sw'])) { babh6_pwa_serve_sw(); exit; }
}, 1);

function babh6_pwa_serve_manifest() {
    $icons = BABH6_URL . 'assets/icons/';
    $v = '?ver=' . BABH6_VERSION;
    $site = trim((string)get_bloginfo('name'));
    $start = home_url('/');
    $m = array(
        'id'               => $start,
        'name'             => $site !== '' ? $site . ' — Регистър на добавките' : 'Регистър на добавките',
        'short_name'       => babh6_pwa_short_name(),
        'description'      => 'Справка за хранителни добавки по данни от регистъра на БАБХ: продукти, производители, търговци, класация.',
        'lang'             => 'bg',
        'dir'              => 'ltr',
        'start_url'        => add_query_arg('source', 'pwa', $start),
        'scope'            => wp_parse_url($start, PHP_URL_PATH) ? wp_parse_url($start, PHP_URL_PATH) : '/',
        'display'          => 'standalone',
        'display_override' => array('standalone', 'minimal-ui'),
        'orientation'      => 'portrait',
        'background_color' => '#0E1116',
        'theme_color'      => '#0E1116',
        'categories'       => array('health', 'reference', 'productivity'),
        'icons'            => array(
            array('src' => $icons . 'icon-192.png' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
            array('src' => $icons . 'icon-512.png' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
            array('src' => $icons . 'icon-maskable-192.png' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'),
            array('src' => $icons . 'icon-maskable-512.png' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
        ),
        'shortcuts'        => array(
            array('name' => 'Продукти', 'short_name' => 'Продукти', 'description' => 'Търсене в регистъра', 'url' => $start . '#tab=products', 'icons' => array(array('src' => $icons . 'icon-192.png' . $v, 'sizes' => '192x192'))),
            array('name' => 'Класация', 'short_name' => 'Класация', 'description' => 'Топ производители и търговци', 'url' => $start . '#tab=rank', 'icons' => array(array('src' => $icons . 'icon-192.png' . $v, 'sizes' => '192x192'))),
            array('name' => 'Производители', 'short_name' => 'Производители', 'url' => $start . '#tab=producers', 'icons' => array(array('src' => $icons . 'icon-192.png' . $v, 'sizes' => '192x192'))),
        ),
    );
    status_header(200);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo wp_json_encode(apply_filters('babh6_pwa_manifest', $m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Service worker: обвивката (CSS, JS, шрифтове, икони) — „от кеша, после обновяване“;
 * навигацията — „от мрежата, при отказ — последната запазена страница / офлайн екран“;
 * REST и администрацията — винаги от мрежата (нищо лично не се пази).
 */
function babh6_pwa_serve_sw() {
    $v    = BABH6_VERSION;
    $css  = BABH6_URL . 'assets/babh6.css?ver=' . $v;
    $js   = BABH6_URL . 'assets/babh6.js?ver=' . $v;
    $home = home_url('/');
    $icons = BABH6_URL . 'assets/icons/';
    $offline = '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Няма връзка</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0E1116;color:#E5E7EB;font-family:system-ui,sans-serif;text-align:center;padding:24px}'
        . '.c{max-width:360px}.m{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#E66A3D,#C5572E);margin:0 auto 18px}h1{font-size:20px;margin:0 0 8px}p{color:#9CA3AF;font-size:14px;line-height:1.5}'
        . 'button{margin-top:16px;padding:12px 20px;border:0;border-radius:10px;background:#E66A3D;color:#fff;font-size:14px;font-weight:600}</style></head>'
        . '<body><div class="c"><div class="m"></div><h1>Няма връзка с интернет</h1><p>Регистърът се зарежда от сървъра. Провери връзката и опитай отново.</p><button onclick="location.reload()">Опитай отново</button></div></body></html>';
    $cfg = array('v' => 'babh6-' . $v, 'shell' => array($css, $js, $icons . 'icon-192.png?ver=' . $v), 'home' => $home, 'offline' => $offline);
    status_header(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, max-age=0');
    echo "/* Регистър на добавките — service worker v$v */\n";
    echo 'var CFG = ' . wp_json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
    echo <<<'JS'
var SHELL = CFG.v + '-shell', PAGES = CFG.v + '-pages';
self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) {
    return Promise.all(CFG.shell.map(function (u) { return c.add(new Request(u, { cache: 'reload' })).catch(function () {}); }));
  }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('babh6-') === 0 && k !== SHELL && k !== PAGES; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('message', function (e) { if (e.data && e.data.type === 'SKIP_WAITING') self.skipWaiting(); });
function isApi(u) { return u.pathname.indexOf('/wp-json/') !== -1 || u.searchParams.has('rest_route') || u.pathname.indexOf('/wp-admin') !== -1 || u.pathname.indexOf('/wp-login') !== -1 || u.pathname.indexOf('admin-ajax') !== -1 || u.pathname.indexOf('wp-cron') !== -1; }
function isShell(u) { return (/\.(css|js|png|svg|woff2?|ttf)(\?|$)/i.test(u.pathname + u.search) && u.origin === self.location.origin) || /fonts\.(googleapis|gstatic)\.com$/.test(u.hostname); }
self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var u; try { u = new URL(req.url); } catch (err) { return; }
  if (isApi(u) || u.searchParams.has('babh6_sw') || u.searchParams.has('babh6_manifest')) return;
  if (req.mode === 'navigate') {
    var homePath = new URL(CFG.home).pathname;
    e.respondWith(fetch(req).then(function (r) {
      /* офлайн копие само на началната страница (приложението), не на всяка посетена страница */
      if (r && r.ok && r.type === 'basic' && u.pathname === homePath) { var copy = r.clone(); caches.open(PAGES).then(function (c) { c.put(new Request(CFG.home), copy); }); }
      return r;
    }).catch(function () {
      return caches.match(new Request(CFG.home)).then(function (r) { return r || new Response(CFG.offline, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }); });
    }));
    return;
  }
  if (isShell(u)) {
    e.respondWith(caches.open(SHELL).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (r) { if (r && (r.ok || r.type === 'opaque')) c.put(req, r.clone()); return r; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
JS;
}

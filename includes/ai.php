<?php
if (!defined('ABSPATH')) exit;

/**
 * AI (Novel Food проверка) + парола за достъп.
 * v6.6 (по одита): сесията изтича на сървъра (AU-02), „Изход“, лимити на AI
 * заявките независимо от режима на вход (AI-05), строга проверка на отговора и
 * проверими източници (AI-01, AI-04), версия на метода в кеша (AI-06).
 */

/* Версия на метода за AI проверка — влиза в кеш ключа; смени я при промяна на промпта/модела. */
define('BABH6_AI_METHOD', 'v2');

/* ============ Настройки с lazy миграция от v5.6 (babh_*) ============ */
function babh6_api_key() {
    $v = get_option('babh6_anthropic_key', null);
    if ($v === null || $v === false) {
        $v = (string)get_option('babh_anthropic_key', '');
        update_option('babh6_anthropic_key', $v, false);
    }
    return trim((string)$v);
}
function babh6_password() {
    $v = get_option('babh6_password', null);
    if ($v === null || $v === false) {
        $v = (string)get_option('babh_password', '');
        update_option('babh6_password', $v, false);
    }
    return (string)$v;
}

/* ============ Gate ============ */
/** Подписан токен с изрично изтичане: "<exp>.<hmac>". Сървърът отказва изтекъл токен дори ако бъде изпратен ръчно. */
function babh6_auth_token($pw, $exp) {
    return (int)$exp . '.' . hash_hmac('sha256', 'babh6|' . $pw . '|' . (int)$exp, wp_salt('auth'));
}
function babh6_auth_hash($pw) { /* обратна съвместимост (не се използва за проверка) */
    return hash_hmac('sha256', 'babh6|' . $pw, wp_salt('auth'));
}
function babh6_gate_ok() {
    $pw = babh6_password();
    if ($pw === '') return true;
    if (current_user_can('manage_options')) return true;
    $c = isset($_COOKIE['babh6_auth']) ? (string)$_COOKIE['babh6_auth'] : '';
    if ($c === '' || strpos($c, '.') === false) return false;
    $exp = (int)substr($c, 0, strpos($c, '.'));
    if ($exp <= 0 || $exp < time()) return false;
    return hash_equals(babh6_auth_token($pw, $exp), $c);
}
/** permission_callback за data endpoint-ите */
function babh6_rest_permission() {
    if (babh6_gate_ok()) return true;
    return new WP_Error('babh6_locked', 'Достъпът изисква парола.', array('status' => 401));
}

/* ============ REST: /auth, /logout, /novel-check ============ */
add_action('rest_api_init', function () {
    register_rest_route('babh6/v1', '/auth', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_auth', 'permission_callback' => '__return_true',
    ));
    register_rest_route('babh6/v1', '/logout', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_logout', 'permission_callback' => '__return_true',
    ));
    register_rest_route('babh6/v1', '/novel-check', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_novel_check', 'permission_callback' => 'babh6_rest_permission',
    ));
});

function babh6_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

function babh6_rest_auth($req) {
    $pw = babh6_password();
    if ($pw === '') return rest_ensure_response(array('ok' => 1));

    $key = 'babh6_auth_' . md5(babh6_client_ip());
    $n   = (int)get_transient($key);
    if ($n > 20) return new WP_Error('babh6_rate', 'Направени са твърде много опити. Опитай отново след час.', array('status' => 429));
    set_transient($key, $n + 1, HOUR_IN_SECONDS);

    $input = (string)$req->get_param('password');
    if (!hash_equals($pw, $input)) {
        return new WP_Error('babh6_wrongpw', 'Грешна парола.', array('status' => 403));
    }
    $exp = time() + 30 * DAY_IN_SECONDS;
    setcookie('babh6_auth', babh6_auth_token($pw, $exp), array(
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    return rest_ensure_response(array('ok' => 1, 'expires' => $exp));
}

function babh6_rest_logout() {
    setcookie('babh6_auth', '', array('expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
    return rest_ensure_response(array('ok' => 1));
}

/** Етикетите се генерират локално от валидния статус — цветът и текстът никога не си противоречат (AI-04). */
function babh6_ai_labels() {
    return array(
        'banned'  => 'ЗАБРАНЕН',
        'ok'      => 'РАЗРЕШЕН',
        'caution' => 'ВНИМАНИЕ',
        'pending' => 'ЧАКА ОДОБРЕНИЕ',
        'unknown' => 'НЕ Е УСТАНОВЕН ЕДНОЗНАЧЕН РЕЗУЛТАТ',
    );
}

function babh6_rest_novel_check($req) {
    $api_key = babh6_api_key();
    if ($api_key === '') {
        return new WP_Error('babh6_noai', 'AI проверката не е конфигурирана.', array('status' => 503));
    }

    $ingredient = sanitize_text_field((string)$req->get_param('ingredient'));
    $ingredient = trim(preg_replace('/\s+/u', ' ', $ingredient));
    if ($ingredient === '' || mb_strlen($ingredient, 'UTF-8') < 2 || mb_strlen($ingredient, 'UTF-8') > 120) {
        return new WP_Error('babh6_input', 'Въведи една съставка (2–120 знака).', array('status' => 400));
    }

    /* Кеш — споделен за всички потребители, 7 дни; ключът включва версията на метода (AI-06) */
    $cache_key = 'babh6_ai_' . BABH6_AI_METHOD . '_' . md5(mb_strtolower($ingredient, 'UTF-8'));
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['status'])) {
        $cached['cached'] = 1;
        return rest_ensure_response($cached);
    }

    /* Лимити на сървъра независимо от режима на вход (AI-05). Администраторът е без лимит по IP. */
    $is_admin = current_user_can('manage_options');
    if (!$is_admin) {
        $ip = babh6_client_ip();
        $hk = 'babh6_aih_' . md5($ip);
        $dk = 'babh6_aid_' . md5($ip);
        $hmax = (int)apply_filters('babh6_ai_hour_max', 5);
        $dmax = (int)apply_filters('babh6_ai_day_max', 20);
        if ((int)get_transient($hk) >= $hmax) return new WP_Error('babh6_rate', 'Лимитът е ' . $hmax . ' нови проверки на час от един адрес. Опитай отново след час.', array('status' => 429));
        if ((int)get_transient($dk) >= $dmax) return new WP_Error('babh6_rate', 'Дневният лимит от ' . $dmax . ' нови проверки е достигнат. Опитай отново след 24 часа.', array('status' => 429));
        set_transient($hk, (int)get_transient($hk) + 1, HOUR_IN_SECONDS);
        set_transient($dk, (int)get_transient($dk) + 1, DAY_IN_SECONDS);
    }
    /* Общ дневен бюджет за целия сайт (и за администратори) */
    $gk = 'babh6_ai_global_' . gmdate('Ymd');
    $gmax = (int)apply_filters('babh6_ai_global_day_max', 300);
    if ((int)get_transient($gk) >= $gmax) return new WP_Error('babh6_rate', 'Дневният бюджет за нови AI проверки на сайта е изчерпан. Опитай отново утре.', array('status' => 429));
    set_transient($gk, (int)get_transient($gk) + 1, DAY_IN_SECONDS);

    $system = 'You are a JSON API endpoint. You MUST respond with ONLY a single valid JSON object. No text before or after. No markdown. No explanations. ONLY JSON. CRITICAL: All string values MUST be in PERFECT Bulgarian. Use ONLY standard Bulgarian vocabulary. NEVER use Russian words. NEVER transliterate English — always use the correct established Bulgarian term. Examples: novel food = нова храна, approved = одобрен, whey protein isolate = суроватъчен протеинов изолат, authorized = разрешен. You MUST use the web_search tool to find the relevant entries before answering. If you cannot find a reliable source that confirms the status, use status "unknown".';
    $user = 'Провери статуса на "' . $ingredient . '" като съставка за хранителни добавки в ЕС. Търси в EU Novel Food Catalogue и Union List of Novel Foods (Reg. 2017/2470). ВАЖНО: summary_bg трябва да е на БЕЗУПРЕЧЕН български. Макс 2 изречения. Посочи конкретните намерени документи. JSON: {"status":"banned|ok|caution|pending|unknown","summary_bg":"кратко на български","source":"име на документа","source_url":"URL на документа","confidence":"high|medium|low"}';

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 45,
        'headers' => array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => wp_json_encode(array(
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 600,
            'system'     => $system,
            'tools'      => array(array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4)),
            'messages'   => array(array('role' => 'user', 'content' => $user)),
        )),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('babh6_ai_http', 'Проверката не завърши: няма връзка с AI услугата (' . $response->get_error_message() . '). Опитай отново.', array('status' => 502));
    }
    $code = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($code !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
        return new WP_Error('babh6_ai_err', 'Проверката не завърши: AI услугата върна грешка (' . $msg . '). Опитай отново.', array('status' => 502));
    }

    /* Текст + проверими източници от web_search (AI-01) */
    $text = ''; $sources = array();
    $add_src = function ($url, $title) use (&$sources) {
        $url = esc_url_raw((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) return;
        foreach ($sources as $s) { if ($s['url'] === $url) return; }
        if (count($sources) >= 6) return;
        $sources[] = array('url' => $url, 'title' => mb_substr(sanitize_text_field((string)$title), 0, 140, 'UTF-8'));
    };
    if (!empty($body['content']) && is_array($body['content'])) {
        foreach ($body['content'] as $block) {
            $type = isset($block['type']) ? $block['type'] : '';
            if ($type === 'text') {
                $text .= isset($block['text']) ? $block['text'] : '';
                if (!empty($block['citations']) && is_array($block['citations'])) {
                    foreach ($block['citations'] as $c) { if (!empty($c['url'])) $add_src($c['url'], isset($c['title']) ? $c['title'] : ''); }
                }
            } elseif ($type === 'web_search_tool_result' && !empty($block['content']) && is_array($block['content'])) {
                foreach ($block['content'] as $r) { if (isset($r['type']) && $r['type'] === 'web_search_result' && !empty($r['url'])) $add_src($r['url'], isset($r['title']) ? $r['title'] : ''); }
            }
        }
    }
    $text = trim($text);
    $stop = isset($body['stop_reason']) ? (string)$body['stop_reason'] : '';
    if ($text === '' || $stop === 'max_tokens') {
        return new WP_Error('babh6_ai_invalid', 'Проверката не завърши: отговорът е непълен. Опитай отново.', array('status' => 502));
    }

    /* JSON извличане — строг договор; при неуспех НЕ се измисля резултат и нищо не се кешира (AI-04) */
    $result = null;
    $clean  = trim(preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text));
    $try    = json_decode($clean, true);
    if (is_array($try) && isset($try['status'])) $result = $try;
    if (!$result && preg_match_all('/\{[^{}]*"status"\s*:\s*"[^"]*"[^{}]*\}/s', $text, $m)) {
        foreach (array_reverse($m[0]) as $candidate) {
            $try = json_decode($candidate, true);
            if (is_array($try) && isset($try['status'])) { $result = $try; break; }
        }
    }
    $labels = babh6_ai_labels();
    if (!$result || !isset($labels[(string)$result['status']]) || trim((string)($result['summary_bg'] ?? '')) === '') {
        return new WP_Error('babh6_ai_invalid', 'Проверката не завърши: AI услугата не върна валиден структуриран резултат. Опитай отново.', array('status' => 502));
    }
    if (!empty($result['source_url'])) $add_src($result['source_url'], isset($result['source']) ? $result['source'] : '');

    $status = (string)$result['status'];
    /* Категоричен статус без нито един проверим документ → не се представя като установен (AI-01) */
    if (!$sources && in_array($status, array('banned', 'ok'), true)) $status = 'unknown';
    $conf = (string)($result['confidence'] ?? 'low');
    if (!in_array($conf, array('high', 'medium', 'low'), true)) $conf = 'low';

    $out = array(
        'status'     => $status,
        'label_bg'   => $labels[$status],
        'summary_bg' => mb_substr(sanitize_text_field((string)$result['summary_bg']), 0, 600, 'UTF-8'),
        'source'     => mb_substr(sanitize_text_field((string)($result['source'] ?? '')), 0, 200, 'UTF-8'),
        'sources'    => $sources,
        'confidence' => $conf,
        'ingredient' => $ingredient,
        'checked_at' => date_i18n('d.m.Y H:i'),
        'method'     => BABH6_AI_METHOD,
    );

    set_transient($cache_key, $out, 7 * DAY_IN_SECONDS);
    return rest_ensure_response($out);
}

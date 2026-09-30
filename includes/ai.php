<?php
if (!defined('ABSPATH')) exit;

/**
 * AI (Novel Food проверка) + парола за достъп.
 * Портнато от v5.6 с корекции: server-side gate на всички endpoint-и,
 * HMAC cookie вместо md5+статична сол, rate limit на AI проверките.
 */

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
function babh6_auth_hash($pw) {
    return hash_hmac('sha256', 'babh6|' . $pw, wp_salt('auth'));
}
function babh6_gate_ok() {
    $pw = babh6_password();
    if ($pw === '') return true;
    if (current_user_can('manage_options')) return true;
    $c = isset($_COOKIE['babh6_auth']) ? (string)$_COOKIE['babh6_auth'] : '';
    return $c !== '' && hash_equals(babh6_auth_hash($pw), $c);
}
/** permission_callback за data endpoint-ите */
function babh6_rest_permission() {
    if (babh6_gate_ok()) return true;
    return new WP_Error('babh6_locked', 'Достъпът изисква парола.', array('status' => 401));
}

/* ============ REST: /auth + /novel-check ============ */
add_action('rest_api_init', function () {
    register_rest_route('babh6/v1', '/auth', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_auth', 'permission_callback' => '__return_true',
    ));
    register_rest_route('babh6/v1', '/novel-check', array(
        'methods' => 'POST', 'callback' => 'babh6_rest_novel_check', 'permission_callback' => 'babh6_rest_permission',
    ));
});

function babh6_rest_auth($req) {
    $pw = babh6_password();
    if ($pw === '') return rest_ensure_response(array('ok' => 1));

    $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $key = 'babh6_auth_' . md5($ip);
    $n   = (int)get_transient($key);
    if ($n > 20) return new WP_Error('babh6_rate', 'Твърде много опити — опитай след час.', array('status' => 429));
    set_transient($key, $n + 1, HOUR_IN_SECONDS);

    $input = (string)$req->get_param('password');
    if (!hash_equals($pw, $input)) {
        return new WP_Error('babh6_wrongpw', 'Грешна парола.', array('status' => 403));
    }
    setcookie('babh6_auth', babh6_auth_hash($pw), array(
        'expires'  => time() + 30 * DAY_IN_SECONDS,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    return rest_ensure_response(array('ok' => 1));
}

function babh6_rest_novel_check($req) {
    $api_key = babh6_api_key();
    if ($api_key === '') {
        return new WP_Error('babh6_noai', 'AI проверката не е конфигурирана.', array('status' => 503));
    }

    $ingredient = sanitize_text_field((string)$req->get_param('ingredient'));
    $ingredient = trim(preg_replace('/\s+/u', ' ', $ingredient));
    if ($ingredient === '' || mb_strlen($ingredient, 'UTF-8') < 2 || mb_strlen($ingredient, 'UTF-8') > 120) {
        return new WP_Error('babh6_input', 'Въведи съставка (2–120 знака).', array('status' => 400));
    }

    /* Кеш — споделен за всички потребители, 7 дни (както в v5.6) */
    $cache_key = 'babh6_ai_' . md5(mb_strtolower($ingredient, 'UTF-8'));
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        $cached['cached'] = 1;
        return rest_ensure_response($cached);
    }

    /* Rate limit само когато сайтът е публичен (без парола) — иначе гейтът пази */
    if (babh6_password() === '' && !current_user_can('manage_options')) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $hk = 'babh6_aih_' . md5($ip);
        $dk = 'babh6_aid_' . md5($ip);
        if ((int)get_transient($hk) >= 5)  return new WP_Error('babh6_rate', 'Лимит: 5 AI проверки на час. Опитай по-късно.', array('status' => 429));
        if ((int)get_transient($dk) >= 20) return new WP_Error('babh6_rate', 'Дневен лимит достигнат. Опитай утре.', array('status' => 429));
        set_transient($hk, (int)get_transient($hk) + 1, HOUR_IN_SECONDS);
        set_transient($dk, (int)get_transient($dk) + 1, DAY_IN_SECONDS);
    }

    /* Промптът от v5.6 — 1:1, с реалните корекции срещу русизми */
    $system = 'You are a JSON API endpoint. You MUST respond with ONLY a single valid JSON object. No text before or after. No markdown. No explanations. No thinking. ONLY JSON. CRITICAL: All string values MUST be in PERFECT Bulgarian. Use ONLY standard Bulgarian vocabulary. NEVER use Russian words. NEVER transliterate English — always use the correct established Bulgarian term. Examples: novel food = нова храна, approved = одобрен, whey protein isolate = суроватъчен протеинов изолат, authorized = разрешен.';
    $user = 'Провери статуса на "' . $ingredient . '" като съставка за хранителни добавки в ЕС. Търси в EU Novel Food Catalogue и Union List of Novel Foods. ВАЖНО: summary_bg трябва да е на БЕЗУПРЕЧЕН български. Забранени са руски думи (новотрапорт→нова храна, разрешённый→разрешен). Макс 2 изречения. JSON: {"status":"banned|ok|caution|pending","label_bg":"ЗАБРАНЕН|РАЗРЕШЕН|ВНИМАНИЕ|ЧАКА ОДОБРЕНИЕ","summary_bg":"кратко на български","source":"източник","confidence":"high|medium|low"}';

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 45,
        'headers' => array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => wp_json_encode(array(
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 400,
            'system'     => $system,
            'tools'      => array(array('type' => 'web_search_20250305', 'name' => 'web_search')),
            'messages'   => array(array('role' => 'user', 'content' => $user)),
        )),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('babh6_ai_http', 'Грешка при връзка с AI: ' . $response->get_error_message(), array('status' => 502));
    }
    $code = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($code !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
        return new WP_Error('babh6_ai_err', 'AI грешка: ' . $msg, array('status' => 502));
    }

    $text = '';
    if (!empty($body['content'])) {
        foreach ($body['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') $text .= $block['text'];
        }
    }
    $text = trim($text);

    /* JSON извличане — трите стратегии от v5.6 */
    $result = null;
    $clean  = trim(preg_replace('/^```json\s*|\s*```$/s', '', $text));
    $try    = json_decode($clean, true);
    if (is_array($try) && isset($try['status'])) $result = $try;

    if (!$result) {
        if (preg_match_all('/\{[^{}]*"status"\s*:\s*"[^"]*"[^{}]*\}/s', $text, $m)) {
            foreach (array_reverse($m[0]) as $candidate) {
                $try = json_decode($candidate, true);
                if (is_array($try) && isset($try['status'])) { $result = $try; break; }
            }
        }
    }
    if (!$result) {
        $summary = preg_replace('/[{}"\'\[\]]/s', '', $text);
        $summary = mb_substr(preg_replace('/\s+/u', ' ', trim($summary)), 0, 200, 'UTF-8');
        $result  = array('status' => 'caution', 'label_bg' => 'ВНИМАНИЕ', 'summary_bg' => $summary, 'source' => 'AI', 'confidence' => 'low');
    }

    $out = array(
        'status'     => in_array($result['status'], array('banned', 'ok', 'caution', 'pending'), true) ? $result['status'] : 'caution',
        'label_bg'   => sanitize_text_field((string)($result['label_bg'] ?? 'ВНИМАНИЕ')),
        'summary_bg' => sanitize_text_field((string)($result['summary_bg'] ?? '')),
        'source'     => sanitize_text_field((string)($result['source'] ?? '')),
        'confidence' => sanitize_text_field((string)($result['confidence'] ?? 'low')),
        'ingredient' => $ingredient,
    );

    set_transient($cache_key, $out, 7 * DAY_IN_SECONDS);
    return rest_ensure_response($out);
}

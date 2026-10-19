<?php
/* Минимални WordPress stub-ове за зареждане на includes/xlsx-reader.php, includes/etl.php и includes/detect.php без WordPress.
   Само това, което тези файлове викат при зареждане и при babh6_normalize_row(). Нищо тук не „имитира“ база или HTTP:
   функциите, които биха писали ($wpdb, options), са no-op и тестовете не разчитат на тях. */
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $message; public $data; function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } function get_error_code() { return $this->code; } }
}
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof WP_Error; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('do_action')) { function do_action() {} }
if (!function_exists('apply_filters')) { function apply_filters($h, $v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option() { return true; } }
if (!function_exists('delete_option')) { function delete_option() { return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient() { return true; } }
if (!function_exists('delete_transient')) { function delete_transient() { return true; } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($s) { return $s; } }
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

<?php
/**
 * Plugin Name: De Composiet Specialist – Website
 * Description: Toont de website van De Composiet Specialist (schuttingen, prijzen, offerte-tool) als homepage. Offerteaanvragen komen binnen per e-mail én onder "Offerteaanvragen" in het beheer. Zet de plugin uit om terug te gaan naar de gewone WordPress-homepage.
 * Version: 1.4.0
 * Author: De Composiet Specialist
 * Requires PHP: 7.0
 */
if (!defined('ABSPATH')) { exit; }
require_once plugin_dir_path(__FILE__) . 'dashboard.php';

/* ---------- Offerteaanvragen bewaren in het beheer ---------- */
add_action('init', function () {
    register_post_type('dcs_offerte', array(
        'labels' => array(
            'name' => 'Offerteaanvragen',
            'singular_name' => 'Offerteaanvraag',
            'menu_name' => 'Offerteaanvragen',
            'all_items' => 'Alle aanvragen',
            'edit_item' => 'Aanvraag bekijken',
            'not_found' => 'Nog geen aanvragen',
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-email-alt',
        'menu_position' => 3,
        'supports' => array('title', 'editor'),
        'capability_type' => 'post',
        'capabilities' => array('create_posts' => 'do_not_allow'),
        'map_meta_cap' => true,
    ));
});

/* ---------- Ontvangstadres ---------- */
function dcs_ontvangers() {
    $to = array(get_option('admin_email'));
    $extra = 'info@decomposietspecialist.nl';
    if (strtolower($extra) !== strtolower($to[0])) { $to[] = $extra; }
    return array_filter(array_unique($to), 'is_email');
}

/* ---------- Formulier-endpoint ---------- */
add_action('rest_api_init', function () {
    register_rest_route('dcs/v1', '/aanvraag', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'dcs_aanvraag_ontvangen',
    ));
});

function dcs_aanvraag_ontvangen(WP_REST_Request $req) {
    $data = $req->get_json_params();
    if (!is_array($data) || count($data) > 40) {
        return new WP_REST_Response(array('success' => 'false'), 400);
    }
    if (!empty($data['_honey'])) { // spambot
        return new WP_REST_Response(array('success' => 'true'), 200);
    }

    // Simpele bescherming tegen spam: max 6 aanvragen per 10 minuten per bezoeker.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
    $key = 'dcs_rl_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 6) { return new WP_REST_Response(array('success' => 'false'), 429); }
    set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

    $subject = isset($data['_subject']) ? sanitize_text_field($data['_subject']) : 'Nieuwe aanvraag via de website';
    $reply = isset($data['_replyto']) ? sanitize_email($data['_replyto']) : '';

    $rows = '';
    $text = '';
    foreach ($data as $k => $v) {
        if (!is_string($k) || $k === '' || $k[0] === '_') { continue; }
        if (is_array($v) || is_object($v)) { continue; }
        $k = sanitize_text_field($k);
        $v = sanitize_textarea_field((string) $v);
        if (strlen($v) > 2000) { $v = substr($v, 0, 2000); }
        $rows .= '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;vertical-align:top">' . esc_html($k) . '</td><td style="padding:6px 12px;border:1px solid #ddd">' . nl2br(esc_html($v)) . '</td></tr>';
        $text .= $k . ': ' . $v . "\n";
    }
    if ($rows === '') { return new WP_REST_Response(array('success' => 'false'), 400); }

    $html = '<h2 style="font-family:Arial,sans-serif">' . esc_html($subject) . '</h2><table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px">' . $rows . '</table>';

    // 1. Altijd bewaren in het beheer, zodat er nooit iets verloren gaat.
    $post_id = wp_insert_post(array(
        'post_type' => 'dcs_offerte',
        'post_status' => 'private',
        'post_title' => $subject . ' – ' . wp_date('j F Y H:i'),
        'post_content' => $html,
    ));
    if ($post_id && !is_wp_error($post_id)) { dcs_meta_uit_formulier($post_id, $data); }

    // 2. E-mail sturen.
    $headers = array('Content-Type: text/html; charset=UTF-8');
    if ($reply && is_email($reply)) { $headers[] = 'Reply-To: ' . $reply; }
    $sent = wp_mail(dcs_ontvangers(), $subject, $html, $headers);

    if (!$post_id && !$sent) {
        return new WP_REST_Response(array('success' => 'false'), 500);
    }
    return new WP_REST_Response(array('success' => 'true'), 200);
}

/* ---------- De website als homepage tonen ---------- */
add_action('template_redirect', function () {
    if (is_admin() || !(is_front_page() || is_home())) { return; }
    if (isset($_GET['s']) || is_feed() || is_preview()) { return; }

    $file = plugin_dir_path(__FILE__) . 'site/index.html';
    if (!is_readable($file)) { return; }

    $base = plugin_dir_url(__FILE__) . 'site/';
    $html = file_get_contents($file);
    // Prijzen komen uit GitHub (aan te passen via de beheerpagina); lukt dat niet, dan gelden de prijzen in de pagina zelf.
    $prijzen = 'https://raw.githubusercontent.com/wesley351991/de-composiet-specialist/main/prijzen.json';
    $html = str_replace(
        array('"fotos/', "'fotos/", '(fotos/', '"video/', "'video/", "'prijzen.json", '"prijzen.json'),
        array('"' . $base . 'fotos/', "'" . $base . 'fotos/', '(' . $base . 'fotos/', '"' . $base . 'video/', "'" . $base . 'video/', "'" . $prijzen, '"' . $prijzen),
        $html
    );
    // Formulieren via de eigen WordPress laten lopen in plaats van FormSubmit.
    $html = str_replace("'https://formsubmit.co/ajax/'+FORM_EMAIL", "'" . esc_url_raw(rest_url('dcs/v1/aanvraag')) . "'", $html);

    nocache_headers();
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}, 1);

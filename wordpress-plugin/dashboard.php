<?php
/* Offerte-dashboard in het WordPress-beheer: statussen, planning en 2-wekenbewaking. */
if (!defined('ABSPATH')) { exit; }

function dcs_statussen() {
    return array(
        'nieuw'     => array('Nieuw', '#2F6FB3'),
        'gebeld'    => array('Gebeld', '#7A5BB5'),
        'offerte'   => array('Offerte verstuurd', '#B07A1E'),
        'akkoord'   => array('Akkoord', '#2E7D4F'),
        'ingepland' => array('Ingepland', '#1F7A80'),
        'geplaatst' => array('Geplaatst', '#5B6B3A'),
        'afgewezen' => array('Afgewezen', '#8E8478'),
    );
}
function dcs_velden() {
    return array(
        'naam' => 'Naam', 'telefoon' => 'Telefoon', 'email' => 'E-mail', 'plaats' => 'Woonplaats',
        'adres' => 'Adres', 'postcode' => 'Postcode', 'ontwerp' => 'Ontwerp', 'poort' => 'Poort',
        'prijs' => 'Prijsindicatie (€)', 'plaatsingWens' => 'Wens plaatsing', 'slopen' => 'Oude schutting slopen',
        'afvoeren' => 'Afvoeren', 'hoogteverschil' => 'Hoogteverschil',
    );
}
/* Formulierlabels van de website -> velden */
function dcs_formulier_map() {
    return array(
        'Naam' => 'naam', 'Voornaam' => 'naam', 'E-mail' => 'email', 'Telefoon' => 'telefoon', 'Adres' => 'adres',
        'Postcode' => 'postcode', 'Woonplaats' => 'plaats', 'Plaatsing' => 'plaatsingWens', 'Poort' => 'poort',
        'Oude schutting slopen' => 'slopen', 'Afvoeren' => 'afvoeren', 'Hoogteverschil' => 'hoogteverschil',
        'Ontwerp' => 'ontwerp', 'Prijsindicatie (incl. plaatsing)' => 'prijsTekst',
    );
}

/* Na een nieuwe aanvraag: velden en status vastleggen */
function dcs_meta_uit_formulier($post_id, $data) {
    $map = dcs_formulier_map();
    $is_review = isset($data['Soort']) && stripos((string) $data['Soort'], 'review') !== false;
    update_post_meta($post_id, 'dcs_soort', $is_review ? 'review' : 'offerte');
    if ($is_review) { return; }
    foreach ($map as $label => $key) {
        if (!isset($data[$label]) || is_array($data[$label])) { continue; }
        $v = sanitize_text_field((string) $data[$label]);
        if ($v === '-') { $v = ''; }
        if ($key === 'prijsTekst') {
            if (preg_match('/[\d.]+(,\d+)?/', $v, $m)) {
                update_post_meta($post_id, 'dcs_prijs', (float) str_replace(',', '.', str_replace('.', '', $m[0])));
            }
            continue;
        }
        update_post_meta($post_id, 'dcs_' . $key, $v);
    }
    update_post_meta($post_id, 'dcs_status', 'nieuw');
}

/* Alle offerte-aanvragen ophalen als eenvoudige rijen */
function dcs_rijen() {
    $posts = get_posts(array('post_type' => 'dcs_offerte', 'post_status' => array('private', 'publish', 'draft'), 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC'));
    $rows = array();
    foreach ($posts as $p) {
        if (get_post_meta($p->ID, 'dcs_soort', true) === 'review') { continue; }
        $r = array('id' => $p->ID, 'datum' => get_post_time('U', true, $p));
        foreach (array_keys(dcs_velden()) as $k) { $r[$k] = get_post_meta($p->ID, 'dcs_' . $k, true); }
        if ($r['naam'] === '') { $r['naam'] = $p->post_title; }
        $r['status'] = get_post_meta($p->ID, 'dcs_status', true) ?: 'nieuw';
        $r['plaatsingDatum'] = get_post_meta($p->ID, 'dcs_plaatsingDatum', true);
        $r['akkoordOp'] = (int) get_post_meta($p->ID, 'dcs_akkoordOp', true);
        $r['notitie'] = get_post_meta($p->ID, 'dcs_notitie', true);
        $rows[] = $r;
    }
    return $rows;
}

/* ---------- Menu ---------- */
add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=dcs_offerte', 'Offerte dashboard', 'Dashboard', 'edit_posts', 'dcs-dashboard', 'dcs_dashboard_pagina', 0);
});
/* Na inloggen en bij klikken op het hoofdmenu direct naar het dashboard */
add_action('admin_menu', function () {
    global $menu;
    if (!is_array($menu)) { return; }
    foreach ($menu as $i => $m) {
        if (isset($m[2]) && $m[2] === 'edit.php?post_type=dcs_offerte') { $menu[$i][2] = 'edit.php?post_type=dcs_offerte&page=dcs-dashboard'; }
    }
}, 99);

/* Melding op het gewone WP-dashboard */
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget('dcs_widget', 'Offerteaanvragen', function () {
        $rows = dcs_rijen(); $n = 0; $late = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'nieuw') { $n++; }
            if ($r['status'] === 'akkoord' && !$r['plaatsingDatum'] && $r['akkoordOp'] && (time() - $r['akkoordOp']) / DAY_IN_SECONDS >= 10) { $late++; }
        }
        echo '<p style="font-size:15px"><b>' . (int) $n . '</b> nieuwe aanvra' . ($n === 1 ? 'ag' : 'gen') . '.';
        if ($late) { echo ' <span style="color:#B3261E"><b>' . (int) $late . '</b> bijna over de 2-wekenbelofte!</span>'; }
        echo '</p><p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=dcs_offerte&page=dcs-dashboard')) . '">Open offerte dashboard</a></p>';
    });
});

/* ---------- Opslaan vanuit het dashboard ---------- */
add_action('admin_post_dcs_opslaan', function () {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if (!$id || !current_user_can('edit_post', $id) || !check_admin_referer('dcs_opslaan_' . $id)) { wp_die('Geen toegang'); }
    $st = dcs_statussen();
    $status = isset($_POST['status']) && isset($st[$_POST['status']]) ? $_POST['status'] : 'nieuw';
    $oud = get_post_meta($id, 'dcs_status', true);
    update_post_meta($id, 'dcs_status', $status);
    if (in_array($status, array('akkoord', 'ingepland', 'geplaatst'), true) && !get_post_meta($id, 'dcs_akkoordOp', true)) {
        update_post_meta($id, 'dcs_akkoordOp', time());
    }
    $datum = isset($_POST['plaatsingDatum']) ? sanitize_text_field($_POST['plaatsingDatum']) : '';
    update_post_meta($id, 'dcs_plaatsingDatum', preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : '');
    update_post_meta($id, 'dcs_notitie', isset($_POST['notitie']) ? sanitize_textarea_field(wp_unslash($_POST['notitie'])) : '');
    foreach (array_keys(dcs_velden()) as $k) {
        if (!isset($_POST['f_' . $k])) { continue; }
        $v = sanitize_text_field(wp_unslash($_POST['f_' . $k]));
        if ($k === 'prijs') { $v = $v === '' ? '' : (float) str_replace(',', '.', str_replace('.', '', $v)); }
        update_post_meta($id, 'dcs_' . $k, $v);
    }
    $tab = isset($_POST['tab']) ? sanitize_key($_POST['tab']) : 'a';
    wp_safe_redirect(admin_url('edit.php?post_type=dcs_offerte&page=dcs-dashboard&tab=' . $tab . '&opgeslagen=1'));
    exit;
});

/* ---------- De pagina ---------- */
function dcs_eur($v) { return '€ ' . number_format((float) $v, 0, ',', '.'); }
function dcs_dagen($ts) { return $ts ? (int) floor((time() - $ts) / DAY_IN_SECONDS) : 0; }

function dcs_rij_html($r, $tab) {
    $st = dcs_statussen();
    $kleur = isset($st[$r['status']]) ? $st[$r['status']][1] : '#888';
    $label = isset($st[$r['status']]) ? $st[$r['status']][0] : $r['status'];
    $flag = '';
    if ($r['status'] === 'akkoord' && !$r['plaatsingDatum']) {
        $d = dcs_dagen($r['akkoordOp']);
        if ($d >= 14) { $flag = '<span class="dcs-flag crit">' . $d . ' dagen sinds akkoord</span>'; }
        elseif ($d >= 10) { $flag = '<span class="dcs-flag">' . $d . ' dagen sinds akkoord</span>'; }
        elseif ($d) { $flag = '<span class="dcs-note">' . $d . ' dag' . ($d === 1 ? '' : 'en') . ' sinds akkoord</span>'; }
    }
    $tel = preg_replace('/[^\d+]/', '', (string) $r['telefoon']);
    $wa = preg_replace('/^0/', '31', ltrim($tel, '+'));
    $meta = implode(' · ', array_filter(array($r['plaats'], $r['ontwerp'])));
    $h = '<details class="dcs-row" style="--c:' . esc_attr($kleur) . '"><summary>';
    $h .= '<span class="dcs-badge">' . esc_html($label) . '</span>';
    $h .= '<span class="dcs-main"><b>' . esc_html($r['naam']) . '</b><small>' . esc_html($meta) . '</small>' . $flag . '</span>';
    $h .= '<span class="dcs-side">' . ($r['prijs'] !== '' ? '<b>' . esc_html(dcs_eur($r['prijs'])) . '</b>' : '') . '<small>' . esc_html($r['plaatsingDatum'] ? 'Plaatsing ' . wp_date('D j M', strtotime($r['plaatsingDatum'] . ' 12:00')) : 'Binnen ' . wp_date('j M', $r['datum'])) . '</small></span>';
    $h .= '</summary><div class="dcs-body">';
    if ($tel) {
        $h .= '<p class="dcs-contact"><a class="button" href="tel:' . esc_attr($tel) . '">Bel ' . esc_html($r['telefoon']) . '</a> <a class="button" target="_blank" rel="noopener" href="https://wa.me/' . esc_attr($wa) . '">WhatsApp</a>';
        if ($r['email']) { $h .= ' <a class="button" href="mailto:' . esc_attr($r['email']) . '">Mail</a>'; }
        $h .= '</p>';
    }
    $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $h .= '<input type="hidden" name="action" value="dcs_opslaan"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    $h .= wp_nonce_field('dcs_opslaan_' . $r['id'], '_wpnonce', true, false);
    $h .= '<div class="dcs-grid"><label>Status<select name="status">';
    foreach ($st as $k => $s) { $h .= '<option value="' . esc_attr($k) . '"' . selected($r['status'], $k, false) . '>' . esc_html($s[0]) . '</option>'; }
    $h .= '</select></label><label>Plaatsingsdatum<input type="date" name="plaatsingDatum" value="' . esc_attr($r['plaatsingDatum']) . '"></label>';
    foreach (dcs_velden() as $k => $l) {
        $full = in_array($k, array('ontwerp', 'poort', 'hoogteverschil'), true) ? ' class="full"' : '';
        $h .= '<label' . $full . '>' . esc_html($l) . '<input type="text" name="f_' . esc_attr($k) . '" value="' . esc_attr($r[$k]) . '"></label>';
    }
    $h .= '<label class="full">Notitie<textarea name="notitie" rows="3">' . esc_textarea($r['notitie']) . '</textarea></label></div>';
    $h .= '<p><button class="button button-primary">Opslaan</button></p></form></div></details>';
    return $h;
}

function dcs_dashboard_pagina() {
    $rows = dcs_rijen();
    $st = dcs_statussen();
    $tab = isset($_GET['tab']) && $_GET['tab'] === 'p' ? 'p' : 'a';
    $filter = isset($_GET['f']) ? sanitize_key($_GET['f']) : 'open';
    $c = array_fill_keys(array_keys($st), 0);
    foreach ($rows as $r) { if (isset($c[$r['status']])) { $c[$r['status']]++; } }

    $maandag = strtotime('monday this week', current_time('timestamp'));
    $volgende = $maandag + 7 * DAY_IN_SECONDS;
    $in_week = function ($r, $van, $tot) { if (!$r['plaatsingDatum'] || $r['status'] === 'afgewezen') { return false; } $t = strtotime($r['plaatsingDatum'] . ' 12:00'); return $t >= $van && $t < $tot; };

    $teplannen = array_values(array_filter($rows, function ($r) { return $r['status'] === 'akkoord' && !$r['plaatsingDatum']; }));
    $alert = false; foreach ($teplannen as $r) { if (dcs_dagen($r['akkoordOp']) >= 10) { $alert = true; } }
    $deze_week = count(array_filter($rows, function ($r) use ($in_week, $maandag, $volgende) { return $in_week($r, $maandag, $volgende); }));
    $waarde = 0; foreach ($rows as $r) { if (in_array($r['status'], array('akkoord', 'ingepland', 'geplaatst'), true)) { $waarde += (float) $r['prijs']; } }

    $url = admin_url('edit.php?post_type=dcs_offerte&page=dcs-dashboard');
    ?>
    <style>
    .dcs{max-width:1100px;font-size:14px}
    .dcs h1{font-size:26px;font-weight:800;margin:10px 0 14px}
    .dcs-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
    .dcs-kpi{background:#fff;border:1px solid #DED3C2;border-radius:10px;padding:12px 14px}
    .dcs-kpi small{display:block;color:#6E6457;font-weight:600}.dcs-kpi strong{font-size:26px;line-height:1.2}
    .dcs-kpi.alert{border-color:#B26B00}.dcs-kpi.alert strong{color:#B26B00}
    .dcs-tabs{display:flex;gap:4px;border-bottom:1px solid #DED3C2;margin-bottom:12px}
    .dcs-tabs a{padding:10px 14px;font-weight:700;color:#6E6457;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-1px}
    .dcs-tabs a.on{color:#1F1A16;border-bottom-color:#C49A62}
    .dcs-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
    .dcs-chips a{border:1px solid #DED3C2;background:#fff;border-radius:999px;padding:5px 12px;text-decoration:none;color:#1F1A16}
    .dcs-chips a.on{background:#1F1A16;color:#fff;border-color:#1F1A16}
    .dcs-row{background:#fff;border:1px solid #DED3C2;border-left:5px solid var(--c);border-radius:10px;margin-bottom:8px}
    .dcs-row summary{display:flex;gap:12px;align-items:center;padding:12px 14px;cursor:pointer;list-style:none}
    .dcs-row summary::-webkit-details-marker{display:none}
    .dcs-badge{background:var(--c);color:#fff;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;white-space:nowrap}
    .dcs-main{flex:1;min-width:0}.dcs-main b{display:block;font-size:15px}.dcs-main small,.dcs-side small{color:#6E6457;display:block}
    .dcs-side{text-align:right;white-space:nowrap}
    .dcs-flag{display:inline-block;margin-top:4px;background:#FFF1DD;color:#B26B00;border-radius:6px;padding:2px 8px;font-weight:700;font-size:12px}
    .dcs-flag.crit{background:#FCE4E2;color:#B3261E}.dcs-note{display:block;color:#6E6457;font-size:12px;margin-top:2px}
    .dcs-body{padding:0 14px 14px;border-top:1px solid #EFE8DC}
    .dcs-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-top:12px}
    .dcs-grid label{display:flex;flex-direction:column;font-weight:600;gap:4px}.dcs-grid .full{grid-column:1/-1}
    .dcs-grid input,.dcs-grid select,.dcs-grid textarea{width:100%;max-width:none}
    .dcs-week{border:1px solid #DED3C2;border-radius:10px;padding:10px 12px;margin-bottom:12px;background:#FBF8F3}
    .dcs-week h3{margin:0 0 8px;font-size:15px}.dcs-week h3 small{color:#6E6457;font-weight:400}
    .dcs-empty{color:#6E6457;padding:10px 0}
    @media(max-width:600px){.dcs-grid{grid-template-columns:1fr}.dcs-row summary{flex-wrap:wrap}.dcs-side{text-align:left}}
    </style>
    <div class="wrap dcs">
    <h1>Offerte dashboard</h1>
    <?php if (isset($_GET['opgeslagen'])) { echo '<div class="notice notice-success is-dismissible"><p>Opgeslagen.</p></div>'; } ?>
    <div class="dcs-kpis">
        <div class="dcs-kpi"><small>Nieuw</small><strong><?php echo (int) $c['nieuw']; ?></strong></div>
        <div class="dcs-kpi"><small>Lopend (gebeld / offerte)</small><strong><?php echo (int) ($c['gebeld'] + $c['offerte']); ?></strong></div>
        <div class="dcs-kpi<?php echo $alert ? ' alert' : ''; ?>"><small>Nog in te plannen</small><strong><?php echo count($teplannen); ?></strong></div>
        <div class="dcs-kpi"><small>Deze week plaatsen</small><strong><?php echo (int) $deze_week; ?></strong></div>
        <div class="dcs-kpi"><small>Opdrachtwaarde</small><strong><?php echo esc_html(dcs_eur($waarde)); ?></strong></div>
    </div>
    <div class="dcs-tabs">
        <a href="<?php echo esc_url($url . '&tab=a'); ?>" class="<?php echo $tab === 'a' ? 'on' : ''; ?>">Aanvragen</a>
        <a href="<?php echo esc_url($url . '&tab=p'); ?>" class="<?php echo $tab === 'p' ? 'on' : ''; ?>">Planning</a>
    </div>
    <?php
    if ($tab === 'a') {
        $lopend = count(array_filter($rows, function ($r) { return !in_array($r['status'], array('geplaatst', 'afgewezen'), true); }));
        $chips = array(array('open', 'Lopend', $lopend), array('alle', 'Alle', count($rows)));
        foreach ($st as $k => $s) { $chips[] = array($k, $s[0], $c[$k]); }
        echo '<div class="dcs-chips">';
        foreach ($chips as $ch) { echo '<a class="' . ($filter === $ch[0] ? 'on' : '') . '" href="' . esc_url($url . '&tab=a&f=' . $ch[0]) . '">' . esc_html($ch[1]) . ' <b>' . (int) $ch[2] . '</b></a>'; }
        echo '</div>';
        $lijst = array_filter($rows, function ($r) use ($filter) {
            if ($filter === 'alle') { return true; }
            if ($filter === 'open') { return !in_array($r['status'], array('geplaatst', 'afgewezen'), true); }
            return $r['status'] === $filter;
        });
        if (!$lijst) { echo '<p class="dcs-empty">Nog geen aanvragen hier. Nieuwe aanvragen van je website verschijnen automatisch.</p>'; }
        foreach ($lijst as $r) { echo dcs_rij_html($r, 'a'); }
    } else {
        usort($teplannen, function ($a, $b) { return $a['akkoordOp'] - $b['akkoordOp']; });
        echo '<div class="dcs-week" style="border-color:#2E7D4F"><h3>Nog in te plannen <small>akkoord, nog geen datum · belofte: binnen 2 weken geplaatst</small></h3>';
        echo $teplannen ? implode('', array_map(function ($r) { return dcs_rij_html($r, 'p'); }, $teplannen)) : '<p class="dcs-empty">Alles is ingepland.</p>';
        echo '</div>';
        $te_laat = array_filter($rows, function ($r) use ($maandag) { return $r['plaatsingDatum'] && !in_array($r['status'], array('geplaatst', 'afgewezen'), true) && strtotime($r['plaatsingDatum'] . ' 12:00') < $maandag; });
        if ($te_laat) {
            echo '<div class="dcs-week" style="border-color:#B3261E"><h3>Te laat <small>datum verstreken, nog niet geplaatst</small></h3>' . implode('', array_map(function ($r) { return dcs_rij_html($r, 'p'); }, $te_laat)) . '</div>';
        }
        for ($i = 0; $i < 6; $i++) {
            $van = $maandag + $i * 7 * DAY_IN_SECONDS; $tot = $van + 7 * DAY_IN_SECONDS;
            $klussen = array_values(array_filter($rows, function ($r) use ($in_week, $van, $tot) { return $in_week($r, $van, $tot); }));
            usort($klussen, function ($a, $b) { return strcmp($a['plaatsingDatum'], $b['plaatsingDatum']); });
            $lbl = $i === 0 ? 'Deze week' : ($i === 1 ? 'Volgende week' : 'Week ' . (int) wp_date('W', $van));
            echo '<div class="dcs-week"><h3>' . esc_html($lbl) . ' <small>' . esc_html(wp_date('j M', $van) . ' – ' . wp_date('j M', $tot - DAY_IN_SECONDS) . ' · ' . count($klussen) . ' klus' . (count($klussen) === 1 ? '' : 'sen')) . '</small></h3>';
            echo $klussen ? implode('', array_map(function ($r) { return dcs_rij_html($r, 'p'); }, $klussen)) : '<p class="dcs-empty">Geen klussen gepland.</p>';
            echo '</div>';
        }
    }
    echo '</div>';
}

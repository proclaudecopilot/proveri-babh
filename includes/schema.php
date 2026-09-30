<?php
if (!defined('ABSPATH')) exit;

function babh6_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'babh6_' . $name;
}

function babh6_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $products = babh6_table('products');
    dbDelta("CREATE TABLE $products (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reg VARCHAR(20) NOT NULL,
        rtype VARCHAR(4) NOT NULL DEFAULT '',
        ryear SMALLINT UNSIGNED NULL,
        oblast VARCHAR(40) NOT NULL DEFAULT '',
        name TEXT NOT NULL,
        purpose TEXT NULL,
        composition TEXT NULL,
        comp_hash CHAR(32) NOT NULL DEFAULT '',
        producer_name VARCHAR(500) NOT NULL DEFAULT '',
        producer_norm VARCHAR(191) NOT NULL DEFAULT '',
        producer_kind VARCHAR(10) NOT NULL DEFAULT '',
        trader_name VARCHAR(500) NOT NULL DEFAULT '',
        trader_norm VARCHAR(191) NOT NULL DEFAULT '',
        trader_kind VARCHAR(10) NOT NULL DEFAULT '',
        trader_inf_norm VARCHAR(191) NOT NULL DEFAULT '',
        trader_inf_name VARCHAR(500) NOT NULL DEFAULT '',
        storage TEXT NULL,
        notif_no VARCHAR(100) NOT NULL DEFAULT '',
        notif_date DATE NULL,
        launch_date DATE NULL,
        entry_date DATE NULL,
        deletion TEXT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other',
        flags TEXT NULL,
        flag_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        first_upload BIGINT UNSIGNED NULL,
        last_upload BIGINT UNSIGNED NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY reg (reg),
        KEY producer_norm (producer_norm),
        KEY trader_norm (trader_norm),
        KEY trader_inf_norm (trader_inf_norm),
        KEY category (category),
        KEY ryear (ryear),
        KEY flag_count (flag_count),
        KEY notif_date (notif_date),
        KEY deleted_at (deleted_at)
    ) $charset;");

    $parties = babh6_table('parties');
    dbDelta("CREATE TABLE $parties (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        kind CHAR(1) NOT NULL,
        norm VARCHAR(191) NOT NULL,
        name VARCHAR(500) NOT NULL,
        is_bg TINYINT(1) NOT NULL DEFAULT 0,
        product_count INT UNSIGNED NOT NULL DEFAULT 0,
        flagged_count INT UNSIGNED NOT NULL DEFAULT 0,
        partner_count INT UNSIGNED NOT NULL DEFAULT 0,
        inferred_count INT UNSIGNED NOT NULL DEFAULT 0,
        first_year SMALLINT UNSIGNED NULL,
        last_year SMALLINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY kindnorm (kind, norm),
        KEY product_count (product_count),
        KEY kindbg (kind, is_bg)
    ) $charset;");

    $uploads = babh6_table('uploads');
    dbDelta("CREATE TABLE $uploads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL DEFAULT '',
        uploaded_at DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'processing',
        source VARCHAR(10) NOT NULL DEFAULT 'manual',
        row_count INT UNSIGNED NOT NULL DEFAULT 0,
        added INT UNSIGNED NOT NULL DEFAULT 0,
        updated_ct INT UNSIGNED NOT NULL DEFAULT 0,
        removed INT UNSIGNED NOT NULL DEFAULT 0,
        restored INT UNSIGNED NOT NULL DEFAULT 0,
        notes TEXT NULL,
        PRIMARY KEY  (id),
        KEY uploaded_at (uploaded_at)
    ) $charset;");

    $waitlist = babh6_table('waitlist');
    dbDelta("CREATE TABLE $waitlist (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(191) NOT NULL,
        source VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email)
    ) $charset;");

    /* FULLTEXT — dbDelta не го управлява надеждно; добавяме ръчно с толериране на грешка */
    $has_ft = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE table_schema = %s AND table_name = %s AND index_name = 'ft_search'",
        DB_NAME, $products
    ));
    if (!$has_ft) {
        $wpdb->hide_errors();
        $ok = $wpdb->query("ALTER TABLE $products ADD FULLTEXT KEY ft_search (name, composition, producer_name, trader_name)");
        $wpdb->show_errors();
        update_option('babh6_fulltext', ($ok !== false) ? 1 : 0);
    } else {
        update_option('babh6_fulltext', 1);
    }
}

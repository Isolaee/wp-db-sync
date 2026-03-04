<?php
/**
 * Plugin Name: ACF DB Sync
 * Description: Ensures all existing posts have postmeta rows for every ACF field defined in their assigned field groups.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-syncer.php';
require_once __DIR__ . '/includes/class-admin-page.php';

add_action( 'plugins_loaded', function () {
    if ( ! function_exists( 'acf_get_field_groups' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>ACF DB Sync</strong> requires Advanced Custom Fields to be installed and active.</p></div>';
        } );
        return;
    }

    ACF_DB_Sync_Admin_Page::register();
} );

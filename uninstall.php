<?php
/** Remove only data created by Lightweight Translation when WordPress uninstalls it. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$table = $wpdb->prefix . 'light_translations';
$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB

$options = array(
	'lt_default_language', 'lt_secondary_languages', 'lt_url_bases', 'lt_hidden_languages',
	'lt_language_label_mode', 'lt_language_labels', 'lt_selector_show_flag', 'lt_selector_show_name',
	'lt_selector_include_default', 'lt_auto_redirect', 'lt_sticky_language', 'lt_seo_tags',
	'lt_debug_mode', 'lt_collect_strings', 'lt_admin_translation_layout', 'lt_list_column', 'lt_verify_until',
	'lt_email_catalog_ids', 'lt_string_index', 'lt_js_strings', 'lt_string_domains', 'lt_admin_text_options', 'lt_page_texts',
	'lt_site_scan', 'lt_scan_ignored', 'lt_string_seen', 'lt_woocommerce_catalog', 'lt_wc_blocks_seen',
	'lt_wc_blocks_texts', 'lt_wc_blocks_pages', 'lt_wc_blocks_scanned', 'lt_ai_context', 'lt_version',
	'lt_catalog_revision', 'lt_catalog_revisions', 'lt_status_summary_keys', 'lt_overview_progress_keys', 'lt_flush_rewrite_rules', 'lt_cache_purge_due',
);
wp_clear_scheduled_hook( 'lt_delayed_cache_purge' );
foreach ( $options as $option ) delete_option( $option );

delete_metadata( 'user', 0, 'lt_language', '', true );
delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'lt_strings_per_page', '', true );
delete_metadata( 'user', 0, 'lt_collect_texts', '', true );

$switchers = get_posts( array(
	'post_type' => 'nav_menu_item', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
	'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_lt_switcher', 'meta_value' => '1', 'suppress_filters' => true,
) );
foreach ( $switchers as $id ) wp_delete_post( $id, true );
delete_metadata( 'post', 0, '_lt_switcher', '', true );
delete_metadata( 'post', 0, '_lt_switcher_style', '', true );

$names = array( 'lt_strings_%', 'lt_wcjs_%', 'lt_content_hashes_%', 'lt_admin_text_origins', 'lt_settings_texts_read', 'lt_list_%', 'lt_status_summary_%', 'lt_overview_progress_%', 'lt_scan_known_%', 'lt_full_backup_ready_%' );
foreach ( $names as $name ) foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $prefix ) {
	$pattern = $prefix . $name;
	$like = $wpdb->esc_like( str_replace( '%', '', $pattern ) ) . ( false !== strpos( $pattern, '%' ) ? '%' : '' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
}
if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) ) $GLOBALS['wp_rewrite']->rules = null;
flush_rewrite_rules( false );

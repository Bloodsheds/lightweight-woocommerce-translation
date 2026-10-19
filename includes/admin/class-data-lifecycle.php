<?php
defined( 'ABSPATH' ) || exit;

/** Complete, portable backup and deliberate removal of data owned by this plugin. */
class LT_Data_Lifecycle {
	const BACKUP_FORMAT = 'lightweight-translation-full-backup';
	const BACKUP_REVISION = 1;
	const RESET_TOKEN = 'lt_full_backup_ready_';
	const RESET_TOKEN_TTL = 1800;
	const OPTIONS = array(
		'lt_default_language', 'lt_secondary_languages', 'lt_url_bases', 'lt_hidden_languages',
		'lt_language_label_mode', 'lt_language_labels', 'lt_selector_show_flag', 'lt_selector_show_name',
		'lt_selector_include_default', 'lt_auto_redirect', 'lt_sticky_language', 'lt_seo_tags',
		'lt_debug_mode', 'lt_collect_strings', 'lt_admin_translation_layout', 'lt_list_column', 'lt_verify_until',
		'lt_email_catalog_ids', 'lt_string_index', 'lt_js_strings', 'lt_string_domains', 'lt_admin_text_options', 'lt_page_texts',
		'lt_site_scan', 'lt_scan_ignored', 'lt_string_seen', 'lt_woocommerce_catalog', 'lt_woodmart_catalog', 'lt_wc_blocks_seen',
		'lt_wc_blocks_texts', 'lt_wc_blocks_pages', 'lt_wc_blocks_scanned',
		'lt_ai_context', 'lt_version', 'lt_flush_rewrite_rules',
	);

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'admin_post_lt_full_backup', array( $this, 'download_backup' ) );
		add_action( 'admin_post_lt_full_restore', array( $this, 'restore_backup' ) );
		add_action( 'admin_post_lt_full_reset', array( $this, 'reset' ) );
	}

	public static function backup_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=lt_full_backup' ), 'lt_full_backup' );
	}

	private function authorized( $nonce ) {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( $nonce ) ) wp_die( esc_html__( 'Unauthorized.', 'lightweight-translation' ) );
	}

	private static function options_snapshot() {
		$out = array();
		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) $out[ $name ] = $value;
		}
		return $out;
	}

	private static function switchers_snapshot() {
		$items = get_posts( array(
			'post_type' => 'nav_menu_item', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1, 'fields' => 'all', 'meta_key' => '_lt_switcher', 'meta_value' => '1', 'suppress_filters' => true,
		) );
		$out = array();
		foreach ( $items as $item ) {
			$menus = wp_get_object_terms( $item->ID, 'nav_menu', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $menus ) || ! $menus ) continue;
			$menu_item = wp_setup_nav_menu_item( $item );
			$out[] = array(
				'id' => (int) $item->ID, 'menu_id' => (int) $menus[0], 'title' => (string) $menu_item->title,
				'url' => (string) $menu_item->url, 'type' => (string) ( $menu_item->type ?: 'custom' ),
				'object' => (string) ( $menu_item->object ?? '' ), 'object_id' => (int) ( $menu_item->object_id ?? 0 ),
				'parent' => (int) ( $menu_item->menu_item_parent ?? 0 ), 'position' => (int) $item->menu_order,
				'status' => (string) $item->post_status, 'target' => (string) ( $menu_item->target ?? '' ),
				'attr_title' => (string) ( $menu_item->attr_title ?? '' ), 'description' => (string) ( $menu_item->description ?? '' ),
				'xfn' => (string) ( $menu_item->xfn ?? '' ), 'classes' => array_values( array_filter( (array) ( $menu_item->classes ?? array() ), 'is_string' ) ),
				'style' => in_array( get_post_meta( $item->ID, '_lt_switcher_style', true ), array( 'plain', 'list' ), true ) ? get_post_meta( $item->ID, '_lt_switcher_style', true ) : 'boxed',
			);
		}
		return $out;
	}

	private static function language_preferences() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE meta_key IN (%s,%s)", 'lt_language', $wpdb->get_blog_prefix() . 'lt_strings_per_page' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$row ) if ( 'lt_language' !== $row['meta_key'] ) $row['meta_key'] = 'lt_strings_per_page';
		return $rows;
	}

	private static function snapshot() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT language_code, object_type, object_id, field_key, source_text, translated_text, source_hash, status, updated_at FROM ' . LT_Translation_Store::table() . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! empty( $wpdb->last_error ) ) wp_die( esc_html__( 'Could not read translation data for the backup.', 'lightweight-translation' ) );
		return array(
			'format' => self::BACKUP_FORMAT, 'revision' => self::BACKUP_REVISION, 'plugin_version' => LT_VERSION,
			'created_at_utc' => gmdate( 'c' ), 'home_url' => home_url( '/' ),
			'translations' => (array) $rows, 'options' => self::options_snapshot(),
			'language_preferences' => self::language_preferences(), 'menu_switchers' => self::switchers_snapshot(),
		);
	}

	public function download_backup() {
		$this->authorized( 'lt_full_backup' );
		$data = wp_json_encode( self::snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $data ) || '' === $data ) wp_die( esc_html__( 'Could not create the full backup.', 'lightweight-translation' ) );
		set_transient( self::RESET_TOKEN . get_current_user_id(), wp_hash( $data ), self::RESET_TOKEN_TTL );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lightweight-translation-backup-' . gmdate( 'Y-m-d-His' ) . '.json' );
		header( 'Content-Length: ' . strlen( $data ) );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function valid_snapshot( $data ) {
		return is_array( $data ) && self::BACKUP_FORMAT === ( $data['format'] ?? '' ) && self::BACKUP_REVISION === ( $data['revision'] ?? 0 )
			&& isset( $data['translations'], $data['options'], $data['language_preferences'], $data['menu_switchers'] )
			&& is_array( $data['translations'] ) && is_array( $data['options'] ) && is_array( $data['language_preferences'] ) && is_array( $data['menu_switchers'] )
			&& count( $data['translations'] ) <= 500000 && count( $data['language_preferences'] ) <= 100000 && count( $data['menu_switchers'] ) <= 10000;
	}

	private static function valid_row( $row ) {
		if ( ! is_array( $row ) ) return false;
		$language = sanitize_key( $row['language_code'] ?? '' );
		$type     = sanitize_key( $row['object_type'] ?? '' );
		$field    = sanitize_key( $row['field_key'] ?? '' );
		$id       = absint( $row['object_id'] ?? 0 );
		if ( ! $language || strlen( $language ) > 12 || ! $type || strlen( $type ) > 24 || ! $field || strlen( $field ) > 191 ) return false;
		if ( ! is_string( $row['source_text'] ?? null ) || ! is_string( $row['translated_text'] ?? null ) ) return false;
		if ( ! in_array( $row['status'] ?? '', array( 'empty', 'translated', 'reviewed', 'partial' ), true ) ) return false;
		if ( in_array( $type, array( 'string', 'email' ), true ) ) return 0 === $id;
		if ( 'term' === $type ) return $id && get_term( $id ) instanceof WP_Term;
		if ( 'attribute' === $type ) return $id && function_exists( 'wc_get_attribute' ) && wc_get_attribute( $id );
		$post = $id ? get_post( $id ) : null;
		return $post instanceof WP_Post && $post->post_type === $type;
	}

	private static function restore_switchers( $switchers ) {
		$map = array();
		foreach ( $switchers as $item ) {
			if ( ! is_array( $item ) ) continue;
			$menu_id = absint( $item['menu_id'] ?? 0 );
			if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) continue;
			$args = array(
				'menu-item-title' => sanitize_text_field( $item['title'] ?? __( 'Language', 'lightweight-translation' ) ),
				'menu-item-url' => esc_url_raw( $item['url'] ?? '#lt-language-switcher' ),
				'menu-item-status' => in_array( $item['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $item['status'] : 'publish',
				'menu-item-type' => 'custom' === ( $item['type'] ?? 'custom' ) ? 'custom' : 'custom',
				'menu-item-position' => absint( $item['position'] ?? 1 ), 'menu-item-parent-id' => 0,
				'menu-item-target' => sanitize_text_field( $item['target'] ?? '' ),
				'menu-item-attr-title' => sanitize_text_field( $item['attr_title'] ?? '' ),
				'menu-item-description' => sanitize_textarea_field( $item['description'] ?? '' ),
				'menu-item-classes' => array_map( 'sanitize_html_class', (array) ( $item['classes'] ?? array() ) ),
				'menu-item-xfn' => sanitize_text_field( $item['xfn'] ?? '' ),
			);
			$id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! $id || is_wp_error( $id ) ) continue;
			update_post_meta( $id, '_lt_switcher', '1' );
			$style = $item['style'] ?? '';
			update_post_meta( $id, '_lt_switcher_style', in_array( $style, array( 'plain', 'list' ), true ) ? $style : 'boxed' );
			if ( ! empty( $item['id'] ) ) $map[ absint( $item['id'] ) ] = (int) $id;
		}
		foreach ( $switchers as $item ) {
			$old_id = absint( $item['id'] ?? 0 );
			$new_id = $map[ $old_id ] ?? 0;
			$parent = absint( $item['parent'] ?? 0 );
			if ( $new_id ) update_post_meta( $new_id, '_menu_item_menu_item_parent', $map[ $parent ] ?? 0 );
		}
		return $map;
	}

	public function restore_backup() {
		$this->authorized( 'lt_full_restore' );
		if ( empty( $_POST['confirm_restore'] ) || 'yes' !== sanitize_key( wp_unslash( $_POST['confirm_restore'] ) ) ) wp_die( esc_html__( 'Confirm that you want to restore this backup.', 'lightweight-translation' ) );
		if ( empty( $_FILES['lt_full_backup']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $_FILES['lt_full_backup']['error'] ?? UPLOAD_ERR_NO_FILE ) ) wp_die( esc_html__( 'Choose a valid backup file.', 'lightweight-translation' ) );
		$tmp = $_FILES['lt_full_backup']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_uploaded_file( $tmp ) ) wp_die( esc_html__( 'Invalid backup upload.', 'lightweight-translation' ) );
		$data = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! self::valid_snapshot( $data ) ) wp_die( esc_html__( 'This is not a supported Lightweight Translation full backup.', 'lightweight-translation' ) );
		foreach ( self::OPTIONS as $name ) if ( array_key_exists( $name, $data['options'] ) ) update_option( $name, $data['options'][ $name ], false );
		$router = LT_Language_Router::instance();
		$valid_languages = array_fill_keys( $router->languages(), true );
		$translation_table = LT_Translation_Store::table();
		$wpdb_error = false;
		$wpdb_rows = 0;
		foreach ( self::switcher_ids() as $old_switcher_id ) wp_delete_post( $old_switcher_id, true );
		$remap = self::restore_switchers( $data['menu_switchers'] );
		global $wpdb;
		// Same rule as LT_Translation_Store::save(): without unfiltered_html, restored translations are cleaned like any saved one.
		$unfiltered = current_user_can( 'unfiltered_html' );
		foreach ( $data['translations'] as $row ) {
			if ( ! self::valid_row( $row ) || ! isset( $valid_languages[ sanitize_key( $row['language_code'] ) ] ) ) continue;
			$type = sanitize_key( $row['object_type'] );
			$id = absint( $row['object_id'] );
			if ( 'nav_menu_item' === $type && isset( $remap[ $id ] ) ) $id = $remap[ $id ];
			$values = array(
				sanitize_key( $row['language_code'] ), $type, $id, sanitize_key( $row['field_key'] ),
				$row['source_text'], $unfiltered ? $row['translated_text'] : wp_kses_post( $row['translated_text'] ), LT_Translation_Store::hash( $row['source_text'] ),
				$row['status'], sanitize_text_field( $row['updated_at'] ?? current_time( 'mysql' ) ),
			);
			$format = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
			if ( false === $wpdb->replace( $translation_table, array_combine( array( 'language_code', 'object_type', 'object_id', 'field_key', 'source_text', 'translated_text', 'source_hash', 'status', 'updated_at' ), $values ), $format ) ) { $wpdb_error = true; break; }
			$wpdb_rows++;
		}
		foreach ( $data['language_preferences'] as $preference ) {
			if ( ! is_array( $preference ) || empty( $preference['user_id'] ) || ! get_user_by( 'id', absint( $preference['user_id'] ) ) ) continue;
			$key = $preference['meta_key'] ?? 'lt_language';
			if ( 'lt_language' === $key ) update_user_meta( absint( $preference['user_id'] ), 'lt_language', sanitize_key( $preference['meta_value'] ?? '' ) );
			elseif ( 'lt_strings_per_page' === $key ) update_user_option( absint( $preference['user_id'] ), 'lt_strings_per_page', absint( $preference['meta_value'] ?? 0 ) );
		}
		LT_Translation_Store::reset_strings();
		if ( class_exists( 'LT_Content_Catalog' ) ) LT_Content_Catalog::flush_summary();
		if ( $wpdb_error ) wp_die( esc_html__( 'Some translations could not be restored. The available settings and menu selectors were restored; please retry from this backup.', 'lightweight-translation' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-preferences', 'lt_full_restored' => $wpdb_rows ), admin_url( 'admin.php' ) ) . '#tools' );
		exit;
	}

	private static function switcher_ids() {
		return array_map( 'absint', get_posts( array(
			'post_type' => 'nav_menu_item', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_lt_switcher', 'meta_value' => '1', 'suppress_filters' => true,
		) ) );
	}

	/** Remove performance transients created by earlier releases. Never creates new ones. */
	public static function purge_legacy_caches() {
		global $wpdb;
		$table = $wpdb->options;
		$names = array( 'lt_strings_%', 'lt_wcjs_%', 'lt_content_hashes_%', 'lt_admin_text_origins', 'lt_settings_texts_read', 'lt_list_%', 'lt_status_summary_%', 'lt_overview_progress_%', 'lt_scan_known_%' );
		foreach ( LT_Language_Router::instance()->languages() as $language ) foreach ( array( 'lt_strings_', 'lt_wcjs_', 'lt_content_hashes_', 'lt_scan_known_' ) as $prefix ) delete_transient( $prefix . $language );
		foreach ( array_merge( (array) get_option( 'lt_status_summary_keys', array() ), (array) get_option( 'lt_overview_progress_keys', array() ) ) as $key ) if ( is_string( $key ) ) delete_transient( $key );
		delete_transient( 'lt_admin_text_origins' );
		delete_transient( 'lt_settings_texts_read' );
		$patterns = array();
		foreach ( $names as $name ) foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $prefix ) $patterns[] = $prefix . $name;
		foreach ( $patterns as $pattern ) {
			$escaped = $wpdb->esc_like( str_replace( '%', '', $pattern ) );
			$like = false !== strpos( $pattern, '%' ) ? $escaped . '%' : $escaped;
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
		}
		foreach ( array( 'lt_catalog_revision', 'lt_catalog_revisions', 'lt_status_summary_keys', 'lt_overview_progress_keys' ) as $name ) delete_option( $name );
	}

	/** Clears only data owned by this plugin. Content, products, terms and menus remain. */
	public static function clear_data( $drop_table = false ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		if ( $drop_table ) $wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB
		else $wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		foreach ( self::OPTIONS as $name ) delete_option( $name );
		delete_metadata( 'user', 0, 'lt_language', '', true );
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'lt_strings_per_page', '', true );
		delete_metadata( 'user', 0, 'lt_collect_texts', '', true );
		LT_Translation_Reset::purge_all(); // translated pages must not stay in the page cache (also cancels a waiting purge)
		foreach ( self::switcher_ids() as $id ) wp_delete_post( $id, true );
		delete_metadata( 'post', 0, '_lt_switcher', '', true );
		delete_metadata( 'post', 0, '_lt_switcher_style', '', true );
		self::purge_legacy_caches();
		delete_transient( self::RESET_TOKEN . get_current_user_id() );
		if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) ) $GLOBALS['wp_rewrite']->rules = null;
		flush_rewrite_rules( false );
		if ( function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'lt_content' ); // Old versions used this group.
	}

	/** Full backup: download, restore, raw table. Settings › Maintenance, below the danger zone. */
	public static function backup_section() {
		echo '<h2>' . esc_html__( 'Full backup', 'lightweight-translation' ) . '</h2>';
		echo '<div class="card" style="max-width:none"><p>' . esc_html__( 'The full backup includes translations, plugin settings, scan data, language preferences and language selectors added to menus. It does not contain your pages, products or other site content.', 'lightweight-translation' ) . '</p>';
		echo '<p><a class="button lt-full-backup-link" href="' . esc_url( self::backup_url() ) . '">' . esc_html__( 'Download full backup (.json)', 'lightweight-translation' ) . '</a></p>';
		echo '<hr><h3>' . esc_html__( 'Restore a full backup', 'lightweight-translation' ) . '</h3><p>' . esc_html__( 'Choose a full backup file to restore translations and settings. Existing translations with the same text key will be replaced.', 'lightweight-translation' ) . '</p>';
		if ( isset( $_GET['lt_full_restored'] ) ) echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Backup restored. %d translation rows were imported.', 'lightweight-translation' ), absint( $_GET['lt_full_restored'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_full_restore' );
		echo '<input type="hidden" name="action" value="lt_full_restore"><input type="file" name="lt_full_backup" accept=".json,application/json" required><p><label><input type="checkbox" name="confirm_restore" value="yes" required> ' . esc_html__( 'I understand that matching translations and plugin settings may be replaced.', 'lightweight-translation' ) . '</label></p>';
		submit_button( __( 'Restore full backup', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</form><p style="margin-top:20px"><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lt_export&raw=1' ), 'lt_export' ) ) . '">' . esc_html__( 'Download the raw translation table (CSV)', 'lightweight-translation' ) . '</a></p></div>';
	}

	/** Reset of the plugin (backup first, then delete its data and deactivate it): Settings › Maintenance › Danger zone. */
	public static function reset_form() {
		echo '<div class="lt-reset-plugin"><h3>' . esc_html__( 'Reset the plugin and deactivate it', 'lightweight-translation' ) . '</h3><p class="description">' . esc_html__( 'Deletes every translation, setting and scan of the plugin, then deactivates it. Your pages, products and other content stay. Download the full backup first: the button below unlocks once it is downloaded.', 'lightweight-translation' ) . '</p>';
		echo '<p><a id="lt-full-backup-download" class="button" href="' . esc_url( self::backup_url() ) . '">' . esc_html__( 'Download full backup (.json)', 'lightweight-translation' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:16px 0" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'This removes the plugin data and deactivates Lightweight Translation. Continue?', 'lightweight-translation' ) ) ) . ')">';
		wp_nonce_field( 'lt_full_reset' );
		echo '<input type="hidden" name="action" value="lt_full_reset"><label><input id="lt-full-reset-confirm" type="checkbox" name="confirm_download" value="yes" required> ' . esc_html__( 'I downloaded the backup and want to remove the plugin data and deactivate the plugin.', 'lightweight-translation' ) . '</label><p><button id="lt-full-reset-button" class="button button-link-delete" disabled>' . esc_html__( 'Reset plugin and deactivate', 'lightweight-translation' ) . '</button></p></form>';
		echo '</div><script>document.addEventListener("DOMContentLoaded",function(){var d=document.querySelectorAll("#lt-full-backup-download,.lt-full-backup-link"),c=document.getElementById("lt-full-reset-confirm"),b=document.getElementById("lt-full-reset-button");if(d.length&&c&&b){d.forEach(function(l){l.addEventListener("click",function(){sessionStorage.setItem("ltFullBackupRequested","1");});});c.addEventListener("change",function(){b.disabled=!c.checked||sessionStorage.getItem("ltFullBackupRequested")!=="1";});}});</script>';
	}

	public function reset() {
		$this->authorized( 'lt_full_reset' );
		$token = get_transient( self::RESET_TOKEN . get_current_user_id() );
		if ( ! $token || empty( $_POST['confirm_download'] ) || 'yes' !== sanitize_key( wp_unslash( $_POST['confirm_download'] ) ) ) wp_die( esc_html__( 'Download the full backup and confirm before resetting the plugin.', 'lightweight-translation' ) );
		self::clear_data( true );
		deactivate_plugins( plugin_basename( LT_FILE ), true );
		wp_safe_redirect( admin_url( 'plugins.php?lt_plugin_reset=1' ) );
		exit;
	}
}

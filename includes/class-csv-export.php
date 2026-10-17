<?php
defined( 'ABSPATH' ) || exit;

/**
 * Organised CSV export: choose what to export (pages, products, categories, menus…), which languages,
 * and whether to export only what still needs translating. The file can be given to an AI or a translator
 * and re-imported as-is from the Import page.
 */
class LT_CSV_Export {
	const COLUMNS = array( 'group', 'item_title', 'object_type', 'object_id', 'field_key', 'language_code', 'status', 'source_text', 'translated_text' );

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'admin_post_lt_ai_context', array( $this, 'save_context' ) ); add_action( 'admin_post_lt_export', array( $this, 'download' ) ); add_action( 'admin_menu', array( $this, 'menu' ), 20 ); }

	public function menu() { add_submenu_page( 'lt-settings', __( 'Import / Export', 'lightweight-translation' ), __( 'Import / Export', 'lightweight-translation' ), lt_capability(), 'lt-export', array( $this, 'page' ) ); }

	private function secondary_languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	public function save_context() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_ai_context' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		update_option( 'lt_ai_context', sanitize_textarea_field( wp_unslash( (string) ( $_POST['context'] ?? '' ) ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=lt-export#lt-ai' ) );
		exit;
	}

	/** Prompt to paste with the CSV when using an AI. */
	public static function ai_prompt() {
		$router = LT_Language_Router::instance();
		$site    = class_exists( 'WooCommerce' ) ? 'an online shop (WordPress / WooCommerce)' : 'a WordPress website';
		$context = trim( (string) get_option( 'lt_ai_context', '' ) );
		if ( '' === $context ) $context = trim( get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ), " –" );
		return "You are a professional translator for {$site}.\n"
			. ( '' !== $context ? 'About the website: ' . $context . "\n" : '' )
			. 'The attached CSV contains texts written in "' . strtoupper( $router->default_language() ) . "\" (column source_text).\n"
			. "For EVERY row, write the translation into the language given by column language_code, in column translated_text.\n"
			. "Rules:\n"
			. "- Do not change any other column (except status, see below), do not add, remove or reorder rows or columns. Keep the header row.\n"
			. "- If translated_text already contains a translation (status \"outdated\"), update it so it matches the current source_text.\n"
			. "- Keep exactly: HTML tags and attributes, Gutenberg comments (<!-- wp:... -->, including their JSON), shortcodes [like_this], URLs, e-mail addresses, placeholders (%s, %1\$s, {name}), numbers, units, product references and brand names.\n"
			. "- field_key \"slug\": lowercase words separated by hyphens, no accents, no spaces (e.g. blue-cotton-shirt).\n"
			. "- Natural tone that suits the website and its visitors; same length and formatting as the source.\n"
			. "- If a translation marked \"outdated\" is still correct, keep it and write reviewed in column status.\n"
			. "- Return the complete file as a CSV: UTF-8, same delimiter as the file you received, every field quoted.";
	}

	public function page() {
		$groups    = LT_Content_Catalog::groups();
		$languages = $this->secondary_languages();
		echo '<div class="wrap lt-export"><h1>' . esc_html__( 'Import / Export', 'lightweight-translation' ) . '</h1>';
		echo '<p>' . esc_html__( 'Translate many texts at once: 1. download a CSV, 2. translate it (manually or with an AI, prompt below), 3. upload the file it returns.', 'lightweight-translation' ) . '</p>';
		echo '<div class="card" style="max-width:none;margin:16px 0"><h2>' . esc_html__( '1. Download the texts to translate', 'lightweight-translation' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_export' );
		echo '<input type="hidden" name="action" value="lt_export">';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Content', 'lightweight-translation' ) . '</th><td><p><label><input type="checkbox" id="lt-export-all"> <strong>' . esc_html__( 'Select all', 'lightweight-translation' ) . '</strong></label></p><div style="columns:3 220px">';
		foreach ( $groups as $key => $label ) echo '<label style="display:block;margin:0 0 6px"><input type="checkbox" class="lt-export-group" name="groups[]" value="' . esc_attr( $key ) . '"> ' . esc_html( $label ) . '</label>';
		echo '</div></td></tr>';
		echo '<tr><th>' . esc_html__( 'Languages', 'lightweight-translation' ) . '</th><td>';
		foreach ( $languages as $language ) echo '<label style="margin-right:14px"><input type="checkbox" name="langs[]" value="' . esc_attr( $language ) . '" checked> ' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</label>';
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Rows', 'lightweight-translation' ) . '</th><td><label style="display:block"><input type="radio" name="only" value="todo" checked> ' . esc_html__( 'Only what needs translating (missing + outdated)', 'lightweight-translation' ) . '</label><label style="display:block"><input type="radio" name="only" value="all"> ' . esc_html__( 'Everything (including texts already translated)', 'lightweight-translation' ) . '</label><label style="display:block;margin-top:8px"><input type="checkbox" name="slugs" value="1" checked> ' . esc_html__( 'Include URL slugs', 'lightweight-translation' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Separator', 'lightweight-translation' ) . '</th><td><select name="delimiter"><option value=";">' . esc_html__( 'Semicolon ; (Excel FR/PT)', 'lightweight-translation' ) . '</option><option value=",">' . esc_html__( 'Comma , (Google Sheets, Excel EN)', 'lightweight-translation' ) . '</option></select></td></tr></table>';
		submit_button( __( 'Download CSV', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</form></div>';
		LT_CSV_Import::instance()->form();
		echo '<h2 id="lt-ai" style="margin-top:32px">' . esc_html__( 'Prompt to use with an AI', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Give the CSV to the AI with this message, then import the file it returns.', 'lightweight-translation' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
		wp_nonce_field( 'lt_ai_context' );
		echo '<input type="hidden" name="action" value="lt_ai_context"><label><strong>' . esc_html__( 'About your site', 'lightweight-translation' ) . '</strong> <span class="description">' . esc_html__( '(who your visitors are, tone, words never to translate — added to the prompt)', 'lightweight-translation' ) . '</span><br><textarea name="context" rows="2" class="large-text" placeholder="' . esc_attr__( 'e.g. Handmade jewellery shop for young adults, friendly tone, keep the brand name “Lumi” untranslated.', 'lightweight-translation' ) . '">' . esc_textarea( (string) get_option( 'lt_ai_context', '' ) ) . '</textarea></label> ';
		submit_button( __( 'Save', 'lightweight-translation' ), 'secondary small', 'submit', false );
		echo '</form>';
		echo '<textarea readonly rows="12" class="large-text code" onclick="this.select()">' . esc_textarea( self::ai_prompt() ) . '</textarea>';
		echo '<h2 style="margin-top:32px">' . esc_html__( 'Full backup', 'lightweight-translation' ) . '</h2>';
		echo '<div class="card" style="max-width:none"><p>' . esc_html__( 'The full backup includes translations, plugin settings, scan data, language preferences and language selectors added to menus. It does not contain your pages, products or other site content.', 'lightweight-translation' ) . '</p>';
		echo '<p><a id="lt-full-backup-download" class="button" href="' . esc_url( LT_Data_Lifecycle::backup_url() ) . '">' . esc_html__( 'Download full backup (.json)', 'lightweight-translation' ) . '</a></p>';
		echo '<p class="description">' . wp_kses_post( sprintf( __( 'To delete all the plugin data and deactivate it, go to <a href="%s">Settings › Maintenance › Danger zone</a>.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-preferences#tools' ) ) ) ) . '</p>';
		echo '<hr><h3>' . esc_html__( 'Restore a full backup', 'lightweight-translation' ) . '</h3><p>' . esc_html__( 'Choose a full backup file to restore translations and settings. Existing translations with the same text key will be replaced.', 'lightweight-translation' ) . '</p>';
		if ( isset( $_GET['lt_full_restored'] ) ) echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Backup restored. %d translation rows were imported.', 'lightweight-translation' ), absint( $_GET['lt_full_restored'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_full_restore' );
		echo '<input type="hidden" name="action" value="lt_full_restore"><input type="file" name="lt_full_backup" accept=".json,application/json" required><p><label><input type="checkbox" name="confirm_restore" value="yes" required> ' . esc_html__( 'I understand that matching translations and plugin settings may be replaced.', 'lightweight-translation' ) . '</label></p>';
		submit_button( __( 'Restore full backup', 'lightweight-translation' ), 'secondary', 'submit', false );
		echo '</form><p style="margin-top:20px"><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lt_export&raw=1' ), 'lt_export' ) ) . '">' . esc_html__( 'Download the raw translation table (CSV)', 'lightweight-translation' ) . '</a></p></div>';
		echo '<script>document.getElementById("lt-export-all").addEventListener("change",function(e){document.querySelectorAll(".lt-export-group").forEach(function(c){c.checked=e.target.checked;});});</script>';
		echo '</div>';
	}

	private function open_csv( $name ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name . '-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM: accents displayed correctly in Excel.
		return $out;
	}

	private function put( $out, $row, $delimiter ) { fputcsv( $out, $row, $delimiter, '"', '' ); }

	public function download() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_export' ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$delimiter = ( isset( $_REQUEST['delimiter'] ) && ',' === $_REQUEST['delimiter'] ) ? ',' : ';';
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! empty( $_REQUEST['raw'] ) ) {
			$out = $this->open_csv( 'light-translations-backup' );
			$this->put( $out, array( 'language_code', 'object_type', 'object_id', 'field_key', 'source_text', 'translated_text', 'source_hash', 'status' ), $delimiter );
			foreach ( LT_Translation_Store::rows() as $row ) $this->put( $out, array( $row->language_code, $row->object_type, $row->object_id, $row->field_key, $row->source_text, $row->translated_text, $row->source_hash, $row->status ), $delimiter );
			fclose( $out );
			exit;
		}

		$all_groups = LT_Content_Catalog::groups();
		$groups     = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['groups'] ?? array() ) ), array_keys( $all_groups ) ) );
		if ( ! $groups ) wp_die( esc_html__( 'Choose at least one type of content to export.', 'lightweight-translation' ), '', array( 'back_link' => true ) );
		$languages = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_REQUEST['langs'] ?? array() ) ), $this->secondary_languages() ) );
		if ( ! $languages ) $languages = $this->secondary_languages();
		$only_todo = 'all' !== ( $_REQUEST['only'] ?? 'todo' );
		$slugs     = ! empty( $_REQUEST['slugs'] );
		// Only some items (ticked in "Pages & products"): "group|id".
		$only_ids  = ! empty( $_REQUEST['ids'] ) ? array_fill_keys( array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['ids'] ) ), true ) : null;
		// Interface texts: optional filters by plugin (text domain), "manual" texts, or messages only.
		$string_source = sanitize_text_field( wp_unslash( $_REQUEST['string_source'] ?? '' ) );
		$string_kind   = sanitize_key( $_REQUEST['string_kind'] ?? '' );
		$string_group  = sanitize_text_field( wp_unslash( $_REQUEST['string_group'] ?? '' ) ); // WooCommerce, theme, one plugin… (LT_Sources)
		$string_index  = ( $string_source || $string_kind || $string_group ) ? (array) get_option( 'lt_string_index', array() ) : array();

		$name = 1 === count( $groups ) ? sanitize_title( str_replace( ':', '-', $groups[0] ) ) : 'content';
		$out  = $this->open_csv( 'translations-' . $name . '-' . implode( '-', $languages ) );
		$this->put( $out, self::COLUMNS, $delimiter );
		foreach ( $groups as $group ) {
			foreach ( ( null !== $only_ids ? array_map( 'absint', array_map( function( $ref ) { return substr( $ref, strpos( $ref, '|' ) + 1 ); }, array_keys( array_filter( $only_ids, function( $v, $ref ) use ( $group ) { return 0 === strpos( $ref, $group . '|' ); }, ARRAY_FILTER_USE_BOTH ) ) ) ) : LT_Content_Catalog::item_ids( $group ) ) as $id ) {
				$item = LT_Content_Catalog::item( $group, $id );
				if ( ! $item ) continue;
				foreach ( $item['fields'] as $field => $source ) {
					if ( 'slug' === $field && ! $slugs ) continue;
					if ( 'string' === $group && ( $string_source || $string_kind || $string_group ) ) {
						$entry = $string_index[ $field ] ?? null;
						if ( 'manual' === $string_source ) { if ( 0 !== strpos( $field, 'text_' ) ) continue; }
						elseif ( 'unknown' === $string_source ) { if ( $entry || 0 === strpos( $field, 'text_' ) ) continue; }
						elseif ( $string_source && ( $entry['d'] ?? '' ) !== $string_source ) continue;
						if ( 'messages' === $string_kind && 'm' !== ( $entry['t'] ?? '' ) ) continue;
						if ( $string_group && LT_Sources::group_of( $field, $entry ) !== $string_group ) continue;
						if ( ! apply_filters( 'lt_export_string_include', true, $field, $entry, $_REQUEST ) ) continue;
					}
					foreach ( $languages as $language ) {
						list( $status, $translated ) = LT_Content_Catalog::field_status( $item['type'], $item['id'], $field, $source, $language );
						if ( $only_todo && 'translated' === $status ) continue;
						$this->put( $out, array( $all_groups[ $group ], $item['title'], $item['type'], $item['id'], $field, $language, $status, $source, $translated ), $delimiter );
					}
				}
			}
		}
		fclose( $out );
		exit;
	}
}

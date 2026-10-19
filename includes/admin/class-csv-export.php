<?php
defined( 'ABSPATH' ) || exit;

/**
 * Organised CSV export: choose what to export (pages, products, categories, menus…), which languages,
 * and whether to export only what still needs translating. The file can be given to an AI or a translator
 * and re-imported as-is from the Import page.
 */
class LT_CSV_Export {
	const MAX_CHARS = 40000; // per file of a split export (about 10,000 tokens of original text)
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
			. "If the file has a column \"key\": for EVERY row, write the translation in each language column (named with its language code, e.g. fr, en). Otherwise: write the translation into the language given by column language_code, in column translated_text.\n"
			. "Rules:\n"
			. "- Do not change any other column (key, source_text, and status except as below), do not add, remove or reorder rows or columns. Keep the header row.\n"
			. "- If a translation cell is already filled, update it so it matches the current source_text.\n"
			. "- Keep exactly: HTML tags and attributes, Gutenberg comments (<!-- wp:... -->, including their JSON), shortcodes [like_this], URLs, e-mail addresses, placeholders (%s, %1\$s, {name}), numbers, units, product references and brand names.\n"
			. "- Web address (field_key \"slug\", or a key ending with |slug): lowercase words separated by hyphens, no accents, no spaces (e.g. blue-cotton-shirt).\n"
			. "- Natural tone that suits the website and its visitors; same length and formatting as the source.\n"
			. "- (Files with a status column) If a translation marked \"outdated\" is still correct, keep it and write reviewed in column status.\n"
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
		echo '<tr><th>' . esc_html__( 'Format', 'lightweight-translation' ) . '</th><td><label style="display:block"><input type="radio" name="format" value="compact" checked> <strong>' . esc_html__( 'Compact, for an AI (fewer tokens)', 'lightweight-translation' ) . '</strong> — ' . esc_html__( 'one row per text: its reference, the original and one column per language.', 'lightweight-translation' ) . '</label><label style="display:block"><input type="radio" name="format" value="full"> ' . esc_html__( 'Complete, for Excel (one row per language, with titles and status)', 'lightweight-translation' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Split', 'lightweight-translation' ) . '</th><td><select name="split"><option value="0">' . esc_html__( 'One file', 'lightweight-translation' ) . '</option>';
		foreach ( array( 100, 250, 500, 1000 ) as $size ) echo '<option value="' . (int) $size . '"' . selected( 250, $size, false ) . '>' . esc_html( sprintf( __( 'Files of %d texts (zip)', 'lightweight-translation' ), $size ) ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'Give the AI one file at a time. A file also stops at about 40,000 characters, so long product descriptions never make it too big. Import all the returned files at once.', 'lightweight-translation' ) . '</p></td></tr>';
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
		echo '<p class="description" style="margin-top:32px">' . wp_kses_post( sprintf( __( 'Full backup, restore and plugin reset are in <a href="%s">Settings › Maintenance</a>.', 'lightweight-translation' ), esc_url( admin_url( 'admin.php?page=lt-preferences#tools' ) ) ) ) . '</p>';
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
		$string_index  = (array) get_option( 'lt_string_index', array() );

		$name = 1 === count( $groups ) ? sanitize_title( str_replace( ':', '-', $groups[0] ) ) : 'content';
		$compact = 'full' !== sanitize_key( $_REQUEST['format'] ?? 'full' );
		$split   = in_array( absint( $_REQUEST['split'] ?? 0 ), array( 100, 250, 500, 1000 ), true ) ? absint( $_REQUEST['split'] ) : 0;
		$header  = $compact ? array_merge( array( 'key', 'source_text' ), $languages ) : self::COLUMNS;
		$rows    = array();
		$seen    = array();
		foreach ( $groups as $group ) {
			foreach ( ( null !== $only_ids ? array_map( 'absint', array_map( function( $ref ) { return substr( $ref, strpos( $ref, '|' ) + 1 ); }, array_keys( array_filter( $only_ids, function( $v, $ref ) use ( $group ) { return 0 === strpos( $ref, $group . '|' ); }, ARRAY_FILTER_USE_BOTH ) ) ) ) : LT_Content_Catalog::item_ids( $group ) ) as $id ) {
				foreach ( self::entries( $group, $id ) as $entry ) {
					$field = $entry['field']; $source = $entry['source'];
					$item = array( 'type' => $entry['type'], 'id' => $entry['id'], 'title' => $entry['title'] );
					$ref = $item['type'] . '|' . $item['id'] . '|' . $field;
					if ( 'string' === $item['type'] && ( ! empty( $string_index[ $field ]['x'] ) || LT_Code_Scanner::admin_only( (array) ( $string_index[ $field ] ?? array() ) ) ) ) continue;
					if ( isset( $seen[ $ref ] ) ) continue;
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
					$seen[ $ref ] = true;
					if ( $compact ) { // one row per text, one column per language (the original is sent once)
						$cells = array(); $todo = false;
						foreach ( $languages as $language ) {
							list( $status, $translated ) = LT_Content_Catalog::field_status( $item['type'], $item['id'], $field, $source, $language );
							if ( 'translated' !== $status ) $todo = true;
							$cells[] = $translated;
						}
						if ( $only_todo && ! $todo ) continue;
						$rows[] = array_merge( array( $item['type'] . '|' . $item['id'] . '|' . $field, $source ), $cells );
						continue;
					}
					foreach ( $languages as $language ) {
						list( $status, $translated ) = LT_Content_Catalog::field_status( $item['type'], $item['id'], $field, $source, $language );
						if ( $only_todo && 'translated' === $status ) continue;
						$rows[] = array( $all_groups[ $group ], $item['title'], $item['type'], $item['id'], $field, $language, $status, $source, $translated );
					}
				}
			}
		}
		if ( ! $rows ) wp_die( esc_html__( 'No texts need translating in this selection. Choose Everything to export translations already completed.', 'lightweight-translation' ), '', array( 'back_link' => true ) );
		$this->send( 'translations-' . $name . '-' . implode( '-', $languages ), $header, $rows, $split, $delimiter );
		exit;
	}

	/** Exactly what the panel and the page counters include, including shared blocks and visible strings. */
	public static function entries( $group, $id ) {
		$item = LT_Content_Catalog::item( $group, $id );
		if ( ! $item ) return array();
		$out = array();
		if ( 'post:page' === $group ) {
			$model = LT_Status_Panel::model( $group, $id );
			if ( $model ) foreach ( $model['sections'] as $section ) foreach ( $section['entries'] as $entry ) $out[] = $entry + array( 'title' => $item['title'] . ( empty( $section['own'] ) ? ' → ' . $section['title'] : '' ) );
		} else {
			foreach ( $item['fields'] as $field => $source ) $out[] = array( 'type' => $item['type'], 'id' => $item['id'], 'field' => $field, 'source' => $source, 'title' => $item['title'] );
		}
		return $out;
	}

	/** CSV text of some rows (UTF-8 BOM, so Excel shows accents). */
	private function csv( $header, $rows, $delimiter ) {
		$out = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$this->put( $out, $header, $delimiter );
		foreach ( $rows as $row ) $this->put( $out, $row, $delimiter );
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/** Groups of rows: at most $size rows and about MAX_CHARS characters each (0 = a single group). */
	private static function chunks( $rows, $size ) {
		if ( ! $size ) return array( $rows );
		$chunks = array(); $current = array(); $chars = 0;
		foreach ( $rows as $row ) {
			$length = strlen( implode( '', $row ) );
			if ( $current && ( count( $current ) >= $size || $chars + $length > self::MAX_CHARS ) ) { $chunks[] = $current; $current = array(); $chars = 0; }
			$current[] = $row;
			$chars += $length;
		}
		if ( $current || ! $chunks ) $chunks[] = $current;
		return $chunks;
	}

	/** One CSV, or a zip of numbered CSV files when the export is split. */
	private function send( $name, $header, $rows, $split, $delimiter ) {
		$chunks = self::chunks( $rows, $split );
		$stamp  = gmdate( 'Y-m-d-His' );
		if ( 1 === count( $chunks ) || ! class_exists( 'ZipArchive' ) ) {
			// No zip support on this server: a single file (the description of the page says so).
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $name . '-' . $stamp . '.csv' );
			echo $this->csv( $header, $rows, $delimiter ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		if ( ! function_exists( 'wp_tempnam' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = wp_tempnam( $name . '.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) wp_die( esc_html__( 'Could not create the zip file.', 'lightweight-translation' ) );
		$total = count( $chunks );
		foreach ( $chunks as $i => $chunk ) $zip->addFromString( sprintf( '%s-part-%0' . strlen( (string) $total ) . 'd-of-%d.csv', $name, $i + 1, $total ), $this->csv( $header, $chunk, $delimiter ) );
		$zip->close();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $name . '-' . $stamp . '.zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}

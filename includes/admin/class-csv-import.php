<?php
defined( 'ABSPATH' ) || exit;

/**
 * Imports a CSV produced by the Export page (organised or raw backup), after manual or AI translation.
 * The original text is re-read from WordPress whenever possible, so an AI that altered source_text
 * cannot break the link between the original and its translation.
 */
class LT_CSV_Import {
	const REQUIRED = array( 'language_code', 'object_type', 'object_id', 'field_key', 'translated_text' );

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'admin_init', array( $this, 'old_page' ) ); add_action( 'admin_post_lt_import', array( $this, 'import' ) ); }

	/** Import now lives on the "Import / Export" page: old links (page=lt-import) are redirected there. */
	public function old_page() {
		if ( 'lt-import' === ( $_GET['page'] ?? '' ) ) { wp_safe_redirect( admin_url( 'admin.php?page=lt-export#lt-import' ) ); exit; } // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Upload form, displayed at the top of the Import / Export page. */
	public function form() {
		echo '<div id="lt-import" class="card" style="max-width:none;margin:16px 0"><h2>' . esc_html__( '2. Import the translated file', 'lightweight-translation' ) . '</h2>';
		if ( ! empty( $_GET['lt_result'] ) ) echo '<div class="notice notice-success inline"><p>' . esc_html( wp_unslash( $_GET['lt_result'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<p>' . esc_html__( 'Upload the CSV files returned by the AI or the translator (comma or semicolon), several at once if the export was split. Only the translations are used; the original text is read again from the site.', 'lightweight-translation' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_import', 'lt_import_nonce' );
		echo '<input type="hidden" name="action" value="lt_import"><p><input type="file" name="lt_csv[]" accept=".csv,text/csv" multiple required></p><p><label><input type="checkbox" name="skip_empty" checked> ' . esc_html__( 'Ignore empty translations', 'lightweight-translation' ) . '</label></p>';
		submit_button( __( 'Import CSV', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/** Uploaded files: one (lt_csv) or several (lt_csv[]). */
	private static function uploads() {
		$files = $_FILES['lt_csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $files || empty( $files['tmp_name'] ) ) return array();
		$tmp = (array) $files['tmp_name'];
		$err = (array) ( $files['error'] ?? array() );
		$out = array();
		foreach ( $tmp as $i => $path ) if ( $path && UPLOAD_ERR_OK === (int) ( $err[ $i ] ?? UPLOAD_ERR_OK ) && is_uploaded_file( $path ) ) $out[] = $path;
		return $out;
	}

	public function import() {
		if ( ! current_user_can( lt_capability() ) || ! check_admin_referer( 'lt_import', 'lt_import_nonce' ) ) wp_die( esc_html__( 'Invalid import.', 'lightweight-translation' ) );
		$files = self::uploads();
		if ( ! $files ) wp_die( esc_html__( 'Invalid upload.', 'lightweight-translation' ) );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$router    = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		$counts    = array( 'imported' => 0, 'unchanged' => 0, 'ignored' => 0, 'errors' => 0 );
		foreach ( $files as $tmp ) $this->import_file( $tmp, $languages, $counts );
		$labels = array( 'imported' => __( 'imported', 'lightweight-translation' ), 'unchanged' => __( 'unchanged', 'lightweight-translation' ), 'ignored' => __( 'empty ignored', 'lightweight-translation' ), 'errors' => __( 'errors', 'lightweight-translation' ) );
		$result = implode( ' · ', array_map( function( $key ) use ( $counts, $labels ) { return $counts[ $key ] . ' ' . $labels[ $key ]; }, array_keys( $counts ) ) );
		if ( count( $files ) > 1 ) $result = sprintf( _n( '%d file', '%d files', count( $files ), 'lightweight-translation' ), count( $files ) ) . ' · ' . $result;
		// Imported from "Pages & products": back to that list.
		$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		if ( $back ) { wp_safe_redirect( add_query_arg( 'lt_notice', rawurlencode( __( 'Import:', 'lightweight-translation' ) . ' ' . $result ), remove_query_arg( 'lt_notice', $back ) ) ); exit; }
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-export', 'lt_result' => rawurlencode( $result ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * One CSV. Two layouts are read:
	 *  - complete: one row per language (language_code, object_type, object_id, field_key, translated_text…);
	 *  - compact (for AI): key "type|id|field", source_text, then one column per language code.
	 */
	private function import_file( $tmp, $languages, &$counts ) {
		$handle = fopen( $tmp, 'r' );
		if ( ! $handle ) { $counts['errors']++; return; }
		$first = (string) fgets( $handle );
		$delimiter = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
		rewind( $handle );
		$header = fgetcsv( $handle, 0, $delimiter, '"', '' );
		$header = is_array( $header ) ? array_map( function( $name ) { return sanitize_key( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $name ) ); }, $header ) : array();
		$compact = in_array( 'key', $header, true );
		if ( ! $compact && array_diff( self::REQUIRED, $header ) ) { fclose( $handle ); wp_die( esc_html__( 'Invalid CSV header: columns language_code, object_type, object_id, field_key and translated_text are required.', 'lightweight-translation' ), '', array( 'back_link' => true ) ); }
		$columns = $compact ? array_values( array_intersect( $header, $languages ) ) : array();
		while ( ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) {
			if ( array( null ) === $row ) continue; // blank line
			if ( count( $row ) < count( $header ) ) $row = array_pad( $row, count( $header ), '' );
			$data = array_combine( $header, array_slice( $row, 0, count( $header ) ) );
			if ( $compact ) {
				$parts = explode( '|', (string) $data['key'] );
				if ( 3 !== count( $parts ) ) { $counts['errors']++; continue; }
				foreach ( $columns as $language ) $this->import_entry( $language, $parts[0], $parts[1], $parts[2], (string) $data[ $language ], $data, $languages, $counts );
			} else {
				$this->import_entry( $data['language_code'] ?? '', $data['object_type'] ?? '', $data['object_id'] ?? 0, $data['field_key'] ?? '', (string) ( $data['translated_text'] ?? '' ), $data, $languages, $counts );
			}
		}
		fclose( $handle );
	}

	private function import_entry( $language, $type, $id, $field, $translated, $data, $languages, &$counts ) {
		$language = sanitize_key( $language );
		$type     = sanitize_key( $type );
		$id       = absint( $id );
		$field    = sanitize_key( $field );
		$global   = in_array( $type, array( 'string', 'email' ), true );
		if ( ! in_array( $language, $languages, true ) || ! $type || ! $field || ( ! $global && ! $id ) ) { $counts['errors']++; return; }
		if ( ! empty( $_POST['skip_empty'] ) && '' === trim( $translated ) ) { $counts['ignored']++; return; } // phpcs:ignore WordPress.Security.NonceVerification -- checked in import()

		$source = LT_Content_Catalog::current_source( $type, $id, $field );
		if ( 'email' === $type && ( 0 !== $id || ( function_exists( 'WC' ) && null === $source ) ) ) { $counts['errors']++; return; }
		if ( null === $source ) {
			if ( ! $global ) { $counts['errors']++; return; } // The object no longer exists.
			$existing_source = LT_Translation_Store::get( $language, $type, 0, $field );
			$source = $existing_source ? (string) $existing_source->source_text : (string) ( $data['source_text'] ?? '' );
		}
		if ( 'slug' === $field ) $translated = sanitize_title( $translated );

		$existing = LT_Translation_Store::get( $language, $type, $id, $field );
		if ( $existing && (string) $existing->translated_text === $translated && 'reviewed' !== ( $data['status'] ?? '' ) ) {
			// Same text as stored: nothing to do. An "outdated" row returned untouched stays outdated,
			// unless its status column was set to "reviewed" (= translation checked, still valid).
			$counts['unchanged']++;
			return;
		}
		$result = LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => $type, 'object_id' => $id, 'field_key' => $field, 'source_text' => $source, 'translated_text' => $translated, 'status' => '' === trim( $translated ) ? 'empty' : ( 'reviewed' === ( $data['status'] ?? '' ) ? 'reviewed' : 'translated' ) ) );
		$counts[ false === $result ? 'errors' : 'imported' ]++;
	}
}

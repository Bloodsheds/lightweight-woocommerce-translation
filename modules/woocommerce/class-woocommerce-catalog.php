<?php
defined( 'ABSPATH' ) || exit;

/** Installs the bundled public catalog once per language/configuration, never on customer requests. */
class LT_WooCommerce_Catalog {
	const REVISION = '11.1.0-6';
	public static function prepare() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( wp_doing_ajax() || 0 !== strpos( $page, 'lt-' ) || ! current_user_can( lt_capability() ) ) return;
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! $languages ) return;
		$order_texts = LT_Order_Translations::entries() + LT_Account_Translations::entries();
		$signature = md5( wp_json_encode( $order_texts ) ) . ':' . self::REVISION . ':' . get_locale() . ':' . $router->default_language() . ':' . implode( ',', $languages );
		if ( get_option( 'lt_woocommerce_catalog' ) === $signature ) return;
		$data = json_decode( (string) file_get_contents( __DIR__ . '/catalog.json' ), true );
		if ( empty( $data['entries'] ) ) return;
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$js = (array) get_option( 'lt_js_strings', array() );
		$texts = array(); $areas = array(); $legacy_pairs = array();
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		foreach ( $data['entries'] as $entry ) {
			$context = $entry['context'];
			$prefix = '' !== $context ? $context . "\x04" : '';
			$forms = array( $entry['id'] => 1 );
			if ( '' !== $entry['plural'] ) $forms[ $entry['plural'] ] = 2;
			foreach ( $forms as $msgid => $number ) {
				$field = LT_String_Keys::field( 'woocommerce', $prefix . $msgid );
				$legacy = LT_String_Keys::legacy( $prefix . $msgid );
				$legacy_pairs[ $field ] = $legacy;
				if ( isset( $index[ $legacy ] ) ) $index[ $legacy ]['x'] = 1; // The old row remains as a runtime fallback.
				if ( 2 === $number ) $source = '' !== $context ? _nx( $entry['id'], $entry['plural'], 2, $context, 'woocommerce' ) : _n( $entry['id'], $entry['plural'], 2, 'woocommerce' );
				else $source = '' !== $context ? translate_with_gettext_context( $msgid, $context, 'woocommerce' ) : translate( $msgid, 'woocommerce' );
				$texts[ $field ] = $source;
				$areas[ $field ] = array_values( array_unique( array_merge( $areas[ $field ] ?? array(), $entry['areas'] ) ) );
				// Preserve ignore flags and any other metadata already associated with this key.
				$index[ $field ] = array( 'd' => 'woocommerce', 't' => 'l', 's' => 'woocommerce-catalog', 'a' => 'f', 'p' => $entry['files'][0], 'catalog' => 1 ) + ( $index[ $field ] ?? array() );
				if ( $entry['js'] ) $index[ $field ]['j'] = 1;
				if ( preg_match( '#^templates/emails/#', $entry['files'][0] ) ) $index[ $field ]['e'] = 1;
			}
			if ( $entry['js'] ) $js['woocommerce'][ $prefix . $entry['id'] ] = $entry['plural'];
		}
		if ( $switched ) restore_previous_locale();
		foreach ( $order_texts as $field => $entry ) {
			$texts[ $field ] = $entry['source'];
			if ( ! empty( $entry['legacy'] ) ) { $legacy_pairs[ $field ] = $entry['legacy']; if ( isset( $index[ $entry['legacy'] ] ) ) $index[ $entry['legacy'] ]['x'] = 1; }
			$index[ $field ] = array( 'd' => 'woocommerce', 't' => 'l', 's' => 'woocommerce-catalog', 'a' => 'f', 'catalog' => 1, 'v' => 1, 'p' => $entry['p'], 'wc_areas' => array_values( array_unique( array_merge( $areas[ $field ] ?? array(), $entry['wc_areas'] ) ) ) ) + ( $index[ $field ] ?? array() );
		}
		if ( ! LT_Translation_Store::collect_many( $languages, $texts ) ) return;
		// Copy published legacy values into their WooCommerce-specific rows in small admin-only batches.
		// Customer requests still perform only an in-memory key lookup.
		global $wpdb;
		$table = LT_Translation_Store::table();
		foreach ( $languages as $language ) foreach ( array_chunk( $legacy_pairs, 100, true ) as $chunk ) {
			$cases = array(); $args = array();
			foreach ( $chunk as $field => $legacy ) { $cases[] = 'WHEN %s THEN %s'; $args[] = $field; $args[] = $legacy; }
			$args[] = $language;
			$args = array_merge( $args, array_keys( $chunk ) );
			$sql = "UPDATE $table dst INNER JOIN $table src ON src.language_code=dst.language_code AND src.object_type='string' AND src.object_id=0 AND src.field_key=(CASE dst.field_key " . implode( ' ', $cases ) . " ELSE '' END) SET dst.translated_text=src.translated_text,dst.status=src.status WHERE dst.language_code=%s AND dst.object_type='string' AND dst.object_id=0 AND dst.field_key IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ") AND dst.status='empty' AND dst.source_hash=src.source_hash AND src.status IN ('translated','reviewed') AND src.translated_text <> ''";
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) return; // phpcs:ignore WordPress.DB
		}
		LT_Translation_Store::reset_strings();
		update_option( LT_Code_Scanner::INDEX, $index, false );
		update_option( 'lt_js_strings', $js, false );
		update_option( 'lt_woocommerce_catalog', $signature, false );
		LT_Script_Texts::flush();
		LT_Content_Catalog::strings_changed();
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/** Bundled WoodMart public texts; installation work is restricted to Translations admin pages. */
class LT_WoodMart_Catalog {
	const REVISION = '8.4.0-1';

	public static function prepare() {
		if ( ! LT_Compat_Texts::admin_screen() ) return;
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! $languages ) return;
		$signature = self::REVISION . ':' . get_locale() . ':' . $router->default_language() . ':' . implode( ',', $languages );
		if ( get_option( 'lt_woodmart_catalog' ) === $signature ) return;
		$data = json_decode( (string) file_get_contents( __DIR__ . '/catalog.json' ), true );
		if ( empty( $data['entries'] ) ) return;

		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$js = (array) get_option( 'lt_js_strings', array() );
		$texts = array();
		$legacy = array();
		$compat = array();
		$compat_labels = array( 'Add to wishlist', 'Remove from wishlist', 'Browse wishlist', 'Wishlist', 'Select category', 'Search for products', 'Search for projects', 'Search for pages', 'Search for posts' );
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		foreach ( $data['entries'] as $entry ) {
			$context = (string) $entry['context'];
			$singular = (string) $entry['id'];
			$plural = (string) $entry['plural'];
			$prefix = '' === $context ? '' : $context . "\x04";
			$forms = array( $singular => false );
			if ( '' !== $plural ) $forms[ $plural ] = true;
			foreach ( $forms as $msgid => $is_plural ) {
				$key = $prefix . $msgid;
				$field = LT_String_Keys::field( 'woodmart', $key );
				$legacy[ $field ] = LT_String_Keys::legacy( $key );
				if ( ( $index[ $legacy[ $field ] ]['d'] ?? '' ) === 'woodmart' ) $index[ $legacy[ $field ] ]['x'] = 1;
				if ( $is_plural ) $source = '' === $context ? _n( $singular, $plural, 2, 'woodmart' ) : _nx( $singular, $plural, 2, $context, 'woodmart' );
				else $source = '' === $context ? translate( $msgid, 'woodmart' ) : translate_with_gettext_context( $msgid, $context, 'woodmart' );
				$texts[ $field ] = $source;
				$index[ $field ] = array( 'd' => 'woodmart', 't' => 'l', 's' => 'theme:woodmart', 'a' => 'f', 'p' => $entry['file'], 'catalog' => 1, 'v' => 1 ) + ( $index[ $field ] ?? array() );
				if ( ! empty( $entry['js'] ) ) $index[ $field ]['j'] = 1;
				if ( ! $is_plural && in_array( $msgid, $compat_labels, true ) ) {
					$old = LT_Compat_Texts::key( 'woodmart', $msgid );
					$compat[ $field ] = $old;
					if ( isset( $index[ $old ] ) ) $index[ $old ]['x'] = 1;
				}
			}
			if ( ! empty( $entry['js'] ) ) $js['woodmart'][ $prefix . $singular ] = $plural;
		}
		if ( $switched ) restore_previous_locale();
		if ( ! LT_Translation_Store::collect_many( $languages, $texts ) ) return;

		// A previously scanned or header-registered translation remains usable in its new catalog row.
		foreach ( $languages as $language ) {
			if ( ! self::copy_existing( $language, $legacy ) || ! self::copy_existing( $language, $compat ) ) return;
		}
		LT_Translation_Store::reset_strings();
		update_option( LT_Code_Scanner::INDEX, $index, false );
		update_option( 'lt_js_strings', $js, false );
		update_option( 'lt_woodmart_catalog', $signature, false );
		LT_Script_Texts::flush();
		LT_Content_Catalog::strings_changed();
	}

	private static function copy_existing( $language, $pairs ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		foreach ( array_chunk( $pairs, 100, true ) as $chunk ) {
			$cases = array(); $args = array();
			foreach ( $chunk as $field => $old ) { $cases[] = 'WHEN %s THEN %s'; $args[] = $field; $args[] = $old; }
			$args[] = $language;
			$args = array_merge( $args, array_keys( $chunk ) );
			$sql = "UPDATE $table dst INNER JOIN $table src ON src.language_code=dst.language_code AND src.object_type='string' AND src.object_id=0 AND src.field_key=(CASE dst.field_key " . implode( ' ', $cases ) . " ELSE '' END) SET dst.translated_text=src.translated_text,dst.status=src.status WHERE dst.language_code=%s AND dst.object_type='string' AND dst.object_id=0 AND dst.field_key IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ") AND dst.status='empty' AND dst.source_hash=src.source_hash AND src.status IN ('translated','reviewed') AND src.translated_text <> ''";
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) return false; // phpcs:ignore WordPress.DB
		}
		return true;
	}
}

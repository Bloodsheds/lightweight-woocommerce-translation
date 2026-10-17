<?php
defined( 'ABSPATH' ) || exit;

/** Installs the bundled public catalog once per language/configuration, never on customer requests. */
class LT_WooCommerce_Catalog {
	const REVISION = '11.1.0-2';
	public static function prepare() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( wp_doing_ajax() || 0 !== strpos( $page, 'lt-' ) || ! current_user_can( lt_capability() ) ) return;
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! $languages ) return;
		$signature = self::REVISION . ':' . get_locale() . ':' . $router->default_language() . ':' . implode( ',', $languages );
		if ( get_option( 'lt_woocommerce_catalog' ) === $signature ) return;
		$data = json_decode( (string) file_get_contents( __DIR__ . '/catalog.json' ), true );
		if ( empty( $data['entries'] ) ) return;
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$js = (array) get_option( 'lt_js_strings', array() );
		$texts = array();
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		foreach ( $data['entries'] as $entry ) {
			$context = $entry['context'];
			$prefix = '' !== $context ? $context . "\x04" : '';
			$forms = array( $entry['id'] => 1 );
			if ( '' !== $entry['plural'] ) $forms[ $entry['plural'] ] = 2;
			foreach ( $forms as $msgid => $number ) {
				$field = 'string_' . md5( $prefix . $msgid );
				if ( 2 === $number ) $source = '' !== $context ? _nx( $entry['id'], $entry['plural'], 2, $context, 'woocommerce' ) : _n( $entry['id'], $entry['plural'], 2, 'woocommerce' );
				else $source = '' !== $context ? translate_with_gettext_context( $msgid, $context, 'woocommerce' ) : translate( $msgid, 'woocommerce' );
				$texts[ $field ] = $source;
				// Preserve ignore flags and any other metadata already associated with this key.
				$index[ $field ] = array( 'd' => 'woocommerce', 't' => 'l', 's' => 'woocommerce-catalog', 'a' => 'f', 'p' => $entry['files'][0], 'catalog' => 1 ) + ( $index[ $field ] ?? array() );
				if ( $entry['js'] ) $index[ $field ]['j'] = 1;
				if ( preg_match( '#^templates/emails/#', $entry['files'][0] ) ) $index[ $field ]['e'] = 1;
			}
			if ( $entry['js'] ) $js['woocommerce'][ $prefix . $entry['id'] ] = $entry['plural'];
		}
		if ( $switched ) restore_previous_locale();
		if ( ! LT_Translation_Store::collect_many( $languages, $texts ) ) return;
		update_option( LT_Code_Scanner::INDEX, $index, false );
		update_option( 'lt_js_strings', $js, false );
		update_option( 'lt_woocommerce_catalog', $signature, false );
		LT_Script_Texts::flush();
		LT_Content_Catalog::strings_changed();
	}
}

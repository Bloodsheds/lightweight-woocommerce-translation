<?php
defined( 'ABSPATH' ) || exit;

/** Small shared inventory for integrations. Stored in the existing strings/CSV workflow. */
class LT_Compat_Texts {
	public static function normal( $text ) { return trim( preg_replace( '/\s+/u', ' ', (string) $text ) ); }
	public static function key( $domain, $text ) { return 'string_' . md5( $domain . ':' . self::normal( $text ) ); }
	public static function admin_screen() { return ! wp_doing_ajax() && 0 === strpos( (string) ( $_GET['page'] ?? '' ), 'lt-' ) && current_user_can( lt_capability() ); }
	public static function language() {
		if ( class_exists( 'WooCommerce', false ) ) {
			if ( LT_Email_Translations::reading_original() ) return '';
			$language = LT_Email_Translations::instance()->render_language();
			if ( $language ) return $language;
		}
		return ! is_admin() || wp_doing_ajax() ? LT_Language_Router::instance()->current_language() : '';
	}
	public static function lookup( $domain, $source, $language = null ) {
		$language = null === $language ? self::language() : $language;
		if ( ! $language || $language === LT_Language_Router::instance()->default_language() || ! is_string( $source ) ) return $source;
		$plain = self::normal( $source );
		$value = LT_Translation_Store::string_lookup( $language, self::key( $domain, $plain ), $plain );
		if ( null === $value ) $value = LT_Translation_Store::by_text( $language, $plain );
		if ( null === $value || ! self::valid( $plain, $value ) ) return $source;
		return preg_replace_callback( '/\S(?:.*\S)?/us', function() use ( $value ) { return $value; }, $source, 1 );
	}
	public static function valid( $source, $translation ) {
		$tokens = function( $text ) { preg_match_all( '/\[[a-zA-Z_][a-zA-Z0-9_:\-]*\]|\{[a-zA-Z_][a-zA-Z0-9_]*\}|%(?:\d+\$)?[sdfu]/', $text, $m ); sort( $m[0] ); return $m[0]; };
		return $tokens( $source ) === $tokens( $translation );
	}
	public static function register( $domain, $texts, $place ) {
		$rows = array();
		foreach ( $texts as $text ) {
			$text = self::normal( $text );
			if ( preg_match( '/\p{L}{2}/u', $text ) && mb_strlen( $text ) <= 2000 ) $rows[ self::key( $domain, $text ) ] = array( $text, $domain );
		}
		LT_Page_Texts::register( $rows );
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() ); $before = $index;
		foreach ( $rows as $field => $row ) $index[ $field ] = array( 'd' => $domain, 't' => 'l', 'a' => 'f', 's' => 'plugin:' . $domain, 'p' => $place, 'v' => 1 ) + ( $index[ $field ] ?? array() );
		if ( $before !== $index ) { update_option( LT_Code_Scanner::INDEX, $index, false ); LT_Content_Catalog::strings_changed(); }
	}
}

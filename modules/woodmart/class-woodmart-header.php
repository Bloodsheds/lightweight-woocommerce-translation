<?php
defined( 'ABSPATH' ) || exit;

/** WoodMart 8.x: Header Builder option values bypass gettext; search uses a contextual msgid. */
class LT_WoodMart_Header {
	private static $originals = array();
	private static $public = array( 'Add to wishlist', 'Remove from wishlist', 'Browse wishlist', 'Wishlist', 'Select category' );
	public static function boot() {
		add_action( 'after_setup_theme', array( __CLASS__, 'register_headers' ), 30 );
		add_filter( 'woodmart_get_current_header_id', array( __CLASS__, 'register_header' ), 30 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'placeholder' ), 30, 4 );
		add_filter( 'gettext', array( __CLASS__, 'public_text' ), 35, 3 );
		add_filter( 'woodmart_menu_storage_key', array( __CLASS__, 'menu_storage_key' ) );
		add_filter( 'lt_admin_text_options', array( __CLASS__, 'options' ) );
	}
	public static function public_text( $translation, $text, $domain ) {
		if ( 'woodmart' !== $domain || ! in_array( $text, self::$public, true ) || ! self::frontend() || ! LT_Language_Router::instance()->is_secondary() ) return $translation;
		return self::label( $text, array( $text ), $translation );
	}
	private static function frontend() {
		if ( function_exists( 'woodmart_is_header_frontend_editor' ) && woodmart_is_header_frontend_editor() ) return false;
		if ( ! is_admin() ) return true;
		if ( ! wp_doing_ajax() ) return false;
		static $ajax_front = null;
		if ( null !== $ajax_front ) return $ajax_front;
		$referer = wp_get_raw_referer();
		$ajax_front = (bool) ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) === wp_parse_url( home_url( '/' ), PHP_URL_HOST ) && 0 !== strpos( (string) wp_parse_url( $referer, PHP_URL_PATH ), (string) wp_parse_url( admin_url(), PHP_URL_PATH ) ) );
		return $ajax_front;
	}
	/** WoodMart stores dropdown markup in localStorage; each language needs its own key. */
	public static function menu_storage_key( $key ) {
		return $key . '_' . LT_Language_Router::instance()->current_language();
	}
	private static function ids() {
		$ids = array( defined( 'WOODMART_HB_DEFAULT_ID' ) ? WOODMART_HB_DEFAULT_ID : 'default_header', get_option( 'whb_main_header' ) );
		foreach ( (array) get_option( 'whb_saved_headers', array() ) as $header ) if ( is_array( $header ) && ! empty( $header['id'] ) ) $ids[] = $header['id'];
		return array_unique( array_filter( $ids, 'is_scalar' ) );
	}
	public static function register_headers() { foreach ( self::ids() as $id ) self::register_header( $id ); }
	public static function register_header( $id ) {
		if ( is_scalar( $id ) && '' !== (string) $id ) {
			add_filter( 'option_whb_' . $id, array( __CLASS__, 'capture' ), 10, 2 );
			add_filter( 'option_whb_' . $id, array( __CLASS__, 'header' ), 30, 2 );
		}
		return $id;
	}
	public static function options( $tree ) {
		// Only the visible label, never menu IDs, styles, links or builder settings.
		$params = array();
		foreach ( self::text_params() as $name ) $params[ $name ] = array( 'value' => true );
		$element = array( 'params' => $params );
		for ( $depth = 0; $depth < 5; $depth++ ) $element = array_merge( $element, array( 'content' => array( '*' => $element ) ) );
		foreach ( self::ids() as $id ) $tree[ 'whb_' . $id ] = array_replace_recursive( (array) ( $tree[ 'whb_' . $id ] ?? array() ), array( 'structure' => $element ) );
		return $tree;
	}
	/** Keep sources before LT_Admin_Texts (priority 20), which may already translate collected options. */
	public static function capture( $value, $option ) {
		if ( LT_Language_Router::instance()->is_secondary() || LT_Page_Texts::tracking() ) self::$originals[ $option ] = $value;
		return $value;
	}
	public static function header( $value, $option ) {
		if ( ! self::frontend() ) return self::$originals[ $option ] ?? $value;
		if ( ! LT_Language_Router::instance()->is_secondary() && ! LT_Page_Texts::tracking() ) return $value;
		if ( ! is_array( $value ) || empty( $value['structure'] ) ) return $value;
		$value['structure'] = self::element( $value['structure'], self::$originals[ $option ]['structure'] ?? $value['structure'], $option, 'structure' );
		return $value;
	}
	private static function text_params() {
		return array( 'content', 'title', 'subtitle', 'btn_text', 'categories_title', 'primary_menu_title', 'secondary_menu_title' );
	}
	private static function element( $node, $original, $option, $path ) {
		if ( ! is_array( $node ) ) return $node;
		foreach ( self::text_params() as $name ) {
			if ( ! isset( $node['params'][ $name ]['value'] ) || ! is_string( $node['params'][ $name ]['value'] ) ) continue;
			$text = $original['params'][ $name ]['value'] ?? $node['params'][ $name ]['value'];
			if ( ! LT_Admin_Texts::is_text( $text ) ) continue;
			$key = LT_Admin_Texts::key( $option, $path . '/params/' . $name . '/value' );
			if ( LT_Page_Texts::tracking() ) LT_Page_Texts::record( 'string_' . md5( $key ), $text, 'site' );
			if ( LT_Language_Router::instance()->is_secondary() ) {
				$translated = self::label( $text, array( $key, $text ), $node['params'][ $name ]['value'] );
				$node['params'][ $name ]['value'] = 'content' === $name ? LT_Content_Merger::merge( $text, $translated ) : $translated;
			}
		}
		foreach ( (array) ( $node['content'] ?? array() ) as $index => $child ) $node['content'][ $index ] = self::element( $child, $original['content'][ $index ] ?? $child, $option, $path . '/content/' . $index );
		return $node;
	}
	public static function placeholder( $translation, $text, $context, $domain ) {
		if ( 'woodmart' !== $domain || 'submit button' !== $context || ! in_array( $text, array( 'Search for products', 'Search for projects', 'Search for pages', 'Search for posts' ), true ) || ! self::frontend() ) return $translation;
		if ( ! LT_Frontend_Filters::domain_allowed( $domain ) && LT_Page_Texts::tracking() ) LT_Page_Texts::record( LT_String_Keys::field( $domain, $context . "\x04" . $text ), $text, $domain, true, LT_String_Keys::legacy( $context . "\x04" . $text ) );
		if ( ! LT_Language_Router::instance()->is_secondary() ) return $translation;
		return self::label( $text, array( $context . "\x04" . $text, $text ), $translation );
	}
	private static function label( $source, $keys, $fallback ) {
		$language = LT_Language_Router::instance()->current_language();
		foreach ( $keys as $key ) {
			$out = LT_Translation_Store::string_lookup( $language, LT_String_Keys::field( 'woodmart', $key ), $source );
			if ( null !== $out ) return $out;
			$out = LT_Translation_Store::string_lookup( $language, 'string_' . md5( $key ), $source );
			if ( null !== $out ) return $out;
		}
		$out = LT_Compat_Texts::lookup( 'woodmart', $source, $language );
		if ( $out !== $source ) return $out;
		$out = LT_Translation_Store::by_text( $language, $source );
		if ( null !== $out ) return $out;
		// WooCommerce writes "All categories"; builder demos use "All Categories".
		if ( in_array( $source, array( 'All Categories', 'All categories' ), true ) ) {
			$out = LT_Translation_Store::by_text( $language, 'All Categories' === $source ? 'All categories' : 'All Categories' );
			if ( null !== $out ) return $out;
		}
		return $fallback;
	}
}

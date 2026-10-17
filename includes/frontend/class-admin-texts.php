<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Site texts" (WPML calls them admin texts): texts typed in settings screens and stored in options,
 * e.g. WoodMart theme options (copyright, empty cart text, cookie notice…), WooCommerce payment methods
 * and shipping methods titles / descriptions, checkout terms & privacy texts, store notice, site title.
 *
 * Which options are translatable comes from:
 * - the wpml-config.xml files shipped by the active theme and plugins (<admin-texts>), like WPML;
 * - a built-in list for WordPress and WooCommerce.
 * "Find site texts" reads them and adds them to Interface texts (source "Site texts"); on translated
 * pages the option values are replaced by their translation.
 */
class LT_Admin_Texts {
	const TREE = 'lt_admin_text_options'; // option name => true | [ key => subtree ]

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		foreach ( array_keys( (array) get_option( self::TREE, array() ) ) as $option ) add_filter( 'option_' . $option, array( $this, 'translate_option' ), 20, 2 );
	}

	/** Lookup key of one value: "admin:{option}/{path}". */
	public static function key( $option, $path ) { return 'admin:' . $option . ( '' === $path ? '' : '/' . $path ); }

	/** Only real sentences: no IDs, slugs, numbers, URLs, colors, JSON. */
	public static function is_text( $value ) {
		if ( ! is_string( $value ) ) return false;
		$value = trim( $value );
		if ( '' === $value || ! preg_match( '/\p{L}{2,}/u', wp_strip_all_tags( $value ) ) ) return false;
		if ( preg_match( '/^[a-z0-9_\-]+$/', $value ) ) return false;                // id / slug / setting value
		if ( filter_var( $value, FILTER_VALIDATE_URL ) || preg_match( '/^(#|rgba?\(|\{|\[)/', $value ) ) return false;
		return true;
	}

	/* ---------- Configuration ---------- */

	private static function parse_keys( $node ) {
		$tree = array();
		foreach ( $node->key as $key ) {
			$name = (string) $key['name'];
			if ( '' === $name ) continue;
			$tree[ $name ] = count( $key->key ) ? self::parse_keys( $key ) : true;
		}
		return $tree;
	}

	/** Options declared translatable by the active theme / plugins (wpml-config.xml). */
	private static function from_wpml_config() {
		$files = array();
		$theme = wp_get_theme();
		foreach ( array_filter( array( $theme, $theme->parent() ) ) as $item ) $files[] = $item->get_stylesheet_directory() . '/wpml-config.xml';
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) $files[] = WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/wpml-config.xml';
		$tree = array();
		foreach ( array_unique( $files ) as $file ) {
			if ( ! is_readable( $file ) || ! function_exists( 'simplexml_load_file' ) ) continue;
			$xml = @simplexml_load_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $xml || ! isset( $xml->{'admin-texts'} ) ) continue;
			$tree = array_replace_recursive( $tree, self::parse_keys( $xml->{'admin-texts'} ) );
		}
		return $tree;
	}

	/** Who declared each translatable option: option name => group of LT_Sources ("theme", "woocommerce", "plugin:{dir}"). */
	public static function origins() {
		$cached = get_transient( 'lt_admin_text_origins' );
		if ( is_array( $cached ) ) return $cached;
		$files = array();
		$theme = wp_get_theme();
		foreach ( array_filter( array( $theme, $theme->parent() ) ) as $item ) $files[ $item->get_stylesheet_directory() . '/wpml-config.xml' ] = 'theme';
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) $files[ WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/wpml-config.xml' ] = LT_Sources::plugin_group( dirname( $plugin ) );
		$origins = array();
		foreach ( $files as $file => $group ) {
			if ( ! is_readable( $file ) || ! function_exists( 'simplexml_load_file' ) ) continue;
			$xml = @simplexml_load_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $xml || ! isset( $xml->{'admin-texts'} ) ) continue;
			foreach ( array_keys( self::parse_keys( $xml->{'admin-texts'} ) ) as $option ) if ( ! isset( $origins[ $option ] ) ) $origins[ $option ] = $group;
		}
		set_transient( 'lt_admin_text_origins', $origins, DAY_IN_SECONDS );
		return $origins;
	}

	/** WordPress + WooCommerce texts typed in settings. */
	private static function builtin() { return apply_filters( 'lt_admin_text_options', array( 'blogname' => true, 'blogdescription' => true ) ); }

	/* ---------- Find & collect ---------- */

	private static function walk( $value, $tree, $path, $callback ) {
		if ( true === $tree ) {
			if ( is_array( $value ) ) { foreach ( $value as $k => $v ) self::walk( $v, true, ltrim( $path . '/' . $k, '/' ), $callback ); return; }
			$callback( $path, $value );
			return;
		}
		if ( ! is_array( $value ) || ! is_array( $tree ) ) return;
		foreach ( $value as $k => $v ) {
			$sub = $tree[ $k ] ?? ( $tree['*'] ?? null );
			if ( null !== $sub ) self::walk( $v, $sub, ltrim( $path . '/' . $k, '/' ), $callback );
		}
	}

	/** Reads every declared option and adds its texts to Interface texts. @return int number of texts */
	public static function collect() {
		$tree = array_replace_recursive( self::builtin(), self::from_wpml_config() );
		update_option( self::TREE, $tree, true );
		$router = LT_Language_Router::instance();
		$langs  = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		$index  = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$count  = 0;
		foreach ( $tree as $option => $subtree ) {
			remove_filter( 'option_' . $option, array( self::instance(), 'translate_option' ), 20 );
			$value = get_option( $option );
			self::walk( $value, $subtree, '', function( $path, $text ) use ( $option, $langs, &$index, &$count ) {
				if ( ! self::is_text( $text ) ) return;
				$field = 'string_' . md5( self::key( $option, $path ) );
				foreach ( $langs as $language ) LT_Translation_Store::collect_empty( $language, $field, $text );
				$index[ $field ] = array( 'd' => 'site', 't' => 'l', 'a' => 'f', 's' => 'site', 'p' => $option . ( '' !== $path ? ' › ' . $path : '' ) );
				$count++;
			} );
		}
		update_option( LT_Code_Scanner::INDEX, $index, false );
		LT_Content_Catalog::strings_changed();
		return $count;
	}

	/* ---------- Front-end ---------- */

	public function translate_option( $value, $option ) {
		$tree = (array) get_option( self::TREE, array() );
		if ( ! isset( $tree[ $option ] ) ) return $value;
		static $tracked = array();
		if ( ! isset( $tracked[ $option ] ) && LT_Page_Texts::tracking() && ( $tracked[ $option ] = true ) ) self::walk( $value, $tree[ $option ], '', function( $path, $text ) use ( $option ) { if ( self::is_text( $text ) ) LT_Page_Texts::record( 'string_' . md5( self::key( $option, $path ) ), $text, 'site' ); } );
		$router = LT_Language_Router::instance();
		if ( ! $router->is_secondary() || ( is_admin() && ! wp_doing_ajax() ) ) return $value;
		$language = $router->current_language();
		// Theme options are read many times per page: translate a given value only once.
		static $done = array();
		$cache_key = $language . '|' . $option;
		if ( isset( $done[ $cache_key ] ) && $done[ $cache_key ][0] === $value ) return $done[ $cache_key ][1];
		if ( true === $tree[ $option ] && ! is_array( $value ) ) {
			$out = self::is_text( $value ) ? LT_Translation_Store::translate( $language, 'string', 0, 'string_' . md5( self::key( $option, '' ) ), $value ) : $value;
		} else {
			$out = self::replace( $value, $tree[ $option ], '', $option, $language );
		}
		$done[ $cache_key ] = array( $value, $out );
		return $out;
	}

	private static function replace( $value, $tree, $path, $option, $language ) {
		if ( ! is_array( $value ) ) {
			return ( true === $tree && self::is_text( $value ) ) ? LT_Translation_Store::translate( $language, 'string', 0, 'string_' . md5( self::key( $option, $path ) ), $value ) : $value;
		}
		foreach ( $value as $k => $v ) {
			$sub = true === $tree ? true : ( $tree[ $k ] ?? ( $tree['*'] ?? null ) );
			if ( null !== $sub ) $value[ $k ] = self::replace( $v, $sub, ltrim( $path . '/' . $k, '/' ), $option, $language );
		}
		return $value;
	}
}

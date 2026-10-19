<?php
defined( 'ABSPATH' ) || exit;

/** ThemeHigh Free/Premium: public labels only, for classic checkout, Blocks and order e-mails. */
class LT_Checkout_Field_Editor_Module {
	const DOMAIN = 'woo-checkout-field-editor-pro';
	private static $reading = false;
	public static function boot() {
		add_filter( 'gettext', array( __CLASS__, 'gettext' ), 40, 3 );
		add_filter( 'wpml_translate_single_string', array( __CLASS__, 'dynamic' ), 40, 3 );
		foreach ( array( 'woocommerce_billing_fields', 'woocommerce_shipping_fields', 'woocommerce_checkout_fields' ) as $hook ) add_filter( $hook, array( __CLASS__, 'fields' ), 100000 );
		foreach ( array( 'thwcfe_block_sections', 'thwcfe_sections' ) as $option ) add_filter( 'option_' . $option, array( __CLASS__, 'blocks' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'prepare' ), 21 );
	}
	private static function domain( $domain ) { return in_array( $domain, array( self::DOMAIN, 'woocommerce-checkout-field-editor', 'woocommerce-checkout-field-editor-pro' ), true ); }
	private static function active() {
		if ( self::$reading ) return false;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && false === strpos( (string) ( $_GET['rest_route'] ?? $_SERVER['REQUEST_URI'] ?? '' ), '/wc/store/' ) ) return false;
		$language = LT_Compat_Texts::language();
		return $language && $language !== LT_Language_Router::instance()->default_language();
	}
	public static function dynamic( $text, $context, $name ) { return self::domain( $context ) && self::active() ? LT_Compat_Texts::lookup( self::DOMAIN, $text ) : $text; }
	public static function gettext( $translation, $source, $domain ) {
		if ( ! self::domain( $domain ) || ! self::active() ) return $translation;
		$value = LT_Compat_Texts::lookup( self::DOMAIN, $source );
		return $value === $source ? $translation : $value;
	}
	/** Mapping an array keeps option keys, defaults, validation and submitted values intact. */
	public static function fields( $fields ) {
		if ( ! self::active() || ! is_array( $fields ) ) return $fields;
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) continue;
			if ( ! isset( $field['type'] ) && ! isset( $field['label'] ) ) { $fields[ $key ] = self::fields( $field ); continue; }
			if ( 'hidden' === ( $field['type'] ?? '' ) ) continue;
			foreach ( array( 'label', 'placeholder', 'description' ) as $part ) if ( isset( $field[ $part ] ) && is_string( $field[ $part ] ) ) $field[ $part ] = LT_Compat_Texts::lookup( self::DOMAIN, $field[ $part ] );
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) foreach ( $field['options'] as $value => $label ) if ( is_string( $label ) ) $field['options'][ $value ] = LT_Compat_Texts::lookup( self::DOMAIN, $label );
			$fields[ $key ] = $field;
		}
		return $fields;
	}
	/** Block sections are objects cached by WP: clone each node before changing presentation properties. */
	private static function walk( $node, $callback, $parent = '' ) {
		if ( ! is_array( $node ) && ! is_object( $node ) ) return $node;
		if ( is_object( $node ) ) $node = clone $node;
		if ( 'hidden' === ( is_object( $node ) ? ( $node->type ?? '' ) : ( $node['type'] ?? '' ) ) ) return $node;
		$presentation = is_object( $node ) ? ( isset( $node->fields ) || isset( $node->type ) ) : ( isset( $node['fields'] ) || isset( $node['type'] ) );
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) $value = self::walk( $value, $callback, (string) $key );
			elseif ( is_string( $value ) && ( in_array( $key, array( 'label', 'placeholder', 'description' ), true ) || ( $presentation && in_array( $key, array( 'title', 'subtitle' ), true ) ) || ( 'text' === $key && 'options' !== $parent ) ) ) $value = $callback( $value );
			if ( is_object( $node ) ) $node->$key = $value; else $node[ $key ] = $value;
		}
		return $node;
	}
	public static function blocks( $sections ) { return self::active() ? self::walk( $sections, function( $text ) { return LT_Compat_Texts::lookup( self::DOMAIN, $text ); } ) : $sections; }
	private static function options( $options ) { return is_array( $options ) ? $options : ( is_string( $options ) ? array_map( 'trim', explode( '|', $options ) ) : array() ); }
	public static function prepare() {
		if ( ! LT_Compat_Texts::admin_screen() ) return;
		self::$reading = true;
		try {
			$texts = array();
			$add = function( $text ) use ( &$texts ) { $texts[] = $text; return $text; };
			foreach ( array( 'billing', 'shipping', 'additional' ) as $section ) foreach ( (array) get_option( 'wc_fields_' . $section, array() ) as $field ) {
				if ( ! is_array( $field ) || 'hidden' === ( $field['type'] ?? '' ) || ( isset( $field['enabled'] ) && ! $field['enabled'] ) ) continue;
				foreach ( array( 'label', 'placeholder', 'description' ) as $key ) if ( ! empty( $field[ $key ] ) ) $add( $field[ $key ] );
				foreach ( self::options( $field['options'] ?? array() ) as $label ) if ( is_string( $label ) ) $add( $label );
			}
			foreach ( array( 'thwcfe_block_sections', 'thwcfe_sections' ) as $option ) self::walk( get_option( $option, array() ), $add );
			LT_Compat_Texts::register( self::DOMAIN, $texts, 'Checkout field labels / Classic and Blocks' );
		} finally { self::$reading = false; }
	}
}

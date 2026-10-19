<?php
defined( 'ABSPATH' ) || exit;

/** WP Overnight 5.16: translate a document at render time, never its saved accounting data. */
class LT_PDF_Invoices_Module {
	const DOMAIN = 'woocommerce-pdf-invoices-packing-slips';
	private static $stack = array();
	private static $originals = array();
	public static function boot() {
		add_action( 'wpo_wcpdf_before_html', array( __CLASS__, 'begin' ), -100, 2 );
		add_action( 'wpo_wcpdf_after_html', array( __CLASS__, 'end' ), 100, 2 );
		add_filter( 'gettext', array( __CLASS__, 'gettext' ), 50, 3 );
		add_filter( 'lt_order_render_language', array( __CLASS__, 'date_language' ) );
		foreach ( array( 'footer', 'extra_1', 'extra_2', 'extra_3', 'shop_address_additional' ) as $key ) add_filter( 'wpo_wcpdf_' . $key . '_settings_text', array( __CLASS__, 'text' ), 30, 2 );
		add_filter( 'wpo_wcpdf_document_title', array( __CLASS__, 'text' ), 30, 2 );
		add_filter( 'wpo_wcpdf_document_language_attributes', array( __CLASS__, 'attributes' ), 30, 2 );
		add_filter( 'wpo_wcpdf_order_item_name', array( __CLASS__, 'item_name' ), 30, 3 );
		add_action( 'admin_init', array( __CLASS__, 'prepare' ), 21 );
	}
	private static function for_document( $document ) {
		$router = LT_Language_Router::instance();
		$order = $document->order ?? null;
		$language = is_object( $order ) && is_callable( array( $order, 'get_meta' ) ) ? (string) $order->get_meta( '_lt_language' ) : '';
		return in_array( $language, $router->languages(), true ) ? $language : $router->default_language();
	}
	public static function begin( $type, $document ) { self::$stack[] = array( $document, self::for_document( $document ) ); }
	public static function end( $type, $document ) {
		for ( $i = count( self::$stack ) - 1; $i >= 0; $i-- ) if ( self::$stack[ $i ][0] === $document ) { array_splice( self::$stack, $i, 1 ); break; }
	}
	public static function language() { return self::$stack ? end( self::$stack )[1] : ''; }
	public static function date_language( $language ) { return self::language() ?: $language; }
	public static function gettext( $translation, $source, $domain ) {
		if ( LT_Email_Translations::reading_original() || ! self::language() || ! in_array( $domain, array( self::DOMAIN, 'woocommerce' ), true ) ) return $translation;
		$value = LT_Compat_Texts::lookup( self::DOMAIN, $source, self::language() );
		if ( $value !== $source ) return $value;
		// An attached document can be prepared inside a different outgoing-mail context.
		$key = $domain . ':' . $source;
		if ( ! isset( self::$originals[ $key ] ) ) self::$originals[ $key ] = LT_Email_Translations::with_original( function() use ( $source, $domain ) { return translate( $source, $domain ); } );
		return LT_Compat_Texts::lookup( self::DOMAIN, self::$originals[ $key ], self::language() );
	}
	public static function text( $text, $document ) {
		$language = self::for_document( $document );
		return LT_Public_HTML::map( $text, function( $part ) use ( $language ) { return LT_Compat_Texts::lookup( self::DOMAIN, $part, $language ); } );
	}
	public static function attributes( $attributes, $document ) {
		$language = esc_attr( self::for_document( $document ) );
		return preg_match( '/\blang=/', $attributes ) ? preg_replace( '/\blang=("|\x27)[^"\x27]*\1/', 'lang="' . $language . '"', $attributes ) : $attributes . ' lang="' . $language . '"';
	}
	public static function item_name( $name, $item, $order ) {
		$language = self::language();
		if ( ! $language || ! is_object( $item ) || ! is_callable( array( $item, 'get_product' ) ) ) return $name;
		$product = $item->get_product();
		if ( $product && $product->is_type( 'variation' ) ) $product = wc_get_product( $product->get_parent_id() );
		if ( ! $product ) return $name;
		$source = $product->get_name( 'edit' );
		$value = LT_Translation_Store::translate( $language, 'product', $product->get_id(), 'title', $source );
		return $source && $value !== $source ? str_replace( $source, $value, $name ) : $name;
	}
	public static function prepare() {
		if ( ! LT_Compat_Texts::admin_screen() ) return;
		$texts = array( 'Invoice', 'Packing Slip', 'Invoice Number:', 'Invoice Date:', 'Order Number:', 'Order Date:', 'Payment Method:', 'Payment Date:', 'Shipping Method:', 'Ship To:', 'Billing Address:', 'Shipping Address:', 'Due Date:', 'SKU:', 'Weight:', 'Notes:', 'Customer Notes:', '%s Number:', '%s Date:', 'Product', 'Quantity', 'Price', 'Description', 'Total', 'Subtotal', 'Shipping', 'VAT', 'Tax', 'N/A', '(includes %s)', 'Discount', 'Total ex. VAT', '%s Date', 'Order Date', 'Reason for refund:' );
		$settings = WPO_WCPDF()->settings->get_common_document_settings();
		foreach ( array( 'invoice', 'packing-slip' ) as $type ) $settings = array_merge_recursive( (array) $settings, (array) WPO_WCPDF()->settings->get_document_settings( $type, 'pdf' ) );
		$add = function( $text ) use ( &$texts ) { if ( is_string( $text ) ) LT_Public_HTML::map( $text, function( $part ) use ( &$texts ) { $texts[] = $part; return $part; } ); };
		foreach ( array( 'footer', 'extra_1', 'extra_2', 'extra_3', 'shop_address_additional' ) as $key ) {
			$values = $settings[ $key ] ?? '';
			if ( is_array( $values ) ) array_walk_recursive( $values, $add ); else $add( $values );
		}
		LT_Compat_Texts::register( self::DOMAIN, $texts, 'PDF document / Invoice and packing slip' );
	}
}

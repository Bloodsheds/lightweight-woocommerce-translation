<?php
defined( 'ABSPATH' ) || exit;

/** Admin-only navigation. Classification is a suggested use, never proof of public visibility. */
class LT_WooCommerce_Text_Groups {
	private static $catalog;
	
	public static function boot() {
		add_filter( 'lt_string_export_args', array( __CLASS__, 'export_args' ) );
		add_filter( 'lt_export_string_include', array( __CLASS__, 'export_include' ), 10, 4 );
		add_filter( 'lt_string_view_args', array( __CLASS__, 'view_args' ), 10, 3 );
		add_filter( 'lt_string_tags', array( __CLASS__, 'tags' ), 10, 4 );
	}
	private static function relevant( $args ) { return 'woocommerce' === ( $args['group'] ?? '' ) && 'texts' === ( $args['sub'] ?? '' ); }
	public static function areas() {
		return array(
			'products' => array( __( 'Products & catalog', 'lightweight-translation' ), __( 'Product buttons, stock, options and reviews.', 'lightweight-translation' ) ),
			'cart' => array( __( 'Cart & mini-cart', 'lightweight-translation' ), __( 'Cart contents, coupons, totals and empty cart.', 'lightweight-translation' ) ),
			'checkout' => array( __( 'Checkout & payment', 'lightweight-translation' ), __( 'Addresses, delivery, payment and checkout validation.', 'lightweight-translation' ) ),
			'account' => array( __( 'Customer account', 'lightweight-translation' ), __( 'Sign in, registration, password and personal details.', 'lightweight-translation' ) ),
			'orders' => array( __( 'Orders & tracking', 'lightweight-translation' ), __( 'Confirmation, order details and tracking.', 'lightweight-translation' ) ),
			'dynamic' => array( __( 'AJAX, API & JSON', 'lightweight-translation' ), __( 'Texts returned by live shop requests and structured API responses.', 'lightweight-translation' ) ),
			'messages' => array( __( 'Shared labels & messages', 'lightweight-translation' ), __( 'Shared wording and messages whose exact screen depends on the situation.', 'lightweight-translation' ) ),
			'scripts' => array( __( 'JavaScript & blocks', 'lightweight-translation' ), __( 'Interface texts present in WooCommerce frontend scripts.', 'lightweight-translation' ) ),
			'emails' => array( __( 'Customer e-mails', 'lightweight-translation' ), __( 'Messages sent to customers; these are not all displayed on the website.', 'lightweight-translation' ) ),
			'geography' => array( __( 'Countries & regions', 'lightweight-translation' ), __( 'Reference lists used by address selectors. Translate only if needed.', 'lightweight-translation' ) ),
			'other' => array( __( 'Other collected texts', 'lightweight-translation' ), __( 'Older or additional texts without a confirmed customer use. Kept with their translations.', 'lightweight-translation' ) ),
		);
	}
	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$data = json_decode( (string) file_get_contents( __DIR__ . '/catalog.json' ), true );
		$map = array();
		foreach ( (array) ( $data['entries'] ?? array() ) as $entry ) {
			$prefix = '' !== $entry['context'] ? $entry['context'] . "\x04" : '';
			foreach ( array_filter( array( $entry['id'], $entry['plural'] ), 'strlen' ) as $text ) $map[ 'string_' . md5( $prefix . $text ) ] = ! empty( $entry['js'] ) ? array_values( array_unique( array_merge( $entry['areas'], array( 'scripts' ) ) ) ) : $entry['areas'];
		}
		foreach ( (array) ( $data['reference_keys'] ?? array() ) as $key ) $map[ $key ] = array( 'geography' );
		foreach ( (array) ( $data['excluded_keys'] ?? array() ) as $key ) $map[ $key ] = array( 'other' );
		return self::$catalog = $map;
	}
	public static function classify( $field, $entry = array() ) {
		$map = self::catalog();
		if ( isset( $map[ $field ] ) ) return $map[ $field ];
		$path = (string) ( $entry['p'] ?? '' );
		if ( preg_match( '#(?:i18n/(?:countries|states|continents)|country|region)\b#i', $path ) ) return array( 'geography' );
		if ( ! empty( $entry['e'] ) ) return array( 'emails' );
		return array( 'other' );
	}
	public static function view_args( $args, $texts, $index ) {
		if ( ! self::relevant( $args ) ) return $args;
		unset( $args['view'] );
		$args['area'] = isset( self::areas()[ $args['area'] ] ) ? $args['area'] : '';
		$keys = array();
		foreach ( $texts as $field => $text ) {
			$entry = $index[ $field ] ?? array();
			if ( 'woocommerce' !== LT_Sources::group_of( $field, $entry ) || 'texts' !== LT_Sources::part_of( $entry ) ) continue;
			if ( '' !== $args['area'] && ! in_array( $args['area'], self::classify( $field, $entry ), true ) ) continue;
			$keys[] = $field;
		}
		$args['keys'] = null === $args['keys'] ? $keys : array_values( array_intersect( $args['keys'], $keys ) );
		return $args;
	}
	public static function category_filter( $args ) {
		// The shared source sidebar owns this navigation; retain the method for third-party compatibility.
	}

	public static function export_args( $args ) {
		if ( 'woocommerce' !== ( $args['string_group'] ?? '' ) || 'lt-woocommerce' !== ( $_GET['page'] ?? '' ) || 'settings' === ( $_GET['sub'] ?? '' ) ) return $args;
		$args['string_area'] = sanitize_key( $_GET['area'] ?? '' );
		$args['string_part'] = 'texts';
		return $args;
	}
	public static function export_include( $include, $field, $entry, $request ) {
		if ( ! $include || 'woocommerce' !== ( $request['string_group'] ?? '' ) || 'texts' !== ( $request['string_part'] ?? '' ) ) return $include;
		if ( 'texts' !== LT_Sources::part_of( $entry ) ) return false;
		$area = sanitize_key( $request['string_area'] ?? '' );
		if ( $area && ! in_array( $area, self::classify( $field, (array) $entry ), true ) ) return false;
		return true;
	}

	public static function tags( $tags, $row, $entry, $args ) {
		if ( ! self::relevant( $args ) ) return $tags;
		$areas = self::areas();
		$names = array();
		foreach ( $row->keys ?? array( $row->field_key ) as $field ) {
			foreach ( self::classify( $field, $entry ) as $area ) $names[ $area ] = $areas[ $area ][0];
		}
		$tags[] = esc_html( implode( ', ', $names ) );
		return $tags;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/** Availability is checked before any integration class is loaded. */
class LT_Modules {
	private static $active = array();
	private static $booted = false;
	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		$modules = apply_filters( 'lt_modules', array(
			'woocommerce' => array( 'label' => 'WooCommerce', 'available' => function() { return class_exists( 'WooCommerce', false ); }, 'boot' => array( 'LT_WooCommerce_Module', 'boot' ) ),
			'woodmart' => array( 'label' => 'WoodMart', 'available' => function() { return 'woodmart' === strtolower( (string) get_template() ); }, 'boot' => array( 'LT_WoodMart_Module', 'boot' ) ),
			'product-bundles' => array( 'label' => 'Product Bundles', 'available' => function() { return class_exists( 'WooCommerce', false ) && class_exists( 'WC_Bundles', false ); }, 'boot' => array( 'LT_Product_Bundles_Module', 'boot' ) ),
		) );
		foreach ( $modules as $id => $module ) {
			if ( empty( $module['available'] ) || ! is_callable( $module['available'] ) || ! call_user_func( $module['available'] ) ) continue;
			if ( empty( $module['boot'] ) || ! is_callable( $module['boot'] ) ) continue;
			call_user_func( $module['boot'] );
			self::$active[ $id ] = $module['label'];
		}
		do_action( 'lt_modules_loaded', self::$active );
	}
	public static function active( $id ) { return isset( self::$active[ $id ] ); }
	public static function all() { return self::$active; }
}

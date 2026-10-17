<?php
defined( 'ABSPATH' ) || exit;

class LT_WooCommerce_Module {
	public static function boot() {
		new LT_WooCommerce_Frontend();
		foreach ( array( 'woocommerce_attribute_updated', 'woocommerce_attribute_added' ) as $hook ) add_action( $hook, array( 'LT_Content_Catalog', 'attributes_changed' ) );
		LT_Email_Translations::instance();
		add_filter( 'lt_admin_text_options', array( __CLASS__, 'options' ) );
		add_filter( 'lt_post_fields', array( __CLASS__, 'fields' ), 10, 2 );
		if ( is_admin() ) {
			LT_Email_Page::instance();
			LT_WooCommerce_Text_Groups::boot();
			add_action( 'admin_init', array( 'LT_WooCommerce_Catalog', 'prepare' ), 20 );
			new LT_WooCommerce_Attribute_Editor();
		}
	}
	public static function fields( $fields, $post ) {
		if ( 'product' === $post->post_type ) {
			unset( $fields['excerpt'] );
			$fields['short_description'] = $post->post_excerpt;
		} elseif ( 'product_variation' === $post->post_type ) {
			$fields = array( 'title' => $post->post_title, 'content' => (string) get_post_meta( $post->ID, '_variation_description', true ) );
		}
		return $fields;
	}

	public static function options( $tree ) {
		foreach ( array( 'woocommerce_demo_store_notice', 'woocommerce_checkout_privacy_policy_text', 'woocommerce_registration_privacy_policy_text', 'woocommerce_checkout_terms_and_conditions_checkbox_text', 'woocommerce_email_footer_text' ) as $option ) $tree[ $option ] = true;
		// Payment methods: title, description, instructions, button text.
		if ( WC()->payment_gateways() ) foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) $tree[ 'woocommerce_' . $gateway->id . '_settings' ] = array( 'title' => true, 'description' => true, 'instructions' => true, 'order_button_text' => true );
		// Shipping methods of every zone: title.
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$zones = WC_Shipping_Zones::get_zones();
			$zones[] = array( 'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods() );
			foreach ( $zones as $zone ) foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) if ( is_callable( array( $method, 'get_instance_option_key' ) ) ) $tree[ $method->get_instance_option_key() ] = array( 'title' => true );
		}
		return $tree;
	}
}

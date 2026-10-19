<?php
defined( 'ABSPATH' ) || exit;

class LT_WooCommerce_Module {
	public static function boot() {
		new LT_WooCommerce_Frontend();
		foreach ( array( 'woocommerce_attribute_updated', 'woocommerce_attribute_added' ) as $hook ) add_action( $hook, array( 'LT_Content_Catalog', 'attributes_changed' ) );
		LT_Email_Translations::instance();
		LT_Order_Translations::boot();
		LT_Account_Translations::boot();
		add_filter( 'lt_admin_text_options', array( __CLASS__, 'options' ) );
		add_filter( 'lt_post_fields', array( __CLASS__, 'fields' ), 10, 2 );
		if ( is_admin() ) {
			LT_Email_Page::instance();
			LT_WooCommerce_Text_Groups::boot();
			add_action( 'admin_init', array( 'LT_WooCommerce_Catalog', 'prepare' ), 20 );
			add_action( 'admin_init', array( __CLASS__, 'prepare_taxes' ), 21 );
			add_action( 'admin_init', array( __CLASS__, 'refresh_site_texts' ), 22 );
			new LT_WooCommerce_Attribute_Editor();
		}
	}
	public static function fields( $fields, $post ) {
		if ( 'product' === $post->post_type ) {
			unset( $fields['excerpt'] );
			$fields['short_description'] = $post->post_excerpt;
			// Local (custom) attributes: name and values ("Red | Blue"), shown in "Additional information".
			$attributes = get_post_meta( $post->ID, '_product_attributes', true );
			if ( is_array( $attributes ) ) foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || '' === trim( (string) ( $attribute['name'] ?? '' ) ) ) continue;
				$slug = sanitize_title_with_dashes( (string) $attribute['name'] );
				$fields[ LT_WooCommerce_Frontend::attribute_field( $slug, 'name' ) ] = (string) $attribute['name'];
				$values = implode( ' | ', array_filter( array_map( 'trim', explode( '|', (string) ( $attribute['value'] ?? '' ) ) ), 'strlen' ) );
				if ( '' !== $values ) $fields[ LT_WooCommerce_Frontend::attribute_field( $slug, 'values' ) ] = $values;
			}
		} elseif ( 'product_variation' === $post->post_type ) {
			// A variation's name is composed from its parent's title and attribute values.
			// Only its own description needs a separate translation row.
			$fields = array( 'content' => (string) get_post_meta( $post->ID, '_variation_description', true ) );
		}
		return $fields;
	}

	/** Tax rate names (VAT, TVA…) are stored in WooCommerce's own table, outside gettext: listed with the WooCommerce texts. */
	public static function prepare_taxes() {
		if ( ! LT_Compat_Texts::admin_screen() ) return;
		global $wpdb;
		$names = $wpdb->get_col( "SELECT DISTINCT tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name <> ''" ); // phpcs:ignore WordPress.DB
		if ( $names ) LT_Compat_Texts::register( 'woocommerce', array_map( 'strval', $names ), 'WooCommerce / Taxes' );
	}

	/**
	 * A payment or shipping method added after "Find site texts" has no translatable texts until the list is read again:
	 * on a Translations screen, the list is read again as soon as a WooCommerce setting is missing from it.
	 */
	public static function refresh_site_texts() {
		if ( ! LT_Compat_Texts::admin_screen() || ! class_exists( 'LT_Admin_Texts' ) ) return;
		$stored = (array) get_option( LT_Admin_Texts::TREE, array() );
		if ( ! $stored ) return; // never read: "Find site texts" does it the first time
		if ( array_diff_key( self::options( array() ), $stored ) ) LT_Admin_Texts::collect();
	}

	public static function options( $tree ) {
		foreach ( array( 'woocommerce_demo_store_notice', 'woocommerce_checkout_privacy_policy_text', 'woocommerce_registration_privacy_policy_text', 'woocommerce_checkout_terms_and_conditions_checkbox_text', 'woocommerce_email_footer_text', 'woocommerce_price_display_suffix' ) as $option ) $tree[ $option ] = true;
		// Local pickup (block checkout): its title, and the name and details of each pickup place.
		$tree['woocommerce_pickup_location_settings'] = array( 'title' => true );
		$tree['pickup_location_pickup_locations']     = array( '*' => array( 'name' => true, 'details' => true ) );
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

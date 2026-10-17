<?php
defined( 'ABSPATH' ) || exit;

/** Bundle-specific labels belong to the parent product, so existing editors and CSV can handle them. */
class LT_Product_Bundles_Module {
	public static function boot() {
		add_filter( 'lt_post_fields', array( __CLASS__, 'fields' ), 30, 2 );
		add_filter( 'woocommerce_bundled_item_title', array( __CLASS__, 'title' ), 20, 2 );
		add_filter( 'woocommerce_bundled_item_description', array( __CLASS__, 'description' ), 20, 2 );
	}
	public static function fields( $fields, $post ) {
		if ( 'product' !== $post->post_type ) return $fields;
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'bundle' ) || ! is_callable( array( $product, 'get_bundled_data_items' ) ) ) return $fields;
		foreach ( $product->get_bundled_data_items( 'edit' ) as $item ) foreach ( array( 'title', 'description' ) as $part ) {
			if ( 'yes' === $item->get_meta( 'override_' . $part ) ) $fields[ 'bundle_item_' . $part . '_' . $item->get_id() ] = (string) $item->get_meta( $part );
		}
		return $fields;
	}
	private static function row( $item, $part ) {
		if ( ! LT_Language_Router::instance()->is_secondary() || ( is_admin() && ! wp_doing_ajax() ) ) return null;
		return LT_Translation_Store::get_translated( LT_Language_Router::instance()->current_language(), 'product', $item->get_bundle_id(), 'bundle_item_' . $part . '_' . $item->get_id() );
	}
	public static function title( $value, $item ) {
		$row = self::row( $item, 'title' );
		return $row && LT_Translation_Store::source_matches( $row, $value ) ? $row->translated_text : $value;
	}
	public static function description( $value, $item ) {
		$row = self::row( $item, 'description' );
		if ( ! $row ) return $value;
		$data = $item->get_data();
		$source = (string) ( $data['description'] ?? '' );
		if ( '' === $source ) return $value;
		return wpautop( do_shortcode( wp_kses_post( LT_Content_Merger::from_row( $source, $row ) ) ) );
	}
}

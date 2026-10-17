<?php
defined( 'ABSPATH' ) || exit;

class LT_WoodMart_Module {
	public static function boot() {
		LT_WoodMart_Compat::instance();
		add_filter( 'lt_post_fields', array( __CLASS__, 'fields' ), 20, 2 );
		add_filter( 'lt_term_fields', array( __CLASS__, 'term_fields' ), 20, 2 );
		add_filter( 'lt_default_string_domains', function( $domains ) { if ( defined( 'WOODMART_CORE_PLUGIN_VERSION' ) ) $domains[] = 'woodmart-core'; return $domains; } );
	}
	public static function fields( $fields, $post ) {
		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) return $fields;
		if ( 'product_variation' === $post->post_type ) {
			$fields['woodmart_variation_title'] = (string) get_post_meta( $post->ID, 'variation_title', true );
			$fields['woodmart_variation_description'] = (string) get_post_meta( $post->ID, '_variation_description', true );
		}
		for ( $tab = 1; $tab <= 20; $tab++ ) foreach ( array( 'title', 'content' ) as $part ) {
			$value = get_post_meta( $post->ID, '_woodmart_product_custom_tab_' . $part . $tab, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) $fields[ 'woodmart_tab_' . $part . '_' . $tab ] = $value;
		}
		return $fields;
	}
	public static function term_fields( $fields, $term ) {
		if ( 'product_cat' === $term->taxonomy ) $fields['woodmart_category_extra_description_text'] = (string) get_term_meta( $term->term_id, 'category_extra_description_text', true );
		return $fields;
	}
}

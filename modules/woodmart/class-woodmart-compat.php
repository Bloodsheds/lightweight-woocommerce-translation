<?php
defined( 'ABSPATH' ) || exit;

/** Compatibility layer for WoodMart's text-like post and term meta. */
class LT_WoodMart_Compat {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20, 5 );
		add_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20, 5 );
	}
	private function active() { return class_exists( 'LT_Language_Router' ) && LT_Language_Router::instance()->is_secondary(); }
	public function post_meta( $value, $object_id, $meta_key, $single, $meta_type ) {
		if ( ! $this->active() || 'post' !== $meta_type || ! is_string( $meta_key ) ) return $value;
		$field = '';
		if ( preg_match( '/^_woodmart_product_custom_tab_(title|content)(\d+)$/', $meta_key, $m ) ) $field = 'woodmart_tab_' . $m[1] . '_' . $m[2];
		elseif ( 'variation_title' === $meta_key ) $field = 'woodmart_variation_title';
		elseif ( '_variation_description' === $meta_key ) $field = 'woodmart_variation_description';
		if ( ! $field ) return $value;
		$raw = $this->raw_meta( $object_id, $meta_key, $single );
		if ( ! is_string( $raw ) || '' === $raw ) return $value;
		$language = LT_Language_Router::instance()->current_language();
		$translated = LT_Translation_Store::translate( $language, get_post_type( $object_id ), $object_id, $field, $raw );
		if ( false !== strpos( $field, 'content' ) || false !== strpos( $field, 'description' ) ) $translated = LT_Content_Merger::merge( $raw, $translated );
		return $single ? $translated : array( $translated );
	}
	public function term_meta( $value, $object_id, $meta_key, $single, $meta_type ) {
		if ( ! $this->active() || 'term' !== $meta_type || ! in_array( $meta_key, array( 'category_extra_description_text', 'category_extra_description_html_block' ), true ) ) return $value;
		$raw = $this->raw_term_meta( $object_id, $meta_key, $single );
		if ( ! is_string( $raw ) || '' === $raw || 'category_extra_description_html_block' === $meta_key ) return $value;
		$translated = LT_Translation_Store::translate( LT_Language_Router::instance()->current_language(), 'term', $object_id, 'woodmart_' . $meta_key, $raw );
		$translated = LT_Content_Merger::merge( $raw, $translated );
		return $single ? $translated : array( $translated );
	}
	private function raw_meta( $id, $key, $single ) { remove_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20 ); $raw = get_post_meta( $id, $key, $single ); add_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20, 5 ); return $raw; }
	private function raw_term_meta( $id, $key, $single ) { remove_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20 ); $raw = get_term_meta( $id, $key, $single ); add_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20, 5 ); return $raw; }
}

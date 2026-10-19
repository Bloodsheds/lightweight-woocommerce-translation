<?php
defined( 'ABSPATH' ) || exit;

/** Compatibility layer for WoodMart's text-like post and term meta. */
class LT_WoodMart_Compat {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20, 5 );
		add_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20, 5 );
		add_filter( 'wpml_object_id', array( $this, 'size_guide' ), 30, 2 );
	}
	private $guide_title = null; // [ original, translation ] of the size guide about to be printed
	private function active() { return class_exists( 'LT_Language_Router' ) && LT_Language_Router::instance()->is_secondary(); }
	public function post_meta( $value, $object_id, $meta_key, $single, $meta_type ) {
		// Runs for every post meta read of the page: the meta key is checked before the language.
		if ( 'post' !== $meta_type || ! is_string( $meta_key ) ) return $value;
		$field = '';
		if ( 0 === strpos( $meta_key, '_woodmart_product_custom_tab_' ) && preg_match( '/^_woodmart_product_custom_tab_(title|content)(?:_([2-5]))?$/', $meta_key, $m ) ) $field = 'woodmart_tab_' . $m[1] . '_' . ( $m[2] ?? '1' );
		elseif ( 'variation_title' === $meta_key ) $field = 'woodmart_variation_title';
		elseif ( '_variation_description' === $meta_key ) $field = 'woodmart_variation_description';
		elseif ( 'product_tab_title' === $meta_key ) $field = 'woodmart_product_tab_title';
		elseif ( 'woodmart_sguide' === $meta_key ) return $this->active() ? $this->size_table( $object_id, $single, $value ) : $value;
		if ( ! $field || ! $this->active() ) return $value;
		$raw = $this->raw_meta( $object_id, $meta_key, $single );
		if ( ! is_string( $raw ) || '' === $raw ) return $value;
		$language = LT_Language_Router::instance()->current_language();
		$translated = LT_Translation_Store::translate( $language, get_post_type( $object_id ), $object_id, $field, $raw );
		if ( false !== strpos( $field, 'content' ) || false !== strpos( $field, 'description' ) ) $translated = LT_Content_Merger::merge( $raw, $translated );
		return $single ? $translated : array( $translated );
	}
	public function term_meta( $value, $object_id, $meta_key, $single, $meta_type ) {
		if ( 'term' !== $meta_type || ! in_array( $meta_key, array( 'category_extra_description_text', 'category_extra_description_html_block' ), true ) || ! $this->active() ) return $value;
		$raw = $this->raw_term_meta( $object_id, $meta_key, $single );
		if ( ! is_string( $raw ) || '' === $raw || 'category_extra_description_html_block' === $meta_key ) return $value;
		$translated = LT_Translation_Store::translate( LT_Language_Router::instance()->current_language(), 'term', $object_id, 'woodmart_' . $meta_key, $raw );
		$translated = LT_Content_Merger::merge( $raw, $translated );
		return $single ? $translated : array( $translated );
	}
	/** Size guide table (rows of cells): each cell with letters is a field "woodmart_sguide_{row}_{column}". */
	private function size_table( $object_id, $single, $value ) {
		$raw = $this->raw_meta( $object_id, 'woodmart_sguide', true );
		if ( ! is_array( $raw ) ) return $value;
		$language = LT_Language_Router::instance()->current_language();
		$type     = get_post_type( $object_id );
		foreach ( $raw as $r => $row ) {
			if ( ! is_array( $row ) ) continue;
			foreach ( $row as $c => $cell ) if ( is_string( $cell ) && preg_match( '/\p{L}/u', $cell ) ) $raw[ $r ][ $c ] = LT_Translation_Store::translate( $language, $type, $object_id, self::cell_field( $r, $c ), $cell );
		}
		return $single ? $raw : array( $raw );
	}
	public static function cell_field( $row, $column ) { return 'woodmart_sguide_' . absint( $row ) . '_' . absint( $column ); }

	/**
	 * WoodMart prints the size guide title straight from the post (esc_html( $post->post_title )), right after
	 * announcing the guide with wpml_object_id: that one printing is translated.
	 */
	public function size_guide( $id, $type = '' ) {
		if ( 'woodmart_size_guide' !== $type || ! is_numeric( $id ) || ! $id || ! $this->active() ) return $id;
		$title = (string) get_post_field( 'post_title', (int) $id, 'raw' );
		$translated = '' !== $title ? LT_Translation_Store::translate( LT_Language_Router::instance()->current_language(), 'woodmart_size_guide', (int) $id, 'title', $title ) : $title;
		if ( $translated === $title ) return $id;
		if ( null === $this->guide_title ) add_filter( 'esc_html', array( $this, 'guide_title' ), 20, 2 );
		$this->guide_title = array( $title, $translated );
		return $id;
	}
	public function guide_title( $safe, $text ) {
		if ( ! $this->guide_title || (string) $text !== $this->guide_title[0] ) return $safe;
		$out = _wp_specialchars( wp_check_invalid_utf8( $this->guide_title[1] ), ENT_QUOTES );
		$this->guide_title = null;
		remove_filter( 'esc_html', array( $this, 'guide_title' ), 20 );
		return $out;
	}
	private function raw_meta( $id, $key, $single ) { remove_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20 ); $raw = get_post_meta( $id, $key, $single ); add_filter( 'get_post_metadata', array( $this, 'post_meta' ), 20, 5 ); return $raw; }
	private function raw_term_meta( $id, $key, $single ) { remove_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20 ); $raw = get_term_meta( $id, $key, $single ); add_filter( 'get_term_metadata', array( $this, 'term_meta' ), 20, 5 ); return $raw; }
}

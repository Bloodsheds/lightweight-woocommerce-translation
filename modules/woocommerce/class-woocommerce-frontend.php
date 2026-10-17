<?php
defined( 'ABSPATH' ) || exit;

/** Product properties are translated only while the WooCommerce integration is active. */
class LT_WooCommerce_Frontend extends LT_Frontend_Filters {
	public function __construct() {
		foreach ( array( 'woocommerce_product_get_name', 'woocommerce_product_variation_get_name' ) as $hook ) add_filter( $hook, array( $this, 'product_name' ), 20, 2 );
		foreach ( array( 'woocommerce_product_get_description', 'woocommerce_product_variation_get_description' ) as $hook ) add_filter( $hook, array( $this, 'product_description' ), 20, 2 );
		add_filter( 'woocommerce_product_title', array( $this, 'product_title' ), 20, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'product_short' ), 20, 2 );
		add_filter( 'woocommerce_short_description', array( $this, 'woocommerce_short_description' ), 1 );
		add_filter( 'woocommerce_attribute_label', array( $this, 'attribute_label' ), 20, 3 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'variation_option' ), 20 );
		add_filter( 'lt_title_translation', array( $this, 'title_translation' ), 10, 4 );
		add_filter( 'lt_excerpt_field', function( $field, $type ) { return 'product' === $type ? 'short_description' : $field; }, 10, 2 );
	}
	public function title_translation( $translated, $source, $type, $id ) { return 'product_variation' === $type ? $this->variation_name( $id, $source ) : $translated; }
	private function variation_name( $variation_id, $value ) {
		$own = $this->t( 'product_variation', $variation_id, 'title', $value );
		if ( $own !== $value ) return $own;
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		$parent    = $parent_id ? (string) get_post_field( 'post_title', $parent_id, 'raw' ) : '';
		if ( '' === $parent || 0 !== strpos( (string) $value, $parent ) ) return $value;
		$suffix = substr( (string) $value, strlen( $parent ) );
		if ( '' !== $suffix && function_exists( 'wc_get_product_variation_attributes' ) ) {
			$map = array();
			foreach ( wc_get_product_variation_attributes( $variation_id ) as $key => $slug ) {
				$taxonomy = substr( (string) $key, 10 ); // "attribute_"
				if ( '' === (string) $slug ) continue;
				if ( taxonomy_exists( $taxonomy ) ) {
					$found = get_term_by( 'slug', $slug, $taxonomy );
					$term  = $found ? WP_Term::get_instance( $found->term_id, $taxonomy ) : null; // unfiltered: original name
					if ( $term instanceof WP_Term ) $map[ $term->name ] = $this->t( 'term', $term->term_id, 'term_name', $term->name );
				} else {
					$map[ (string) $slug ] = $this->variation_option( (string) $slug );
				}
			}
			$map = array_filter( $map, function ( $v, $k ) { return $v !== $k && '' !== (string) $k; }, ARRAY_FILTER_USE_BOTH );
			if ( $map ) {
				uksort( $map, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } ); // longest first
				$suffix = preg_replace_callback( '/(?<=^|[\s,])(' . implode( '|', array_map( function ( $k ) { return preg_quote( $k, '/' ); }, array_keys( $map ) ) ) . ')(?=$|,)/u', function ( $m ) use ( $map ) { return $map[ $m[1] ]; }, $suffix );
			}
		}
		return $this->t( 'product', $parent_id, 'title', $parent ) . $suffix;
	}

	public function product_title( $value, $product = null ) {
		if ( ! $this->active() || ! is_object( $product ) || ! is_callable( array( $product, 'is_type' ) ) || ! $product->is_type( 'variation' ) ) return $value;
		$parent_id = absint( $product->get_parent_id() );
		$raw       = $parent_id ? (string) get_post_field( 'post_title', $parent_id, 'raw' ) : '';
		if ( '' === $raw || ( (string) $value !== $raw && (string) $value !== wptexturize( $raw ) ) ) return $value;
		$translated = $this->t( 'product', $parent_id, 'title', $raw );
		return $translated === $raw ? $value : $translated;
	}

	public function product_name( $value, $product ) { if ( ! $this->active() || ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) return $value; if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) ) return $this->variation_name( $product->get_id(), $value ); return $this->t( 'product', $product->get_id(), 'title', $value ); }

	public function product_description( $value, $product ) { if ( ! $this->active() || ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) return $value; $type = is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) ? 'product_variation' : 'product'; $source = is_callable( array( $product, 'get_description' ) ) ? $product->get_description( 'edit' ) : $value; return $this->text_only( $type, $product->get_id(), 'content', $source ); }

	public function product_short( $value, $product ) { if ( ! $this->active() || ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) return $value; $source = is_callable( array( $product, 'get_short_description' ) ) ? $product->get_short_description( 'edit' ) : $value; return $this->text_only( 'product', $product->get_id(), 'short_description', $source ); }

	public function woocommerce_short_description( $value ) {
		global $post;
		if ( ! $this->active() || ! $post instanceof WP_Post || 'product' !== $post->post_type ) return $value;
		// This filter also receives other texts (e.g. the description of the chosen variation, often empty):
		// only the product's own short description is replaced by its translation.
		if ( '' === trim( (string) $value ) || trim( (string) $value ) !== trim( (string) $post->post_excerpt ) ) return $value;
		return $this->text_only( 'product', $post->ID, 'short_description', $post->post_excerpt );
	}

	public function attribute_label( $label, $name, $product = null ) {
		if ( ! $this->active() || ! function_exists( 'wc_get_attribute' ) || ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) return $label;
		$attribute_name = 0 === strpos( (string) $name, 'pa_' ) ? substr( (string) $name, 3 ) : (string) $name;
		$attribute_id = absint( wc_attribute_taxonomy_id_by_name( $attribute_name ) );
		return $attribute_id ? $this->t( 'attribute', $attribute_id, 'label', $label ) : $label;
	}

	public function variation_option( $value ) { return $this->active() ? $this->t( 'string', 0, 'variation_' . md5( $value ), $value ) : $value; }
}

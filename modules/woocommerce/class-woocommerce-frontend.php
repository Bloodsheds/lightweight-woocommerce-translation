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
		add_filter( 'woocommerce_shipping_rate_label', array( $this, 'shipping_rate_label' ), 20, 2 );
		add_filter( 'woocommerce_rate_label', array( $this, 'tax_rate_label' ), 20, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'shipping_packages' ), 20 );
		add_filter( 'woocommerce_display_product_attributes', array( $this, 'local_attributes' ), 20, 2 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'variation_option' ), 20 );
		add_filter( 'lt_title_translation', array( $this, 'title_translation' ), 10, 4 );
		add_filter( 'lt_excerpt_field', function( $field, $type ) { return 'product' === $type ? 'short_description' : $field; }, 10, 2 );
	}
	public function title_translation( $translated, $source, $type, $id ) { return 'product_variation' === $type ? $this->variation_name( $id, $source ) : $translated; }
	private function variation_name( $variation_id, $value ) {
		// WoodMart can replace WooCommerce's generated name with an explicitly saved title.
		if ( class_exists( 'LT_WoodMart_Compat' ) ) {
			$custom = get_post_meta( $variation_id, 'variation_title', true );
			if ( is_string( $custom ) && '' !== trim( $custom ) && (string) $value === $custom ) return $value;
		}
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		$parent    = $parent_id ? (string) get_post_field( 'post_title', $parent_id, 'raw' ) : '';
		if ( '' === $parent ) return $value;
		$translated_parent = $this->t( 'product', $parent_id, 'title', $parent );
		$prefixes = array( array( $parent, $translated_parent ) );
		if ( $translated_parent !== $parent ) $prefixes[] = array( $translated_parent, $translated_parent );
		$original_display = wptexturize( $parent );
		if ( $original_display !== $parent ) $prefixes[] = array( $original_display, wptexturize( $translated_parent ) );
		$translated_display = wptexturize( $translated_parent );
		if ( $translated_display !== $translated_parent ) $prefixes[] = array( $translated_display, $translated_display );
		$prefix = '';
		$translated_prefix = '';
		foreach ( $prefixes as $candidate ) {
			if ( 0 === strpos( (string) $value, $candidate[0] ) && strlen( $candidate[0] ) > strlen( $prefix ) ) {
				$prefix = $candidate[0];
				$translated_prefix = $candidate[1];
			}
		}
		if ( '' === $prefix ) return $value; // A custom variation title is handled by its own field.
		$suffix = substr( (string) $value, strlen( $prefix ) );
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
					$map += $this->local_variation_values( $parent_id, $taxonomy, (string) $slug );
				}
			}
			$map = array_filter( $map, function ( $v, $k ) { return $v !== $k && '' !== (string) $k; }, ARRAY_FILTER_USE_BOTH );
			if ( $map ) {
				uksort( $map, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } ); // longest first
				$replaced = preg_replace_callback( '/(^|[\s,])(' . implode( '|', array_map( function ( $k ) { return preg_quote( $k, '/' ); }, array_keys( $map ) ) ) . ')(?=$|[\s,])/u', function ( $m ) use ( $map ) { return $m[1] . $map[ $m[2] ]; }, $suffix );
				if ( null !== $replaced ) $suffix = $replaced;
			}
		}
		return $translated_prefix . $suffix;
	}

	/** A custom variation option uses the parent's translated, ordered option list. */
	private function local_variation_values( $parent_id, $name, $selected ) {
		$attributes = get_post_meta( $parent_id, '_product_attributes', true );
		if ( ! is_array( $attributes ) ) return array();
		$decoded = rawurldecode( $selected );
		foreach ( $attributes as $attribute_key => $attribute ) {
			if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || sanitize_title( (string) $attribute_key ) !== $name ) continue;
			$field_name = sanitize_title_with_dashes( (string) ( $attribute['name'] ?? '' ) );
			$options = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $attribute['value'] ?? '' ) ) ), 'strlen' ) );
			$source  = implode( ' | ', $options );
			if ( '' === $source ) return array();
			$values = array_map( 'trim', explode( '|', $this->t( 'product', $parent_id, self::attribute_field( $field_name, 'values' ), $source ) ) );
			if ( count( $values ) !== count( $options ) || in_array( '', $values, true ) ) return array();
			foreach ( $options as $index => $option ) {
				if ( $decoded === $option || $selected === sanitize_title( $option ) ) return array( $option => $values[ $index ], $decoded => $values[ $index ] );
			}
			return array();
		}
		return array();
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
		return $attribute_id ? $this->t( 'attribute', $attribute_id, 'label', $label ) : $this->known_label( $label );
	}

	private function known_label( $label ) {
		if ( ! $this->active() || ! is_string( $label ) || '' === $label ) return $label;
		$out = LT_Translation_Store::by_text( LT_Language_Router::instance()->current_language(), $label );
		return null !== $out ? $out : $label;
	}
	public function shipping_rate_label( $label, $rate ) {
		return $this->known_label( $label );
	}
	public function tax_rate_label( $label, $key ) {
		// Orders keep the original name (admin, invoices); only what the visitor reads is translated.
		if ( ! $this->active() || ! is_string( $label ) ) return $label;
		// The name is stored in the order while WooCommerce builds its tax lines (checkout, recalculation): kept original there.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) if ( in_array( $frame['function'] ?? '', array( 'create_order_tax_lines', 'update_taxes' ), true ) ) return $label; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return LT_Compat_Texts::lookup( 'woocommerce', $label );
	}

	/** Rates are cached in the customer session per package: the language is part of the package, so each language gets its own labels. */
	public function shipping_packages( $packages ) {
		if ( ! is_array( $packages ) ) return $packages;
		$language = LT_Language_Router::instance()->current_language();
		foreach ( $packages as $index => $package ) if ( is_array( $package ) ) $packages[ $index ]['lt_language'] = $language;
		return $packages;
	}

	/** Local (custom) attributes of "Additional information": name and values are fields of the product. */
	public function local_attributes( $list, $product ) {
		if ( ! $this->active() || ! is_array( $list ) || ! is_object( $product ) || ! is_callable( array( $product, 'get_attributes' ) ) ) return $list;
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || $attribute->is_taxonomy() ) continue;
			$slug = sanitize_title_with_dashes( $attribute->get_name() );
			$key  = 'attribute_' . $slug;
			if ( ! isset( $list[ $key ] ) || ! is_array( $list[ $key ] ) ) continue;
			$name  = $this->t( 'product', $product->get_id(), self::attribute_field( $slug, 'name' ), $attribute->get_name() );
			if ( $name !== $attribute->get_name() ) $list[ $key ]['label'] = $name;
			$options = array_map( 'strval', (array) $attribute->get_options() );
			$source  = implode( ' | ', $options );
			$values  = $this->t( 'product', $product->get_id(), self::attribute_field( $slug, 'values' ), $source );
			if ( $values === $source ) continue;
			$values = array_map( 'trim', explode( '|', $values ) );
			if ( count( $values ) !== count( $options ) || in_array( '', $values, true ) ) continue; // keep the original when the list does not match
			foreach ( $values as &$value ) $value = make_clickable( esc_html( $value ) );
			unset( $value );
			$list[ $key ]['value'] = wpautop( wptexturize( implode( ', ', $values ) ) );
		}
		return $list;
	}
	public static function attribute_field( $slug, $part ) { return 'attribute_' . $slug . '_' . $part; }

	public function variation_option( $value ) { return $this->active() ? $this->t( 'string', 0, 'variation_' . md5( $value ), $value ) : $value; }
}

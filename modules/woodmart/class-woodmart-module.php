<?php
defined( 'ABSPATH' ) || exit;

class LT_WoodMart_Module {
	public static function boot() {
		LT_WoodMart_Compat::instance();
		LT_WoodMart_Header::boot();
		LT_WoodMart_Search::boot();
		add_filter( 'lt_post_fields', array( __CLASS__, 'fields' ), 20, 2 );
		add_filter( 'lt_term_fields', array( __CLASS__, 'term_fields' ), 20, 2 );
		add_filter( 'lt_admin_text_tree', array( __CLASS__, 'option_presets' ) );
		add_filter( 'lt_theme_text_areas', array( __CLASS__, 'text_areas' ) );
		add_filter( 'lt_theme_text_area', array( __CLASS__, 'text_area' ), 10, 3 );
		add_filter( 'widget_display_callback', array( __CLASS__, 'widget_instance' ), 20, 2 );
		if ( is_admin() ) add_action( 'admin_init', array( 'LT_WoodMart_Catalog', 'prepare' ), 20 );
		// WoodMart Core uses the theme's "woodmart" text domain, already registered with the active theme.
	}
	/** WoodMart-specific sections are visible only when this theme module is active. */
	public static function text_areas( $areas ) {
		$sorted = array();
		foreach ( $areas as $key => $label ) {
			$sorted[ $key ] = $label;
			if ( 'product' === $key ) {
				$sorted['reviews'] = __( 'Product reviews', 'lightweight-translation' );
				$sorted['wishlist'] = __( 'Wishlist & compare', 'lightweight-translation' );
			}
		}
		return $sorted;
	}

	/**
	 * WoodMart puts unrelated frontend labels in inc/enqueue.php. The path alone cannot
	 * classify them; the exact wording below comes from its localized script keys.
	 * Older collected rows without a file path use the same wording without a rescan.
	 */
	public static function text_area( $area, $entry, $text ) {
		$path = strtolower( (string) ( $entry['p'] ?? '' ) );
		if ( preg_match( '#wishlist|compare#', $path ) ) return 'wishlist';
		if ( preg_match( '#review|comment.images|comment-images#', $path ) ) return 'reviews';
		if ( preg_match( '#/(?:modules/search|shortcodes/ajax-search|header-elements/search|header-elements/mobile-search)#', $path ) ) return 'search';
		if ( '' !== $path && ! preg_match( '#(?:^|/)enqueue\.php$#', $path ) ) return $area;
		static $locations = null;
		if ( null === $locations ) {
			$locations = array();
			$groups = array(
				'header' => array( 'Back to %s', 'Back to menu', 'Back to categories' ),
				'search' => array( 'View all results', 'Search history', 'Clear' ),
				'gallery' => array( 'Previous slide', 'Next slide', 'This is the first slide', 'This is the last slide', 'Go to slide {{index}}', '{{index}} / {{slidesLength}}', 'Download image', 'Share on Facebook', 'Pin it', 'Share on X' ),
				'reviews' => array( 'Image is required.', 'Added %s image(s)', 'Some files are too large. Allowed file size is %s.', 'You can upload up to %s images to your review.', 'You are allowed to upload images only in %s formats.' ),
				'cart' => array( 'Processing', 'Product was successfully added to your cart.', 'Continue shopping', 'View Cart', 'Checkout' ),
				'catalog' => array( 'Add to cart' ),
				'layout' => array( 'Custom style', 'On this page:' ),
			);
			foreach ( $groups as $group => $wordings ) foreach ( $wordings as $wording ) $locations[ $wording ] = $group;
		}
		return $locations[ $text ] ?? $area;
	}
	/** Texts of WoodMart widgets (banner, author area, user panel…), printed by the theme without the widget filters. */
	const WIDGET_TEXTS = array( 'title', 'subtitle', 'content', 'btn_text', 'author_name', 'link_text' );
	/** Widgets that print their title without the widget_title filter (the others are translated there, once). */
	const RAW_TITLE_WIDGETS = array( 'woodmart-banner', 'woodmart-author-information', 'woodmart-instagram', 'woodmart-user-panel' );
	public static function widget_instance( $instance, $widget ) {
		if ( ! is_array( $instance ) || ! is_object( $widget ) || 0 !== strpos( (string) ( $widget->id_base ?? '' ), 'woodmart-' ) || ( is_admin() && ! wp_doing_ajax() ) ) return $instance;
		foreach ( self::WIDGET_TEXTS as $key ) if ( ( 'title' !== $key || in_array( $widget->id_base, self::RAW_TITLE_WIDGETS, true ) ) && isset( $instance[ $key ] ) && is_string( $instance[ $key ] ) && '' !== trim( $instance[ $key ] ) ) $instance[ $key ] = LT_Frontend_Filters::instance()->widget_text( $instance[ $key ] );
		return $instance;
	}
	/** Theme option presets keep their own copy of the option texts under their id: the same texts are translated there. */
	public static function option_presets( $tree ) {
		if ( isset( $tree['xts-woodmart-options'] ) && is_array( $tree['xts-woodmart-options'] ) && ! isset( $tree['xts-woodmart-options']['*'] ) ) {
			$texts = $tree['xts-woodmart-options'];
			$tree['xts-woodmart-options']['*'] = $texts;
		}
		return $tree;
	}
	public static function fields( $fields, $post ) {
		if ( 'wd_product_tabs' === $post->post_type ) {
			$fields['woodmart_product_tab_title'] = (string) get_post_meta( $post->ID, 'product_tab_title', true );
			return $fields;
		}
		if ( 'woodmart_size_guide' === $post->post_type ) {
			$table = get_post_meta( $post->ID, 'woodmart_sguide', true );
			if ( is_array( $table ) ) foreach ( $table as $r => $row ) if ( is_array( $row ) ) foreach ( $row as $c => $cell ) if ( is_string( $cell ) && preg_match( '/\p{L}/u', $cell ) ) $fields[ LT_WoodMart_Compat::cell_field( $r, $c ) ] = $cell;
			return $fields;
		}
		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) return $fields;
		if ( 'product_variation' === $post->post_type ) {
			$fields['woodmart_variation_title'] = (string) get_post_meta( $post->ID, 'variation_title', true );
			$fields['woodmart_variation_description'] = (string) get_post_meta( $post->ID, '_variation_description', true );
		}
		for ( $tab = 1; $tab <= 5; $tab++ ) foreach ( array( 'title', 'content' ) as $part ) {
			$suffix = 1 === $tab ? '' : '_' . $tab;
			$value = get_post_meta( $post->ID, '_woodmart_product_custom_tab_' . $part . $suffix, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) $fields[ 'woodmart_tab_' . $part . '_' . $tab ] = $value;
		}
		return $fields;
	}
	public static function term_fields( $fields, $term ) {
		if ( 'product_cat' === $term->taxonomy ) $fields['woodmart_category_extra_description_text'] = (string) get_term_meta( $term->term_id, 'category_extra_description_text', true );
		return $fields;
	}
}

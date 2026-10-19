<?php
defined( 'ABSPATH' ) || exit;

/** WoodMart's classic and AJAX searches both run WP_Query; category suggestions use WP_Term_Query. */
class LT_WoodMart_Search {
	public static function boot() {
		add_filter( 'lt_search_taxonomies', array( __CLASS__, 'taxonomies' ), 10, 2 );
		add_filter( 'get_terms_args', array( __CLASS__, 'category_args' ), 20, 2 );
		add_filter( 'terms_clauses', array( __CLASS__, 'category_clauses' ), 20, 3 );
	}
	public static function taxonomies( $taxonomies, $query ) {
		$types = (array) $query->get( 'post_type' );
		if ( $types && ! array_intersect( $types, array( 'product', 'any' ) ) ) return $taxonomies;
		// Attribute archives may be private while their values are visible on products.
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $name => $taxonomy ) {
			if ( ( $taxonomy->public || 0 === strpos( $name, 'pa_' ) ) && ! in_array( $name, array( 'product_visibility', 'product_shipping_class' ), true ) ) $taxonomies[] = $name;
		}
		return array_unique( $taxonomies );
	}
	public static function category_args( $args, $taxonomies ) {
		if ( ! wp_doing_ajax() || 'woodmart_ajax_search' !== ( $_REQUEST['action'] ?? '' ) || array( 'product_cat' ) !== array_values( (array) $taxonomies ) || empty( $args['search'] ) ) return $args;
		$args['lt_woodmart_search'] = $args['search'];
		$args['search'] = ''; // Add one combined condition below, leaving parent/include/hide_empty restrictions intact.
		return $args;
	}
	public static function category_clauses( $clauses, $taxonomies, $args ) {
		if ( empty( $args['lt_woodmart_search'] ) || ! wp_doing_ajax() || 'woodmart_ajax_search' !== ( $_REQUEST['action'] ?? '' ) ) return $clauses;
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $args['lt_woodmart_search'] ) . '%';
		$clauses['where'] .= ' AND (' . LT_Multilingual_Search::term_match_sql( $like, 't' ) . ')';
		return $clauses;
	}
}

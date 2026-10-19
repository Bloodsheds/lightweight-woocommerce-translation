<?php
defined( 'ABSPATH' ) || exit;

/** Match stored languages without changing the router or duplicating WordPress objects. */
class LT_Multilingual_Search {
	/** WP_Query and WP_Term_Query cache SQL results, but translation writes do not touch WordPress objects. */
	public static function invalidate() {
		static $queued = false;
		if ( $queued ) return;
		$queued = true;
		self::invalidate_cache();
		add_action( 'shutdown', array( __CLASS__, 'invalidate_cache' ) ); // one final bump after a bulk import
	}
	public static function invalidate_cache() {
		wp_cache_set( 'last_changed', microtime(), 'posts' );
		wp_cache_set( 'last_changed', microtime(), 'terms' );
	}
	public static function filter( $search, $query ) {
		if ( ! is_object( $query ) || '' === trim( (string) $search ) || '' === trim( (string) $query->get( 's' ) ) ) return $search;
		// Never widen editor, REST, or unrelated administrative AJAX queries.
		if ( is_admin() && ! ( wp_doing_ajax() && 'woodmart_ajax_search' === ( $_REQUEST['action'] ?? '' ) ) ) return $search;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $search;
		$terms = array_values( array_filter( (array) $query->get( 'search_terms' ), 'strlen' ) ); // WP has already parsed quotes, stopwords and sentence mode.
		if ( ! $terms ) $terms = array( (string) $query->get( 's' ) );
		$columns = array_intersect( (array) $query->get( 'search_columns' ), array( 'post_title', 'post_excerpt', 'post_content' ) );
		if ( ! $columns ) $columns = array( 'post_title', 'post_excerpt', 'post_content' );
		$columns = array_intersect( (array) apply_filters( 'post_search_columns', $columns, $query->get( 's' ), $query ), array( 'post_title', 'post_excerpt', 'post_content' ) );
		if ( ! $columns ) return $search;
		$taxonomies = (array) apply_filters( 'lt_search_taxonomies', array(), $query );
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) $query->get( 'post_type' ) ) ) );
		if ( ! $types || in_array( 'any', $types, true ) ) $types = array();
		elseif ( in_array( 'product', $types, true ) ) $types[] = 'product_variation'; // translated variations match their parent product
		$positive = $negative = array();
		$prefix = (string) apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		foreach ( $terms as $term ) {
			$exclude = '' !== $prefix && 0 === strpos( $term, $prefix );
			if ( $exclude ) $term = substr( $term, strlen( $prefix ) );
			if ( '' === $term ) continue;
			$match = self::match_sql( $term, $columns, $taxonomies, (bool) $query->get( 'exact' ), array_unique( $types ) );
			if ( $exclude ) $negative[] = 'NOT (' . $match . ')';
			else $positive[] = '(' . $match . ')';
		}
		if ( ! $positive && ! $negative ) return $search;
		global $wpdb;
		$original = preg_replace( '/^\s*AND\s+/i', '', $search, 1 );
		if ( $original === $search ) return $search; // Unknown search provider: do not rewrite arbitrary SQL.
		// Keep WoodMart's SKU/synonym branch, but exclusions apply to every language and branch.
		$expanded = $positive ? implode( ' AND ', $positive ) : '1=1';
		$out = ' AND ( (' . $original . ') OR (' . $expanded . ') )';
		if ( $negative ) $out .= ' AND (' . implode( ' AND ', $negative ) . ')';
		if ( ! is_user_logged_in() ) $out .= " AND {$wpdb->posts}.post_password = ''";
		return $out;
	}

	/** Uncorrelated IN subqueries can be materialized once by MySQL; no per-result PHP lookups or ID caps. */
	private static function match_sql( $term, $columns, $taxonomies, $exact, $types = array() ) {
		global $wpdb;
		$like = ( $exact ? '' : '%' ) . $wpdb->esc_like( $term ) . ( $exact ? '' : '%' );
		$parts = $fields = array();
		$map = array( 'post_title' => array( 'title', 'woodmart_variation_title' ), 'post_content' => array( 'content', 'woodmart_variation_description' ), 'post_excerpt' => array( 'excerpt', 'short_description' ) );
		foreach ( $columns as $column ) {
			$parts[] = $wpdb->prepare( "{$wpdb->posts}.$column LIKE %s", $like );
			$fields = array_merge( $fields, $map[ $column ] );
		}
		$table = LT_Translation_Store::table();
		$live = self::translation_where( 'lts', $fields, $like, $types );
		// One translation scan per token. Variations resolve to the parent inside this same subquery.
		$parts[] = "{$wpdb->posts}.ID IN (SELECT CASE WHEN ltp.post_type='product_variation' THEN ltp.post_parent ELSE ltp.ID END FROM {$wpdb->posts} ltp INNER JOIN $table lts ON lts.object_id=ltp.ID AND lts.object_type=ltp.post_type WHERE (ltp.post_type<>'product_variation' OR ltp.post_status='publish') AND (ltp.post_type<>'product_variation' OR lts.field_key<>'title') AND $live)";
		if ( $taxonomies ) {
			$taxonomies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $taxonomies ) ) ) );
			if ( $taxonomies ) {
				$tax_sql = $wpdb->prepare( implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) ), $taxonomies );
				$term_match = self::term_match_sql( $like );
				$parts[] = "{$wpdb->posts}.ID IN (SELECT ltr.object_id FROM {$wpdb->term_relationships} ltr INNER JOIN {$wpdb->term_taxonomy} ltt ON ltr.term_taxonomy_id=ltt.term_taxonomy_id INNER JOIN {$wpdb->terms} lttm ON lttm.term_id=ltt.term_id WHERE ltt.taxonomy IN ($tax_sql) AND ($term_match))";
			}
		}
		return implode( ' OR ', $parts );
	}

	public static function term_match_sql( $like, $alias = 'lttm' ) {
		global $wpdb;
		// $alias is an internal constant supplied by our adapters, never a request parameter.
		return $wpdb->prepare( "$alias.name LIKE %s OR $alias.slug LIKE %s", $like, $like ) . " OR $alias.term_id IN (SELECT lts.object_id FROM " . LT_Translation_Store::table() . " lts WHERE lts.object_type='term' AND " . self::translation_where( 'lts', array( 'term_name', 'term_description' ), $like ) . ')';
	}

	private static function translation_where( $alias, $fields, $like, $types = array() ) {
		global $wpdb;
		$languages = LT_Language_Router::instance()->languages();
		if ( ! $languages ) return '1=0';
		$lang_sql = $wpdb->prepare( implode( ',', array_fill( 0, count( $languages ), '%s' ) ), $languages );
		$field_sql = $wpdb->prepare( implode( ',', array_fill( 0, count( $fields ), '%s' ) ), $fields );
		$type_sql = $types ? $wpdb->prepare( implode( ',', array_fill( 0, count( $types ), '%s' ) ), $types ) : '';
		return "$alias.language_code IN ($lang_sql)" . ( $type_sql ? " AND $alias.object_type IN ($type_sql)" : '' ) . " AND $alias.field_key IN ($field_sql) AND $alias.status IN ('translated','reviewed','partial') AND " . $wpdb->prepare( "$alias.translated_text LIKE %s", $like );
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Inventory of everything translatable on the site, grouped by kind (pages, products, categories, tags,
 * menus, strings…), with the translation status of every field in every language.
 * Used by the organised CSV export, the CSV import and the "Translation status" page.
 */
class LT_Content_Catalog {
	const EXTRA_POST_TYPES = array( 'wp_block', 'product_variation', 'cms_block', 'woodmart_slide', 'woodmart_layout', 'woodmart_size_guide', 'wd_popup', 'wd_floating_block', 'wd_product_tabs', 'portfolio' );
	const SKIP_POST_TYPES  = array( 'attachment', 'nav_menu_item', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'custom_css' );
	const POST_STATUSES    = array( 'publish', 'future', 'private' );
	/** Public for WordPress, but only used to sort theme blocks in the dashboard: never shown to visitors. */
	const SKIP_TAXONOMIES  = array( 'post_format', 'cms_block_cat', 'woodmart_slider', 'wd_popup_cat', 'wd_floating_block_cat' );

	/** Taxonomies whose terms visitors can read (names, archive addresses). */
	public static function public_taxonomies() { return array_values( array_diff( get_taxonomies( array( 'public' => true ), 'names' ), self::SKIP_TAXONOMIES ) ); }

	/** @var array Stored translation rows per object type: [type][lang][id][field] => row. */
	private static $rows = array();
	private static $global_items;
	private static $item_states = array();
	private static $overview = array();

	/** Group key => label. Keys: post:{post_type}, tax:{taxonomy}, attribute, menu, string. */
	public static function groups() {
		$groups = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( ! in_array( $type->name, self::SKIP_POST_TYPES, true ) ) $groups[ 'post:' . $type->name ] = $type->labels->name;
		}
		foreach ( self::EXTRA_POST_TYPES as $name ) {
			$object = get_post_type_object( $name );
			if ( $object && ! isset( $groups[ 'post:' . $name ] ) ) $groups[ 'post:' . $name ] = 'product_variation' === $name ? __( 'Product variations', 'lightweight-translation' ) : ( 'wp_block' === $name ? __( 'Synced patterns (reusable blocks)', 'lightweight-translation' ) : $object->labels->name );
		}
		$taxonomies = self::public_taxonomies();
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) $taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
		foreach ( array_unique( $taxonomies ) as $name ) {
			$object = get_taxonomy( $name );
			if ( ! $object || 'post_format' === $name ) continue;
			$groups[ 'tax:' . $name ] = $object->labels->name . ( 0 === strpos( $name, 'pa_' ) ? ' ' . __( '(attribute values)', 'lightweight-translation' ) : '' );
		}
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) $groups['attribute'] = __( 'Product attributes (names)', 'lightweight-translation' );
		$groups['menu']   = __( 'Menu links', 'lightweight-translation' );
		$groups['string'] = __( 'Interface strings & e-mails', 'lightweight-translation' );
		if ( function_exists( 'WC' ) ) $groups['email'] = __( 'WooCommerce e-mails', 'lightweight-translation' );
		return $groups;
	}

	private static function split( $group ) { return array_pad( explode( ':', (string) $group, 2 ), 2, '' ); }

	/** IDs of every item of a group (cheap: no content loaded). */
	public static function item_ids( $group ) {
		global $wpdb;
		list( $kind, $name ) = self::split( $group );
		switch ( $kind ) {
			case 'post':
				return array_map( 'absint', get_posts( array( 'post_type' => $name, 'post_status' => self::POST_STATUSES, 'numberposts' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ), 'suppress_filters' => true ) ) );
			case 'tax':
				$ids = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false, 'fields' => 'ids' ) );
				return is_wp_error( $ids ) ? array() : array_map( 'absint', $ids );
			case 'attribute':
				return function_exists( 'wc_get_attribute_taxonomies' ) ? array_map( 'absint', wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_id' ) ) : array();
			case 'menu':
				$ids = array();
				foreach ( wp_get_nav_menus() as $menu ) foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) if ( '1' !== get_post_meta( $item->ID, '_lt_switcher', true ) && '' !== trim( (string) get_post_field( 'post_title', $item->ID, 'raw' ) ) ) $ids[] = absint( $item->ID ); // Items without a custom label follow the linked page/category.
				return $ids;
			case 'email':
				return class_exists( 'LT_Email_Catalog' ) && function_exists( 'WC' ) ? array_keys( LT_Email_Catalog::items() ) : array();
			case 'string':
				return array_map( 'absint', $wpdb->get_col( 'SELECT MIN(id) FROM ' . LT_Translation_Store::table() . ( function_exists( 'WC' ) ? " WHERE object_type='string'" : " WHERE object_type IN ('string','email')" ) . " AND object_id=0 GROUP BY object_type, field_key ORDER BY object_type, MIN(id)" ) );
		}
		return array();
	}

	/**
	 * One item with its translatable fields.
	 * @return array|null [type, id, title, edit, block_editor, fields => [field_key => source text]]
	 */
	public static function item( $group, $id ) {
		list( $kind, $name ) = self::split( $group );
		if ( 'post' === $kind ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || $post->post_type !== $name ) return null;
			$fields = self::post_fields( $post );
			$parent = 'product_variation' === $post->post_type ? $post->post_parent : $post->ID;
			$title  = 'product_variation' === $post->post_type ? get_the_title( $post->post_parent ) . ' → ' . $post->post_title : $post->post_title;
			return array( 'type' => $post->post_type, 'id' => $post->ID, 'title' => '' !== trim( $title ) ? $title : '#' . $post->ID, 'edit' => get_edit_post_link( $parent, 'raw' ), 'block_editor' => function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ), 'fields' => $fields );
		}
		if ( 'tax' === $kind ) {
			$term = get_term( $id, $name );
			if ( ! $term instanceof WP_Term ) return null;
			$fields = array( 'term_name' => $term->name, 'term_description' => $term->description, 'slug' => $term->slug );
			$fields = apply_filters( 'lt_term_fields', $fields, $term );
			return array( 'type' => 'term', 'id' => $term->term_id, 'title' => $term->name, 'edit' => get_edit_term_link( $term->term_id, $term->taxonomy ), 'block_editor' => false, 'fields' => self::non_empty( $fields ) );
		}
		if ( 'attribute' === $kind ) {
			$attribute = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $id ) : null;
			if ( ! $attribute ) return null;
			return array( 'type' => 'attribute', 'id' => absint( $id ), 'title' => $attribute->name, 'edit' => admin_url( 'edit.php?post_type=product&page=product_attributes&edit=' . absint( $id ) ), 'block_editor' => false, 'fields' => array( 'label' => $attribute->name ) );
		}
		if ( 'menu' === $kind ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) return null;
			$item  = wp_setup_nav_menu_item( $post );
			$menus = wp_get_object_terms( $post->ID, 'nav_menu', array( 'fields' => 'all' ) );
			$menu  = ( ! is_wp_error( $menus ) && $menus ) ? $menus[0] : null;
			return array( 'type' => 'nav_menu_item', 'id' => $post->ID, 'title' => ( $menu ? $menu->name . ' → ' : '' ) . $item->title, 'edit' => admin_url( 'nav-menus.php?action=edit&menu=' . ( $menu ? $menu->term_id : 0 ) ), 'block_editor' => false, 'fields' => self::non_empty( array( 'title' => (string) $item->title ) ) );
		}
		if ( 'email' === $kind ) return function_exists( 'WC' ) ? ( LT_Email_Catalog::items()[ (int) $id ] ?? null ) : null;
		if ( 'string' === $kind ) {
			if ( null === self::$global_items ) {
				global $wpdb;
				self::$global_items = array();
				foreach ( (array) $wpdb->get_results( 'SELECT MIN(id) AS id, object_type, field_key, MAX(source_text) AS source_text FROM ' . LT_Translation_Store::table() . " WHERE object_type IN ('string','email') AND object_id=0 GROUP BY object_type, field_key" ) as $row ) {
					self::$global_items[ (int) $row->id ] = array( 'type' => $row->object_type, 'id' => 0, 'title' => wp_html_excerpt( $row->source_text, 160, '…' ), 'edit' => '', 'block_editor' => false, 'fields' => array( $row->field_key => $row->source_text ) );
				}
			}
			return self::$global_items[ (int) $id ] ?? null;
		}
		return null;
	}

	/** Same field keys as the editors and the front-end filters use. */
	public static function post_fields( WP_Post $post ) {
		$fields = array( 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt );
		if ( ! LT_Language_Router::is_front_page_post( $post ) ) $fields['slug'] = $post->post_name;
		if ( function_exists( 'is_post_type_viewable' ) && ! is_post_type_viewable( $post->post_type ) ) $fields = array( 'content' => $post->post_content );
		return self::non_empty( apply_filters( 'lt_post_fields', $fields, $post ) );
	}

	private static function non_empty( $fields ) { return array_filter( $fields, function( $value ) { return is_string( $value ) && '' !== trim( $value ); } ); }

	/** Current original text of a field, read from WordPress (null when unknown, e.g. collected strings). */
	public static function current_source( $type, $id, $field ) {
		$type = sanitize_key( $type );
		if ( 'email' === $type ) return function_exists( 'WC' ) ? LT_Email_Catalog::source( $field ) : null;
		if ( 'string' === $type ) return null;
		if ( 'term' === $type ) {
			$term = get_term( absint( $id ) );
			if ( ! $term instanceof WP_Term ) return null;
			$map = array( 'term_name' => $term->name, 'term_description' => $term->description, 'slug' => $term->slug );
			if ( isset( $map[ $field ] ) ) return $map[ $field ];
			if ( 0 === strpos( $field, 'woodmart_category_extra' ) ) return (string) get_term_meta( $term->term_id, substr( $field, 9 ), true );
			return null;
		}
		if ( 'attribute' === $type ) { $attribute = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( absint( $id ) ) : null; return $attribute ? $attribute->name : null; }
		$post = get_post( absint( $id ) );
		if ( ! $post instanceof WP_Post || $post->post_type !== $type ) return null;
		if ( 'product_variation' === $type && 'title' === $field ) return null;
		if ( 'nav_menu_item' === $type ) return 'title' === $field ? (string) wp_setup_nav_menu_item( $post )->title : null;
		if ( 'slug' === $field ) return $post->post_name;
		$fields = self::post_fields( $post );
		if ( isset( $fields[ $field ] ) ) return $fields[ $field ];
		$raw = array( 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'short_description' => $post->post_excerpt );
		return $raw[ $field ] ?? null;
	}

	private static function rows_for( $type, $with_values = false ) {
		$key = $type . ( $with_values ? ':values' : ':status' );
		if ( ! isset( self::$rows[ $key ] ) ) {
			global $wpdb;
			$map = array();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT language_code, object_id, field_key, " . ( $with_values ? 'translated_text, ' : '' ) . "(TRIM(translated_text) <> '') AS has_translation, source_hash, status FROM " . LT_Translation_Store::table() . ' WHERE object_type=%s', $type ) );
			foreach ( (array) $rows as $row ) $map[ $row->language_code ][ (int) $row->object_id ][ $row->field_key ] = $row;
			self::$rows[ $key ] = $map;
		}
		return self::$rows[ $key ];
	}

	/** Stored rows of ONE item (used when a single line is refreshed: no need to read the whole type). */
	private static function rows_for_item( $type, $id ) {
		if ( isset( self::$rows[ $type . ':status' ] ) ) return self::$rows[ $type . ':status' ];
		$key = $type . ':item:' . (int) $id;
		if ( ! isset( self::$rows[ $key ] ) ) {
			global $wpdb;
			$map = array();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT language_code, object_id, field_key, (TRIM(translated_text) <> '') AS has_translation, source_hash, status FROM " . LT_Translation_Store::table() . ' WHERE object_type=%s AND object_id=%d', $type, (int) $id ) );
			foreach ( (array) $rows as $row ) $map[ $row->language_code ][ (int) $row->object_id ][ $row->field_key ] = $row;
			self::$rows[ $key ] = $map;
		}
		return self::$rows[ $key ];
	}

	/**
	 * @return array [status, translated text]. Status: translated | outdated (original changed since) | missing.
	 */
	public static function field_status( $type, $id, $field, $source, $language, $with_value = true, $single = false ) {
		$map = $single && ! $with_value ? self::rows_for_item( $type, $id ) : self::rows_for( $type, $with_value );
		$row = $map[ $language ][ (int) $id ][ $field ] ?? null;
		if ( 'string' === $type ) { $known = LT_Translation_Store::by_text( $language, $source ); if ( null !== $known ) return array( 'translated', $with_value ? $known : '' ); }
		if ( ! $row || ! in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) || ! $row->has_translation ) return array( 'missing', '' );
		$value = $with_value ? (string) $row->translated_text : '';
		if ( 'partial' === $row->status ) return array( 'missing', $value ); // some paragraphs still to translate
		return array( hash_equals( (string) $row->source_hash, LT_Translation_Store::hash( $source ) ) ? 'translated' : 'outdated', $value );
	}

	/** Slugs are optional: they never make an item "incomplete". */
	public static function counts_for_progress( $field ) { return 'slug' !== $field; }

	/** Per-item counts per language: [lang => [translated, outdated, missing, missing_fields[]]]. */
	public static function item_progress( $item, $languages, $single = false ) {
		$out = array();
		foreach ( $languages as $language ) {
			$out[ $language ] = array( 'translated' => 0, 'outdated' => 0, 'missing' => 0, 'fields' => array() );
			foreach ( $item['fields'] as $field => $source ) {
				if ( ! self::counts_for_progress( $field ) ) continue;
				list( $status ) = self::field_status( $item['type'], $item['id'], $field, $source, $language, false, $single );
				$out[ $language ][ $status ]++;
				if ( 'translated' !== $status ) $out[ $language ]['fields'][] = $field . ( 'outdated' === $status ? ' (outdated)' : '' );
			}
		}
		return $out;
	}

	/* ---------- Live administration counts ---------- */

	/** Fields of an item, without the extra data the lists need (edit links…). */
	private static function item_fields( $group, $id ) {
		list( $kind, $name ) = self::split( $group );
		if ( 'post' !== $kind ) return self::item( $group, $id );
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $name ) return null;
		return array( 'type' => $post->post_type, 'id' => $post->ID, 'fields' => self::post_fields( $post ) );
	}

	/** [lang => [translated, outdated, missing]] of one item, false when it cannot be read. */
	private static function item_counts( $group, $id, $languages, $single = false ) {
		$item = self::item_fields( $group, $id );
		if ( ! $item ) return false;
		$out = array();
		foreach ( self::item_progress( $item, $languages, $single ) as $language => $counts ) $out[ $language ] = array( $counts['translated'], $counts['outdated'], $counts['missing'] );
		return $out;
	}

	/** Counts of every item of a group, calculated only when an administrator opens it. */
	public static function item_states( $group, $languages ) {
		$key = $group . '|' . implode( ',', $languages );
		if ( isset( self::$item_states[ $key ] ) ) return self::$item_states[ $key ];
		$ids = self::item_ids( $group );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			if ( ( 0 === strpos( $group, 'post:' ) || 'menu' === $group ) && function_exists( '_prime_post_caches' ) ) _prime_post_caches( $chunk, true, true );
			elseif ( 0 === strpos( $group, 'tax:' ) && function_exists( '_prime_term_caches' ) ) _prime_term_caches( $chunk, true );
		}
		$items = array();
		foreach ( $ids as $id ) $items[ (int) $id ] = self::item_counts( $group, $id, $languages );
		return self::$item_states[ $key ] = $items;
	}

	/** Totals for every group, from live counts of each item. */
	public static function summary( $languages, $refresh = false, $groups = null ) {
		if ( $refresh ) { self::$item_states = array(); self::$overview = array(); }
		$groups = null === $groups ? null : array_values( array_unique( array_filter( (array) $groups, 'is_string' ) ) );
		$summary = array();
		$catalog_groups = self::groups();
		if ( null !== $groups ) $catalog_groups = array_intersect_key( $catalog_groups, array_fill_keys( $groups, true ) );
		foreach ( $catalog_groups as $group => $label ) {
			$data = array( 'label' => $label, 'items' => 0, 'languages' => array() );
			foreach ( $languages as $language ) $data['languages'][ $language ] = array( 'fields' => 0, 'translated' => 0, 'outdated' => 0, 'missing' => 0, 'complete_items' => 0 );
			if ( 'string' === $group ) {
				// Interface texts: two small SQL counts, nothing to cache.
				global $wpdb;
				$ids = self::item_ids( $group );
				$data['items'] = count( $ids );
				$done = array();
				foreach ( (array) $wpdb->get_results( 'SELECT language_code, COUNT(*) AS n FROM ' . LT_Translation_Store::table() . ( function_exists( 'WC' ) ? " WHERE object_type='string'" : " WHERE object_type IN ('string','email')" ) . " AND object_id=0 AND status IN ('translated','reviewed') AND TRIM(translated_text) <> '' GROUP BY language_code" ) as $row ) $done[ $row->language_code ] = (int) $row->n;
				foreach ( $languages as $language ) $data['languages'][ $language ] = array( 'fields' => count( $ids ), 'translated' => $done[ $language ] ?? 0, 'outdated' => 0, 'missing' => max( 0, count( $ids ) - ( $done[ $language ] ?? 0 ) ), 'complete_items' => $done[ $language ] ?? 0 );
				if ( $data['items'] ) $summary[ $group ] = $data;
				continue;
			}
			if ( 'email' === $group ) {
				$items = array();
				foreach ( self::item_ids( $group ) as $id ) $items[ $id ] = self::item_counts( $group, $id, $languages );
			} else {
				$items = self::item_states( $group, $languages );
			}
			$data['items'] = count( $items );
			$data['incomplete_items'] = 0;
			foreach ( $items as $counts ) {
				if ( false === $counts ) { $data['incomplete_items']++; continue; }
				$incomplete = false;
				foreach ( $languages as $language ) {
					list( $translated, $outdated, $missing ) = $counts[ $language ] ?? array( 0, 0, 0 );
					$data['languages'][ $language ]['fields']     += $translated + $outdated + $missing;
					$data['languages'][ $language ]['translated'] += $translated;
					$data['languages'][ $language ]['outdated']   += $outdated;
					$data['languages'][ $language ]['missing']    += $missing;
					if ( ! $outdated && ! $missing ) $data['languages'][ $language ]['complete_items']++;
					else $incomplete = true;
				}
				if ( $incomplete ) $data['incomplete_items']++;
			}
			if ( $data['items'] ) $summary[ $group ] = $data;
		}
		return $summary;
	}
	/** Small dashboard totals; calculated on demand during this admin request. */
	public static function overview_progress( $languages ) {
		$key = implode( ',', $languages );
		if ( isset( self::$overview[ $key ] ) ) return self::$overview[ $key ];
		$out = array();
		foreach ( $languages as $language ) $out[ $language ] = array( 'fields' => 0, 'translated' => 0, 'outdated' => 0 );
		foreach ( self::summary( $languages ) as $group ) foreach ( $languages as $language ) {
			$counts = $group['languages'][ $language ] ?? array();
			$out[ $language ]['fields'] += (int) ( $counts['fields'] ?? 0 );
			$out[ $language ]['translated'] += (int) ( $counts['translated'] ?? 0 );
			$out[ $language ]['outdated'] += (int) ( $counts['outdated'] ?? 0 );
		}
		return self::$overview[ $key ] = $out;
	}

	/** Everything is counted again (settings, languages, imports, full resets). */
	public static function flush_summary( $clear_overview = true ) {
		LT_Multilingual_Search::invalidate();
		self::$rows = array();
		self::$item_states = array();
		if ( $clear_overview ) self::$overview = array();
	}

	/** A write only invalidates data built earlier in the same request. No progress data is persisted. */
	public static function changed_object( $type, $id, $structure = false ) {
		self::$rows = array();
		self::$global_items = null;
		self::$item_states = array();
		self::$overview = array();
	}

	public static function strings_changed() { self::changed_object( 'string', 0 ); }
	public static function attributes_changed() { self::changed_object( 'attribute', 0 ); }
}

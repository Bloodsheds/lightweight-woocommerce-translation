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
	const STATUS_CACHE     = 'lt_status_summary';
	const OVERVIEW_CACHE   = 'lt_overview_progress';
	const REVISIONS        = 'lt_catalog_revisions'; // group => time of its last change ('s:group' = its list changed)

	/** @var array Stored translation rows per object type: [type][lang][id][field] => row. */
	private static $rows = array();

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
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) $taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
		foreach ( array_unique( $taxonomies ) as $name ) {
			$object = get_taxonomy( $name );
			if ( ! $object || 'post_format' === $name ) continue;
			$groups[ 'tax:' . $name ] = $object->labels->name . ( 0 === strpos( $name, 'pa_' ) ? ' ' . __( '(attribute values)', 'lightweight-translation' ) : '' );
		}
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) $groups['attribute'] = __( 'Product attributes (names)', 'lightweight-translation' );
		$groups['menu']   = __( 'Menu links', 'lightweight-translation' );
		$groups['string'] = __( 'Interface strings & e-mails', 'lightweight-translation' );
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
			case 'string':
				return array_map( 'absint', $wpdb->get_col( 'SELECT MIN(id) FROM ' . LT_Translation_Store::table() . " WHERE object_type IN ('string','email') AND object_id=0 GROUP BY object_type, field_key ORDER BY object_type, MIN(id)" ) );
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
		if ( 'string' === $kind ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT object_type, field_key, source_text FROM ' . LT_Translation_Store::table() . ' WHERE id=%d', $id ) );
			if ( ! $row ) return null;
			return array( 'type' => $row->object_type, 'id' => 0, 'title' => wp_html_excerpt( $row->source_text, 80, '…' ), 'edit' => '', 'block_editor' => false, 'fields' => array( $row->field_key => $row->source_text ) );
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
		if ( in_array( $type, array( 'string', 'email' ), true ) ) return null;
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
		if ( ! $row || ! in_array( $row->status, LT_Translation_Store::LIVE_STATUSES, true ) || ! $row->has_translation ) return array( 'missing', '' );
		$value = $with_value ? (string) $row->translated_text : '';
		if ( in_array( $type, array( 'string', 'email' ), true ) ) return array( 'translated', $value );
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

	/* ---------- Cached counts, kept up to date item by item ----------
	 * Counting a whole catalogue (every product, variation, page and category) is the slow part of the
	 * admin screens. It is done once per type, then only the item that changed is counted again. */

	/** Same signature = same cached counts (languages, full resets, plugin version). */
	private static function signature( $languages ) { return implode( ',', $languages ) . '|' . get_option( 'lt_catalog_revision', '' ) . '|' . LT_VERSION; }

	private static function items_key( $group ) { return self::STATUS_CACHE . '_items_' . md5( $group ); }

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

	/** Is this item (still) part of its group? Same rule as item_ids(). */
	private static function belongs( $group, $id ) {
		list( $kind, $name ) = self::split( $group );
		if ( 'post' === $kind ) { $post = get_post( $id ); return $post instanceof WP_Post && $post->post_type === $name && in_array( $post->post_status, self::POST_STATUSES, true ); }
		if ( 'tax' === $kind ) return get_term( $id, $name ) instanceof WP_Term;
		return true;
	}

	/** Counts of every item of a group: id => [lang => [translated, outdated, missing]] (false = unreadable item). */
	public static function item_states( $group, $languages ) {
		static $memo = array();
		$signature = self::signature( $languages );
		if ( isset( $memo[ $group ] ) && $memo[ $group ]['sig'] === $signature ) return $memo[ $group ]['items'];
		$stored = get_transient( self::items_key( $group ) );
		if ( ! is_array( $stored ) || ( $stored['sig'] ?? '' ) !== $signature || ! isset( $stored['items'] ) ) {
			if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$ids = self::item_ids( $group );
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				if ( ( 0 === strpos( $group, 'post:' ) || 'menu' === $group ) && function_exists( '_prime_post_caches' ) ) _prime_post_caches( $chunk, true, true );
				elseif ( 0 === strpos( $group, 'tax:' ) && function_exists( '_prime_term_caches' ) ) _prime_term_caches( $chunk, true );
			}
			$items = array();
			foreach ( $ids as $id ) $items[ (int) $id ] = self::item_counts( $group, $id, $languages );
			$stored = array( 'sig' => $signature, 'languages' => $languages, 'items' => $items );
			set_transient( self::items_key( $group ), $stored, WEEK_IN_SECONDS );
		}
		$memo[ $group ] = array( 'sig' => $signature, 'items' => $stored['items'] );
		return $stored['items'];
	}

	/** Totals for every group, from the cached counts of each item. */
	public static function summary( $languages, $refresh = false, $groups = null ) {
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
				foreach ( (array) $wpdb->get_results( 'SELECT language_code, COUNT(*) AS n FROM ' . LT_Translation_Store::table() . " WHERE object_type IN ('string','email') AND object_id=0 AND status IN ('translated','reviewed') AND TRIM(translated_text) <> '' GROUP BY language_code" ) as $row ) $done[ $row->language_code ] = (int) $row->n;
				foreach ( $languages as $language ) $data['languages'][ $language ] = array( 'fields' => count( $ids ), 'translated' => $done[ $language ] ?? 0, 'outdated' => 0, 'missing' => max( 0, count( $ids ) - ( $done[ $language ] ?? 0 ) ), 'complete_items' => $done[ $language ] ?? 0 );
				if ( $data['items'] ) $summary[ $group ] = $data;
				continue;
			}
			if ( $refresh ) delete_transient( self::items_key( $group ) );
			$items = self::item_states( $group, $languages );
			$data['items'] = count( $items );
			foreach ( $items as $counts ) {
				if ( false === $counts ) continue;
				foreach ( $languages as $language ) {
					list( $translated, $outdated, $missing ) = $counts[ $language ] ?? array( 0, 0, 0 );
					$data['languages'][ $language ]['fields']     += $translated + $outdated + $missing;
					$data['languages'][ $language ]['translated'] += $translated;
					$data['languages'][ $language ]['outdated']   += $outdated;
					$data['languages'][ $language ]['missing']    += $missing;
					if ( ! $outdated && ! $missing ) $data['languages'][ $language ]['complete_items']++;
				}
			}
			if ( $data['items'] ) $summary[ $group ] = $data;
		}
		return $summary;
	}
	/** Small dashboard totals; calculated on demand and adjusted cheaply after each translation save. */
	public static function overview_progress( $languages ) {
		$key = self::OVERVIEW_CACHE . '_' . md5( implode( ',', $languages ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return $cached;
		$out = array();
		foreach ( $languages as $language ) $out[ $language ] = array( 'fields' => 0, 'translated' => 0, 'outdated' => 0 );
		foreach ( self::summary( $languages ) as $group ) foreach ( $languages as $language ) {
			$counts = $group['languages'][ $language ] ?? array();
			$out[ $language ]['fields'] += (int) ( $counts['fields'] ?? 0 );
			$out[ $language ]['translated'] += (int) ( $counts['translated'] ?? 0 );
			$out[ $language ]['outdated'] += (int) ( $counts['outdated'] ?? 0 );
		}
		set_transient( $key, $out, HOUR_IN_SECONDS );
		update_option( self::OVERVIEW_CACHE . '_keys', array_unique( array_merge( (array) get_option( self::OVERVIEW_CACHE . '_keys', array() ), array( $key ) ) ), false );
		return $out;
	}

	/** Update the cached dashboard counters for one saved field without recounting the full catalogue. */
	public static function translation_changed( $old, $new ) {
		if ( 'slug' === ( $new['field_key'] ?? '' ) ) return;
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		$language = sanitize_key( $new['language_code'] ?? '' );
		if ( ! $languages || ! in_array( $language, $languages, true ) ) return;
		$key = self::OVERVIEW_CACHE . '_' . md5( implode( ',', $languages ) );
		$progress = get_transient( $key );
		if ( ! is_array( $progress ) || ! isset( $progress[ $language ] ) ) return;
		$old_state = self::progress_state( $old, (string) ( $new['source_hash'] ?? '' ) );
		$new_state = self::progress_state( $new, (string) ( $new['source_hash'] ?? '' ) );
		foreach ( array( 'translated', 'outdated' ) as $state ) $progress[ $language ][ $state ] = max( 0, (int) ( $progress[ $language ][ $state ] ?? 0 ) + ( $state === $new_state ? 1 : 0 ) - ( $state === $old_state ? 1 : 0 ) );
		set_transient( $key, $progress, HOUR_IN_SECONDS );
	}

	private static function progress_state( $row, $current_hash ) {
		if ( ! is_object( $row ) && ! is_array( $row ) ) return 'missing';
		$get = function( $key, $default = '' ) use ( $row ) { return is_array( $row ) ? ( $row[ $key ] ?? $default ) : ( $row->$key ?? $default ); };
		if ( ! in_array( $get( 'status' ), LT_Translation_Store::LIVE_STATUSES, true ) || '' === trim( (string) $get( 'translated_text' ) ) || 'partial' === $get( 'status' ) ) return 'missing';
		if ( in_array( $get( 'object_type' ), array( 'string', 'email' ), true ) || hash_equals( (string) $get( 'source_hash' ), $current_hash ) ) return 'translated';
		return 'outdated';
	}

	/** Everything is counted again (settings, languages, imports, full resets). */
	public static function flush_summary( $clear_overview = true ) {
		static $done = false;
		self::$rows = array();
		if ( ! $done ) {
			$done = true;
			update_option( 'lt_catalog_revision', microtime( true ), false );
			foreach ( (array) get_option( self::STATUS_CACHE . '_keys', array() ) as $key ) delete_transient( $key ); // caches of 0.42 and earlier
		}
		if ( $clear_overview ) self::clear_overview();
	}

	private static function clear_overview() { foreach ( (array) get_option( self::OVERVIEW_CACHE . '_keys', array() ) as $key ) delete_transient( $key ); }

	/* ---------- What changed during this request ---------- */

	/** @var array group => [ids => [id => true], structure => bool, all => bool, list => bool] */
	private static $queue = array();
	private static $known = null;

	/** Keeps the cached counts up to date. Only the content types listed by groups() are followed. */
	public static function watch() {
		add_action( 'post_updated', array( __CLASS__, 'post_updated' ), 10, 3 );
		add_action( 'save_post', array( __CLASS__, 'post_saved' ), 99, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) add_action( $hook, array( __CLASS__, 'term_changed' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'terms_set' ), 10, 4 );
		add_action( 'shutdown', array( __CLASS__, 'apply_changes' ) );
	}

	private static function known( $group ) {
		if ( 'string' === $group ) return true;
		if ( null === self::$known ) {
			if ( ! did_action( 'init' ) ) return true; // types are not registered yet: keep the change
			self::$known = self::groups();
		}
		return isset( self::$known[ $group ] );
	}

	private static function &entry( $group ) {
		if ( ! isset( self::$queue[ $group ] ) ) self::$queue[ $group ] = array( 'ids' => array(), 'structure' => false, 'all' => false, 'list' => false );
		return self::$queue[ $group ];
	}

	/**
	 * Notes a change. $id 0 = the whole group; $structure = the item list itself changed (title, parent,
	 * new or removed item), not only a translation or a text.
	 */
	public static function changed( $group, $id = 0, $structure = true ) {
		if ( ! self::known( $group ) ) return;
		self::$rows = array();
		$entry = &self::entry( $group );
		if ( $id ) $entry['ids'][ (int) $id ] = true;
		else $entry['all'] = true;
		if ( $structure ) $entry['structure'] = true;
	}

	/** Only the organised list must be built again (e.g. product category headings); counts stay valid. */
	public static function list_changed( $group ) {
		if ( ! self::known( $group ) ) return;
		$entry = &self::entry( $group );
		$entry['list'] = true;
	}

	/** Same, from a stored translation row (object type + id). */
	public static function changed_object( $type, $id, $structure = false ) {
		$type = sanitize_key( $type );
		if ( in_array( $type, array( 'string', 'email' ), true ) ) return self::changed( 'string', 0, $structure );
		if ( 'term' === $type ) { $term = get_term( absint( $id ) ); return $term instanceof WP_Term ? self::changed( 'tax:' . $term->taxonomy, $term->term_id, $structure ) : null; }
		if ( 'attribute' === $type ) return self::changed( 'attribute', $id, $structure );
		if ( 'nav_menu_item' === $type ) return self::changed( 'menu', $structure ? 0 : $id, $structure );
		return self::changed( 'post:' . $type, $id, $structure );
	}

	public static function strings_changed() { self::changed( 'string', 0, true ); }
	public static function attributes_changed() { self::changed( 'attribute', 0, true ); }

	private static function listed( $post ) { return $post instanceof WP_Post && in_array( $post->post_status, self::POST_STATUSES, true ); }

	/** Title, parent, order or status edited: the line moves, appears or disappears. */
	public static function post_updated( $post_id, $after = null, $before = null ) {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || ( ! self::listed( $after ) && ! self::listed( $before ) ) ) return;
		foreach ( array( 'post_title', 'post_parent', 'post_status', 'menu_order' ) as $field ) {
			if ( (string) $after->$field === (string) $before->$field ) continue;
			if ( 'nav_menu_item' === $after->post_type ) self::changed( 'menu', 0, true );
			else self::changed( 'post:' . $after->post_type, $post_id, true );
			return;
		}
	}

	/** New item, or its texts were edited (its translations may now be "to check"). */
	public static function post_saved( $post_id, $post = null, $update = true ) {
		if ( ! self::listed( $post ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( 'nav_menu_item' === $post->post_type ) self::changed( 'menu', 0, true );
		else self::changed( 'post:' . $post->post_type, $post_id, ! $update );
	}

	public static function post_deleted( $post_id, $post = null ) {
		if ( ! self::listed( $post ) ) return; // trashed items already left the lists
		if ( 'nav_menu_item' === $post->post_type ) self::changed( 'menu', 0, true );
		else self::changed( 'post:' . $post->post_type, $post_id, true );
	}

	public static function term_changed( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ! $taxonomy ) return;
		self::changed( 'tax:' . $taxonomy, $term_id, true );
		if ( 'product_cat' === $taxonomy ) self::list_changed( 'post:product' ); // products are listed under their category
	}

	/** Only product categories matter here (the headings of the product list); stock or visibility terms do not. */
	public static function terms_set( $object_id, $terms = array(), $tt_ids = array(), $taxonomy = '' ) {
		if ( 'product_cat' === $taxonomy ) self::list_changed( 'post:product' );
	}

	/** Revision of the list of one group (organised list of the Translations screen). */
	public static function list_revision( $group ) {
		$revisions = (array) get_option( self::REVISIONS, array() );
		$global    = (string) get_option( 'lt_catalog_revision', '' );
		// A page line also counts the blocks and texts shown inside it: any change can affect it.
		if ( 'post:page' === $group ) return md5( $global . '|' . wp_json_encode( $revisions ) );
		$parts = 'post:product' === $group ? array( 'post:product', 'post:product_variation' ) : array( $group );
		foreach ( $parts as $part ) $global .= '|' . ( $revisions[ 's:' . $part ] ?? 0 );
		return $global;
	}

	/** End of the request: the changed items are counted again, one by one. */
	public static function apply_changes() {
		if ( ! self::$queue ) return;
		$queue = self::$queue;
		self::$queue = array();
		self::$rows  = array();
		$revisions = (array) get_option( self::REVISIONS, array() );
		$now = (string) microtime( true );
		$structure = false;
		foreach ( $queue as $group => $change ) {
			$revisions[ $group ] = $now;
			if ( $change['structure'] || $change['list'] ) $revisions[ 's:' . $group ] = $now;
			if ( $change['structure'] ) $structure = true;
			if ( 'string' === $group || ( ! $change['ids'] && ! $change['all'] ) ) continue;
			self::refresh_items( $group, $change );
		}
		update_option( self::REVISIONS, $revisions, false );
		if ( $structure ) self::clear_overview(); // translations alone already adjust it (translation_changed)
	}

	private static function refresh_items( $group, $change ) {
		$key    = self::items_key( $group );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ! isset( $stored['items'], $stored['languages'] ) ) return; // counted the next time it is shown
		list( $kind ) = self::split( $group );
		$languages = (array) $stored['languages'];
		if ( ( $stored['sig'] ?? '' ) !== self::signature( $languages ) || $change['all'] || ( $change['structure'] && ! in_array( $kind, array( 'post', 'tax' ), true ) ) ) { delete_transient( $key ); return; }
		foreach ( array_keys( $change['ids'] ) as $id ) {
			if ( ! $change['structure'] && ! array_key_exists( $id, $stored['items'] ) ) continue;
			if ( $change['structure'] && ! self::belongs( $group, $id ) ) { unset( $stored['items'][ $id ] ); continue; }
			$stored['items'][ $id ] = self::item_counts( $group, $id, $languages, true );
		}
		set_transient( $key, $stored, WEEK_IN_SECONDS );
	}
}

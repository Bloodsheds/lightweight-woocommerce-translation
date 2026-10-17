<?php
defined( 'ABSPATH' ) || exit;

class LT_Frontend_Filters {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_filter( 'the_title', array( $this, 'title' ), 20, 2 );
		add_filter( 'the_content', array( $this, 'content' ), 1 );
		add_filter( 'get_the_excerpt', array( $this, 'product_excerpt' ), 1, 2 );
		add_filter( 'term_name', array( $this, 'term_name' ), 20, 4 );
		add_filter( 'term_description', array( $this, 'term_description' ), 20, 2 );
		add_filter( 'get_the_terms', array( $this, 'term_objects' ), 20, 3 );
		add_filter( 'get_terms', array( $this, 'term_objects' ), 20, 4 );
		// Single terms (category/tag archive title, breadcrumbs, menu links…) are loaded with get_term(), not get_terms().
		add_filter( 'get_term', array( $this, 'term_object' ), 20, 2 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'menu_items' ), 20 );
		add_filter( 'gettext', array( $this, 'gettext' ), 20, 3 );
		add_filter( 'posts_search', array( $this, 'search' ), 20, 2 );
		// Texts displayed by JavaScript (WooCommerce block checkout / cart, mini cart…): wp.i18n translations.
		// JavaScript texts: answered directly by wp.i18n (LT_Script_Texts), no translation file is rewritten.
		add_filter( 'the_posts', array( $this, 'prime_posts' ), 20 );                     // performance: 1 query per list
		add_filter( 'pre_render_block', array( $this, 'synced_pattern' ), 20, 2 );       // synced patterns (reusable blocks)
		add_filter( 'pre_render_block', array( $this, 'embedded_block' ), 21, 2 );       // theme blocks (HTML blocks, layouts, popups…)
		add_filter( 'wpml_object_id', array( $this, 'embedded_post' ), 20, 2 );         // themes announce the block they render this way
		// Widgets: titles, Text / Custom HTML widgets, block widgets (texts typed in Appearance › Widgets).
		add_filter( 'widget_title', array( $this, 'widget_text' ), 20 );
		add_filter( 'widget_text', array( $this, 'widget_text' ), 20 );
		add_filter( 'widget_custom_html_content', array( $this, 'widget_html' ), 20 );
		add_filter( 'widget_block_content', array( $this, 'widget_html' ), 20 );
		add_filter( 'ngettext', array( $this, 'ngettext' ), 20, 5 );
		add_filter( 'gettext_with_context', array( $this, 'gettext_with_context' ), 20, 4 );
		add_filter( 'ngettext_with_context', array( $this, 'ngettext_with_context' ), 20, 6 );
		foreach ( array( 'wpseo_title', 'wpseo_metadesc', 'rank_math/frontend/title', 'rank_math/frontend/description' ) as $hook ) add_filter( $hook, array( $this, 'seo_text' ), 20 );
	}
	protected function active() { return LT_Language_Router::instance()->is_secondary() && ( ! is_admin() || wp_doing_ajax() ); }
	protected function t( $type, $id, $field, $source ) { return LT_Translation_Store::translate( LT_Language_Router::instance()->current_language(), $type, absint( $id ), $field, $source ); }
	/**
	 * Content-like fields are always rebuilt from the current original structure. A translation may replace
	 * visible text and text-like block attributes, but never blocks, classes, styles, links or layout options.
	 */
	protected function text_only( $type, $id, $field, $source ) {
		$language = LT_Language_Router::instance()->current_language();
		$row      = LT_Translation_Store::get_translated( $language, $type, absint( $id ), $field );
		if ( ! $row ) return $source;
		return LT_Content_Merger::from_row( (string) $source, $row );
	}
	/**
	 * the_title receives the title already texturized (" - " → "&#8211;", quotes…), which never matches the hash
	 * of the original: translate the raw title, then texturize the translation the same way.
	 */
	public function title( $value, $id = 0 ) {
		if ( ! $this->active() ) return $value;
		$id   = absint( $id ?: get_the_ID() );
		$type = $id ? get_post_type( $id ) : '';
		if ( ! $type ) return $value;
		$raw = (string) get_post_field( 'post_title', $id, 'raw' );
		if ( '' === $raw || ( (string) $value !== $raw && (string) $value !== wptexturize( $raw ) ) ) return $this->t( $type, $id, 'title', $value );
		$translated = apply_filters( 'lt_title_translation', $this->t( $type, $id, 'title', $raw ), $raw, $type, $id );
		return $translated === $raw ? $value : ( (string) $value === $raw ? $translated : wptexturize( $translated ) );
	}
	/**
	 * Page/post content. Only acts when the filtered value IS the current post content: other calls to
	 * the_content (WoodMart HTML blocks, widgets, product tabs…) are left untouched.
	 */
	public function content( $value ) {
		if ( ! $this->active() ) return $value;
		return $this->localize_links( $this->translated_content( $value ) );
	}

	private function translated_content( $value ) {
		$id   = absint( get_the_ID() );
		$type = $id ? get_post_type( $id ) : '';
		if ( ! $type ) return $this->other_content( $value );
		$source = (string) get_post_field( 'post_content', $id, 'raw' );
		$is_post_content = trim( (string) $value ) === trim( $source )
			|| ( is_singular() && in_the_loop() && false !== strpos( $source, '<!--more' ) && false === strpos( $source, '<!--nextpage' ) ); // "Read more" tag alters the value.
		if ( ! $is_post_content ) return $this->other_content( $value );
		$language = LT_Language_Router::instance()->current_language();
		$row      = LT_Translation_Store::get_translated( $language, $type, $id, 'content' );
		if ( $row ) {
			// Always keep the current original structure, even when the translation is up to date.
			$merged = LT_Content_Merger::from_row( $source, $row );
			if ( $merged !== $source ) return $merged;
		}
		// Legacy v0.3 rows (one row per block attribute).
		$legacy = LT_Content_Merger::merge_rows( $source, $language, $type, $id );
		return $legacy !== $source ? $legacy : $value;
	}



	public function product_excerpt( $value, $post = null ) {
		$post = get_post( $post );
		if ( ! $this->active() || ! $post instanceof WP_Post || '' === (string) $post->post_excerpt ) return $value;
		return $this->text_only( $post->post_type, $post->ID, apply_filters( 'lt_excerpt_field', 'excerpt', $post->post_type ), $post->post_excerpt );
	}

	public function term_name( $value, $term_id = 0, $taxonomy = '', $context = 'display' ) { $term_id = is_object( $term_id ) && isset( $term_id->term_id ) ? $term_id->term_id : absint( $term_id ); return $this->active() && $term_id ? $this->t( 'term', $term_id, 'term_name', $value ) : $value; }
	public function term_description( $value, $term_id = 0 ) { return $this->active() && $term_id ? $this->text_only( 'term', $term_id, 'term_description', $value ) : $value; }
	private function translate_term( WP_Term $term ) {
		$copy = clone $term; // never touch the object cached by WordPress
		$copy->name        = $this->t( 'term', $copy->term_id, 'term_name', $copy->name );
		$copy->description = $this->text_only( 'term', $copy->term_id, 'term_description', $copy->description );
		return $copy;
	}
	public function term_object( $term, $taxonomy = '' ) { return $this->active() && $term instanceof WP_Term ? $this->translate_term( $term ) : $term; }
	/** Menu items without a custom label show the title of the linked page/product/category: use its translation. */
	public function menu_items( $items ) {
		if ( ! $this->active() || ! is_array( $items ) ) return $items;
		$language = LT_Language_Router::instance()->current_language();
		$by_type  = array();
		foreach ( $items as $item ) if ( is_object( $item ) && ! empty( $item->object_id ) ) $by_type[ 'taxonomy' === ( $item->type ?? '' ) ? 'term' : (string) $item->object ][] = $item->object_id;
		foreach ( $by_type as $type => $ids ) { if ( 'term' === $type ) LT_Translation_Store::prime( $language, $type, $ids ); else LT_Translation_Store::prime_fields( $language, $type, $ids, array( 'title', 'slug' ) ); } // one compact query per menu type
		foreach ( $items as $item ) {
			if ( is_object( $item ) && 'custom' === ( $item->type ?? '' ) && ! empty( $item->url ) ) $item->url = $this->localize_url( $item->url ); // custom links typed with the original address
			if ( ! is_object( $item ) || '' !== (string) get_post_field( 'post_title', $item->ID, 'raw' ) || empty( $item->object_id ) ) continue;
			if ( 'taxonomy' === $item->type ) $item->title = $this->t( 'term', $item->object_id, 'term_name', $item->title );
			elseif ( 'post_type' === $item->type ) $item->title = $this->t( $item->object, $item->object_id, 'title', $item->title );
		}
		return $items;
	}
	public function term_objects( $terms, $object_ids = null, $taxonomies = null, $args = null ) {
		if ( ! $this->active() || ! is_array( $terms ) ) return $terms;
		$ids = array();
		foreach ( $terms as $term ) if ( $term instanceof WP_Term ) $ids[] = $term->term_id;
		if ( count( $ids ) > 1 ) LT_Translation_Store::prime( LT_Language_Router::instance()->current_language(), 'term', $ids ); // one query for the whole list
		foreach ( $terms as $index => $term ) {
			if ( $term instanceof WP_Term ) $terms[ $index ] = $this->translate_term( $term );
		}
		return $terms;
	}


	public function seo_text( $value ) { return $this->active() ? $this->t( 'string', 0, 'seo_' . md5( (string) $value ), $value ) : $value; }
	/* ---------- Interface texts: notices, AJAX messages, buttons, e-mails… ---------- */

	/** Text domains whose texts can be translated (option "lt_string_domains", "*" = all). */
	public static function domains() {
		static $domains = null;
		if ( null === $domains ) $domains = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'lt_string_domains', implode( ', ', self::default_domains() ) ) ) ) );
		return $domains;
	}

	/** Translated by default: WordPress, WooCommerce (when active) and the active theme. */
	public static function default_domains() {
		$domains = array( 'default' );
		if ( class_exists( 'WooCommerce' ) ) $domains[] = 'woocommerce';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			foreach ( array_filter( array( $theme, $theme->parent() ) ) as $item ) { $domain = (string) $item->get( 'TextDomain' ); if ( '' !== $domain ) $domains[] = $domain; }
		}
		return array_values( array_unique( apply_filters( 'lt_default_string_domains', $domains ) ) );
	}
	public static function domain_allowed( $domain ) { $domains = self::domains(); return 'widgets' === $domain || in_array( '*', $domains, true ) || in_array( (string) $domain, $domains, true ); }

	/** Loads the translations of all posts of a list (shop page, blog, related products…) in one query per type. */
	public function prime_posts( $posts ) {
		if ( ! $this->active() || ! is_array( $posts ) || count( $posts ) < 2 ) return $posts;
		$by_type = array();
		foreach ( $posts as $post ) if ( $post instanceof WP_Post ) $by_type[ $post->post_type ][] = $post->ID;
		foreach ( $by_type as $type => $ids ) {
			$language = LT_Language_Router::instance()->current_language();
			if ( 'product' === $type ) LT_Translation_Store::prime_fields( $language, $type, $ids, array( 'title', 'slug', 'short_description' ) );
			else LT_Translation_Store::prime( $language, $type, $ids );
		}
		return $posts;
	}

	/* ---------- Synced patterns (reusable blocks) ---------- */

	/**
	 * A synced pattern is stored in its own post (wp_block) and rendered by WordPress outside the_content,
	 * so it is translated here: if the pattern has a translation, render that instead.
	 */
	public function synced_pattern( $pre, $block ) {
		static $depth = 0;
		if ( 'core/block' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['ref'] ) ) LT_Page_Texts::record_item( 'wp_block', $block['attrs']['ref'] ); // "Interface texts › By page": content of the page
		if ( null !== $pre || ! $this->active() || 'core/block' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['ref'] ) || $depth > 3 ) return $pre;
		$id   = absint( $block['attrs']['ref'] );
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status ) return $pre;
		$translated = $this->translated_post_content( $post );
		if ( null === $translated ) return $pre;
		$depth++;
		$html = do_blocks( $translated );
		$depth--;
		return $this->localize_links( $html );
	}

	/**
	 * the_content called with the content of ANOTHER post (theme HTML block in a footer or mega menu, popup,
	 * page builder template…): recognised by its exact original text, whatever its ID.
	 */
	private function other_content( $value ) {
		static $cache = array();
		$value = (string) $value;
		if ( '' === trim( $value ) ) return $value;
		$hash = LT_Translation_Store::hash( $value );
		$language = LT_Language_Router::instance()->current_language();
		if ( ! array_key_exists( $hash, $cache ) ) {
			if ( ! isset( LT_Translation_Store::content_hashes( $language )[ substr( $hash, 0, 16 ) ] ) ) { $cache[ $hash ] = null; return $value; } // not a translated content: no query
			global $wpdb;
			$cache[ $hash ] = $wpdb->get_var( $wpdb->prepare( 'SELECT translated_text FROM ' . LT_Translation_Store::table() . " WHERE language_code=%s AND field_key='content' AND source_hash=%s AND status IN ('translated','reviewed','partial') AND translated_text <> '' LIMIT 1", $language, $hash ) ); // phpcs:ignore WordPress.DB
		}
		return null !== $cache[ $hash ] ? LT_Content_Merger::merge( $value, (string) $cache[ $hash ] ) : $value;
	}

	/** Blocks of embedded posts about to be rendered: md5( original block ) => translated block. */
	private $embedded = array();

	/**
	 * Themes call apply_filters( 'wpml_object_id', $id, $type ) just before rendering an HTML block / layout /
	 * popup themselves (do_blocks, without the_content). We keep the same post, but remember its translated
	 * blocks so they replace the original ones while it is rendered.
	 */
	public function embedded_post( $id, $type = '' ) {
		if ( is_numeric( $id ) && $id && is_string( $type ) && post_type_exists( $type ) && ! in_array( $type, array( 'nav_menu_item', 'attachment', 'page', 'post', 'product', 'product_variation' ), true ) && (int) $id !== (int) get_queried_object_id() ) LT_Page_Texts::record_item( $type, $id ); // theme block shown on the page
		if ( ! $this->active() || ! is_numeric( $id ) || ! $id ) return $id;
		$post = get_post( (int) $id );
		if ( ! $post instanceof WP_Post || $type !== $post->post_type || in_array( $type, array( 'page', 'post', 'product', 'product_variation', 'attachment', 'nav_menu_item' ), true ) || (int) $id === (int) get_queried_object_id() ) return $id; // the page itself goes through the_content
		$this->embedded = array();
		$translated = $this->translated_post_content( $post );
		if ( null === $translated || ! function_exists( 'parse_blocks' ) ) return $id;
		$original = parse_blocks( $post->post_content );
		$blocks   = parse_blocks( $translated );
		if ( count( $original ) !== count( $blocks ) ) return $id;
		foreach ( $original as $i => $block ) $this->embedded[ md5( serialize_block( $block ) ) ][] = $blocks[ $i ];
		return $id;
	}

	public function embedded_block( $pre, $block ) {
		static $rendering = false;
		if ( null !== $pre || $rendering || ! $this->embedded || ! is_array( $block ) ) return $pre;
		$key = md5( serialize_block( $block ) );
		if ( ! isset( $this->embedded[ $key ] ) ) return $pre;
		$translated_block = array_shift( $this->embedded[ $key ] );
		if ( ! $this->embedded[ $key ] ) unset( $this->embedded[ $key ] );
		$rendering = true;
		$html      = render_block( $translated_block );
		$rendering = false;
		return $this->localize_links( $html );
	}

	/** Translated block content of a post, or null when there is no usable translation. */
	public function translated_post_content( WP_Post $post ) {
		$language = LT_Language_Router::instance()->current_language();
		$source   = (string) $post->post_content;
		$row      = LT_Translation_Store::get_translated( $language, $post->post_type, $post->ID, 'content' );
		if ( $row ) {
			$merged = LT_Content_Merger::from_row( $source, $row );
			if ( $merged !== $source ) return $merged;
		}
		return null;
	}

	/* ---------- JavaScript texts (wp.i18n) ---------- */

	/** Translation of one JavaScript text: [single, plural] or null. */
	private function js_translation( $language, $msgid, $plural = '' ) {
		$single = LT_Translation_Store::string_value( $language, 'string_' . md5( $msgid ) );
		if ( null === $single ) return null;
		$context = false !== strpos( $msgid, "\x04" ) ? substr( $msgid, 0, strpos( $msgid, "\x04" ) + 1 ) : '';
		$many    = '' !== $plural ? LT_Translation_Store::string_value( $language, 'string_' . md5( $context . $plural ) ) : null;
		return null !== $many ? array( $single, $many ) : array( $single );
	}

	/**
	 * A script's translation file (JSON) is being loaded: replace the texts we have translated.
	 * Same keys as in PHP: the original English text (with "context\x04" prefix when there is a context).
	 */
	public function script_translations( $json, $file = '', $handle = '', $domain = '' ) {
		if ( ! $this->active() || ! is_string( $json ) || ! self::domain_allowed( $domain ) ) return $json;
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['locale_data'] ) ) return $json;
		$language = LT_Language_Router::instance()->current_language();
		$plurals  = ( (array) get_option( 'lt_js_strings', array() ) )[ $domain ] ?? array();
		$changed  = false;
		foreach ( $data['locale_data'] as $set => $messages ) {
			foreach ( (array) $messages as $msgid => $values ) {
				if ( '' === $msgid ) continue;
				$translated = $this->js_translation( $language, $msgid, $plurals[ $msgid ] ?? '' );
				if ( null === $translated ) continue;
				$data['locale_data'][ $set ][ $msgid ] = count( (array) $values ) > 1 && 1 === count( $translated ) ? array_fill( 0, count( (array) $values ), $translated[0] ) : $translated;
				$changed = true;
			}
		}
		return $changed ? wp_json_encode( $data ) : $json;
	}

	/** The site language has no translation file for this script: build one from our translations. */
	public function missing_script_translations( $translations, $file = '', $handle = '', $domain = '' ) {
		if ( null !== $translations || ! $this->active() || ! self::domain_allowed( $domain ) || ( $file && is_readable( $file ) ) ) return $translations;
		$known = ( (array) get_option( 'lt_js_strings', array() ) )[ $domain ] ?? array();
		if ( ! $known ) return $translations;
		$language = LT_Language_Router::instance()->current_language();
		$messages = array( '' => array( 'domain' => 'messages', 'lang' => $language, 'plural-forms' => 'nplurals=2; plural=(n != 1);' ) );
		foreach ( $known as $msgid => $plural ) {
			$translated = $this->js_translation( $language, $msgid, $plural );
			if ( null !== $translated ) $messages[ $msgid ] = $translated;
		}
		return count( $messages ) > 1 ? wp_json_encode( array( 'domain' => 'messages', 'locale_data' => array( 'messages' => $messages ) ) ) : $translations;
	}

	/* ---------- Search ---------- */

	/**
	 * Search in the visitor's language: a search made on /fr/ also finds the posts / products whose FRENCH
	 * title, content, excerpt or short description match (WordPress, WooCommerce and WoodMart AJAX search
	 * all use WP_Query). Every word must match, like the normal WordPress search.
	 */
	public function search( $search, $query ) {
		if ( ! $this->active() || '' === trim( (string) $search ) || ! is_object( $query ) || '' === trim( (string) $query->get( 's' ) ) ) return $search;
		$ids = self::search_ids( LT_Language_Router::instance()->current_language(), (string) $query->get( 's' ) );
		if ( ! $ids ) return $search;
		global $wpdb;
		$in = implode( ',', array_map( 'absint', $ids ) );
		// $search is " AND ( …original conditions… ) …": keep it and add "OR the ID matched in the translation".
		$password = is_user_logged_in() ? '' : " AND {$wpdb->posts}.post_password = ''"; // same rule as WordPress for protected posts
		return preg_replace( '/^\s*AND\s*/i', ' AND ( ', $search, 1 ) . " OR ({$wpdb->posts}.ID IN ($in)$password) ) ";
	}

	/** IDs of posts whose translation contains every word of $terms (product variations → their product). */
	public static function search_ids( $language, $terms ) {
		global $wpdb;
		$words = array_values( array_filter( array_map( 'trim', preg_split( '/\s+/u', wp_unslash( $terms ) ) ), function( $w ) { return mb_strlen( $w ) > 1; } ) );
		if ( ! $words ) return array();
		$table  = LT_Translation_Store::table();
		$fields = "'title','content','excerpt','short_description'";
		$ids    = null;
		foreach ( array_slice( $words, 0, 6 ) as $word ) {
			$found = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM $table WHERE language_code=%s AND field_key IN ($fields) AND status IN ('translated','reviewed','partial') AND translated_text LIKE %s LIMIT 1000", $language, '%' . $wpdb->esc_like( $word ) . '%' ) ) ); // phpcs:ignore WordPress.DB
			$ids   = null === $ids ? $found : array_values( array_intersect( $ids, $found ) );
			if ( ! $ids ) return array();
		}
		return array_slice( array_unique( $ids ), 0, 500 );
	}

	/* ---------- Internal links ---------- */

	/**
	 * Links typed by hand in content, HTML blocks, widgets or custom menu links point to the original-language
	 * address. On a translated page, send them to the same page in the current language (translated slug).
	 */
	public function localize_links( $html ) {
		if ( ! $this->active() || ! is_string( $html ) || false === stripos( $html, 'href' ) ) return $html;
		return preg_replace_callback( '/(\bhref\s*=\s*)(["\'])([^"\']+)\2/i', function( $m ) { return $m[1] . $m[2] . esc_attr( $this->localize_url( html_entity_decode( $m[3] ) ) ) . $m[2]; }, $html );
	}

	public function localize_url( $url ) {
		static $cache = array();
		$router = LT_Language_Router::instance();
		$lang   = $router->current_language();
		if ( isset( $cache[ $lang . $url ] ) ) return $cache[ $lang . $url ];
		$home = home_url( '/' );
		$abs  = $url;
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) $abs = home_url( $url );                    // root-relative link
		$same_site = 0 === strpos( preg_replace( '#^https?:#', '', $abs ), preg_replace( '#^https?:#', '', untrailingslashit( $home ) ) );
		$parts     = wp_parse_url( $abs );
		$path      = LT_Language_Router::relative_path( $abs );
		$first     = explode( '/', $path )[0];
		$skip      = ! $same_site || in_array( $first, array_merge( $router->languages(), array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php', 'xmlrpc.php', 'feed' ) ), true ) || preg_match( '/\.[a-z0-9]{2,5}$/i', $path );
		if ( $skip ) return $cache[ $lang . $url ] = $url;
		$suffix = ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		$target = '';
		// A page, post or product: its permalink in the current language (translated slug).
		$post_id = url_to_postid( strtok( $abs, '?#' ) );
		if ( $post_id ) $target = get_permalink( $post_id );
		// A category / tag: its link in the current language.
		if ( ! $target && '' !== $path ) {
			$slug = basename( $path );
			foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term ) continue;
				$original = $router->with_language( $router->default_language(), function() use ( $term ) { return get_term_link( $term ); } );
				if ( ! is_wp_error( $original ) && trim( LT_Language_Router::relative_path( $original ), '/' ) === trim( $path, '/' ) ) { $link = get_term_link( $term ); if ( ! is_wp_error( $link ) ) $target = $link; break; }
			}
		}
		// Anything else (shop, archives, home): same path with the language prefix and translated URL words.
		if ( ! $target ) $target = trailingslashit( home_url( $lang . ( '' === $path ? '' : '/' . $router->translate_path_bases( $path, $lang ) ) ) );
		return $cache[ $lang . $url ] = $target . $suffix;
	}

	/* ---------- Widgets ---------- */

	/** Lookup key of a widget text (translated like interface texts, source "Widgets"). */
	public static function widget_key( $text ) { return 'widget:' . trim( (string) $text ); }

	/** Widget title or plain text widget: translated as a whole. */
	public function widget_text( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) || is_admin() ) return $text;
		if ( false !== strpos( $text, '<' ) ) return $this->widget_html( $text ); // Text widget written in visual mode
		return $this->string( $text, self::widget_key( $text ), 'widgets' );
	}

	/** HTML widgets / block widgets: every piece of visible text is translated separately, the HTML is kept. */
	public function widget_html( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || is_admin() ) return $html;
		return $this->localize_links( self::map_html_texts( $html, function( $text ) { return $this->string( $text, self::widget_key( $text ), 'widgets' ); } ) );
	}

	/** Applies $callback to each visible text node of $html (not inside <script>/<style>, not block comments). */
	public static function map_html_texts( $html, $callback ) {
		$parts = preg_split( '#(<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 ) continue; // script, style or comment: untouched
			$parts[ $index ] = preg_replace_callback( '/(^|>)([^<>]*)(?=<|$)/u', function( $m ) use ( $callback ) {
				$text = trim( $m[2] );
				if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) ) return $m[0];
				$translated = $callback( $text );
				return $m[1] . str_replace( $text, $translated, $m[2] );
			}, $part );
		}
		return implode( '', $parts );
	}

	/** Texts of every widget placed in a sidebar (used by "Find widget texts"). */
	public static function widget_texts() {
		$texts = array();
		foreach ( (array) wp_get_sidebars_widgets() as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) continue;
			foreach ( $widgets as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) continue;
				$instances = get_option( 'widget_' . $m[1] );
				$instance  = is_array( $instances ) ? ( $instances[ (int) $m[2] ] ?? null ) : null;
				if ( ! is_array( $instance ) ) continue;
				if ( ! empty( $instance['title'] ) && is_string( $instance['title'] ) ) $texts[] = trim( $instance['title'] );
				if ( 'text' === $m[1] && ! empty( $instance['text'] ) && false === strpos( $instance['text'], '<' ) ) $texts[] = trim( $instance['text'] );
				foreach ( array( 'content', 'text' ) as $field ) {
					if ( empty( $instance[ $field ] ) || ! is_string( $instance[ $field ] ) || false === strpos( $instance[ $field ], '<' ) ) continue;
					self::map_html_texts( $instance[ $field ], function( $text ) use ( &$texts ) { $texts[] = $text; return $text; } );
				}
			}
		}
		return array_values( array_unique( array_filter( $texts ) ) );
	}

	/**
	 * Translation of one interface text. Two ways to translate it:
	 * - "string_{md5(msgid)}": texts collected automatically while browsing (or exported);
	 * - "text_{md5(visible text)}": texts added by hand in Light translations › Interface texts,
	 *   using the exact wording visitors see in the original language.
	 */
	public static function lookup_string( $language, $translation, $key ) {
		// Called for every text of every page: a plain array lookup, no database query, nothing done for
		// texts that have no translation.
		if ( ! $language || '' === (string) $translation ) return $translation;
		$out = LT_Translation_Store::string_lookup( $language, 'string_' . md5( $key ), $translation );
		if ( null !== $out ) return $out;
		// Same wording translated somewhere else (another plugin, another context, or added by hand).
		$out = LT_Translation_Store::by_text( $language, $translation );
		return null !== $out ? $out : $translation;
	}


	private function string( $translation, $key, $domain ) {
		if ( '' === (string) $key ) return $translation;
		if ( LT_Page_Texts::tracking() ) LT_Page_Texts::record( 'string_' . md5( $key ), $translation, $domain, self::domain_allowed( $domain ) );
		if ( ! self::domain_allowed( $domain ) ) return $translation;
		if ( ! $this->active() ) return $translation;
		return self::lookup_string( LT_Language_Router::instance()->current_language(), $translation, $key );
	}
	public function gettext( $translation, $text, $domain ) { return $this->string( $translation, $text, $domain ); }
	public function ngettext( $translation, $single, $plural, $number, $domain ) { return $this->string( $translation, 1 === (int) $number ? $single : $plural, $domain ); }
	public function gettext_with_context( $translation, $text, $context, $domain ) { return $this->string( $translation, $context . "\x04" . $text, $domain ); }
	public function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) { return $this->string( $translation, $context . "\x04" . ( 1 === (int) $number ? $single : $plural ), $domain ); }
}

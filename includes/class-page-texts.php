<?php
defined( 'ABSPATH' ) || exit;

/**
 * "🌐 Translate" menu in the admin bar of the site, and the list of the interface texts used by a page.
 *
 * While an administrator browses the site, the interface texts displayed by the page (WooCommerce,
 * theme, widgets, site texts…) are noted in memory and kept for that user (last pages only).
 * "🌐 Translate › Texts of this page" then opens Interface texts filtered on exactly those texts:
 * no need to know which plugin or option a text comes from.
 * Costs nothing for visitors: nothing is recorded unless the user can manage the shop.
 */
class LT_Page_Texts {
	const MAX_TEXTS = 2500;
	const MAX_PAGES = 8;
	const MAP       = 'lt_page_texts'; // page type => [ url, time, keys => [ field => [ source, domain ] ] ]
	const MAX_KEYS  = 1500;

	private static $instance;
	private static $on     = null;
	private static $paused = false;
	private static $texts  = array();

	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'pause' ), PHP_INT_MIN ); // texts of the admin bar itself are not page texts
		add_action( 'wp_after_admin_bar_render', array( __CLASS__, 'resume' ) );
		add_action( 'admin_bar_menu', array( $this, 'menu' ), 85 );
		add_action( 'shutdown', array( __CLASS__, 'save' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), PHP_INT_MAX );
	}

	/* ---------- What the visitor really sees ---------- */

	private static $html  = null;
	private static $items = array();

	/** The final HTML of the page is kept (administrators only) to keep only the texts it really shows. */
	public static function start_buffer() {
		if ( self::tracking() && ! is_feed() && ! wp_doing_ajax() ) ob_start( array( __CLASS__, 'keep_html' ) );
	}
	public static function keep_html( $html ) { if ( is_string( $html ) && '' !== $html ) self::$html = ( self::$html ?? '' ) . $html; return $html; }

	/** Content shown on the page: the page itself, theme blocks, synced patterns… (type:id) */
	public static function record_item( $type, $id ) {
		if ( self::tracking() && $type && (int) $id ) self::$items[ $type . ':' . (int) $id ] = true;
	}

	/** Text a visitor can read: page text, or a placeholder / title / button label. No scripts, styles, comments. */
	private static function visible_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>|<!--.*?-->#is', ' ', $html );
		preg_match_all( '#\b(?:placeholder|title|alt|aria-label|value|data-label|data-title)\s*=\s*"([^"]*)"#i', $html, $attributes );
		$text = wp_strip_all_tags( $html ) . ' ' . implode( ' ', $attributes[1] );
		return mb_strtolower( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Is this original text found in what the visitor sees? (the longest part around %s placeholders is enough) */
	private static function is_shown( $source, $haystack ) {
		$needle = preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$parts  = preg_split( '/%(?:\d+\$)?[sdfu]|\{[a-z_]+\}/i', $needle );
		usort( $parts, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$needle = mb_strtolower( trim( (string) $parts[0] ) );
		if ( mb_strlen( $needle ) < 2 ) return false;
		return false !== strpos( $haystack, $needle );
	}

	/* ---------- Recording ---------- */

	public static function tracking() {
		if ( self::$paused ) return false;
		if ( null === self::$on ) {
			if ( ! did_action( 'init' ) ) return false; // user not known yet
			self::$on = false; // no recursion if a check below translates a text
			self::$on = ! wp_doing_cron() && ( ! is_admin() || self::is_front_request() ) && is_user_logged_in() && current_user_can( lt_capability() );
		}
		return self::$on;
	}
	/** AJAX / REST call made by a page of the site (add to cart, form, checkout errors…), not by the admin. */
	private static function is_front_request() {
		if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return ! is_admin();
		$action = (string) ( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $action, 'lt_' ) || 'heartbeat' === $action ) return false;
		return false === strpos( (string) wp_get_raw_referer(), '/wp-admin/' );
	}

	public static function pause() { self::$paused = true; }
	public static function resume() { self::$paused = false; }

	/** @param bool $allowed false when the text domain is not enabled for translation (Interface texts › Settings). */
	public static function record( $field, $source, $domain, $allowed = true ) {
		if ( self::$paused || isset( self::$texts[ $field ] ) || count( self::$texts ) >= self::MAX_TEXTS || '' === trim( (string) $source ) ) return;
		self::$texts[ $field ] = array( (string) $source, (string) $domain, $allowed ? 1 : 0 );
	}

	public static function save() {
		if ( ! self::$on || ! self::$texts ) return;
		$texts = array();
		foreach ( self::$texts as $field => $text ) $texts[ $field ] = array( $text[0], $text[1] );
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { // notices & errors shown without reloading the page
			$part = LT_Page_Places::action_label( (string) ( $_REQUEST['wc-ajax'] ?? $_REQUEST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			foreach ( $texts as $field => $text ) $texts[ $field ] += array_filter( array( 'z' => 'messages', 'c' => $part ) );
			self::add_texts( 'messages', $texts );
			return;
		}
		// Only what is really displayed: texts prepared by plugins but never shown are left out.
		if ( null !== self::$html ) {
			$haystack = self::visible_text( self::$html );
			$texts    = array_filter( $texts, function( $text ) use ( $haystack ) { return self::is_shown( $text[0], $haystack ); } );
			// Blocks shown inside the page: only those really visible, and not in the header / footer / side
			// panels (those are the same on every page and are translated once, in "Blocks & layouts").
			$samples = array();
			foreach ( array_keys( self::$items ) as $ref ) {
				$post = get_post( (int) substr( $ref, strpos( $ref, ':' ) + 1 ) );
				$best = '';
				if ( $post ) foreach ( array_slice( LT_Segments::split( (string) $post->post_content )['segs'], 0, 15 ) as $seg ) if ( self::is_shown( $seg, $haystack ) && mb_strlen( wp_strip_all_tags( $seg ) ) > mb_strlen( wp_strip_all_tags( $best ) ) ) $best = $seg;
				if ( '' === $best ) { unset( self::$items[ $ref ] ); continue; }
				$samples[ '@' . $ref ] = $best;
			}
			// Where each text is on the page (zone, block, kind, position).
			$places = LT_Page_Places::locate( self::$html, array_map( function( $text ) { return $text[0]; }, $texts ) + $samples );
			foreach ( $samples as $key => $sample ) if ( in_array( $places[ $key ]['z'] ?? 'main', array( 'header', 'footer', 'panel' ), true ) ) unset( self::$items[ substr( $key, 1 ) ] );
			foreach ( $places as $field => $place ) if ( isset( $texts[ $field ] ) ) $texts[ $field ] += $place;
		}
		$object = get_queried_object();
		if ( function_exists( 'is_shop' ) && is_shop() ) $object = get_post( wc_get_page_id( 'shop' ) );
		if ( $object instanceof WP_Post ) self::$items = array( $object->post_type . ':' . $object->ID => true ) + self::$items;
		elseif ( $object instanceof WP_Term ) self::$items = array( 'term:' . $object->term_id => true ) + self::$items;
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! did_action( 'wp_head' ) || preg_match( '#\.(js|css|map|png|jpe?g|gif|svg|webp|ico|woff2?|txt|xml)(\?|$)#i', $uri ) ) return; // not a page (or a missing file answered by the 404 template)
		self::add_texts( self::page_type(), $texts, esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . $uri ), true, array_keys( self::$items ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/* ---------- Texts per kind of page (kept: "Interface texts › Pages") ---------- */

	/** Kind of page displayed: "home", "product", "checkout", "account:orders", "page:12"… */
	public static function page_type() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$endpoint = function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '';
			return 'account' . ( '' !== $endpoint ? ':' . $endpoint : '' );
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) return 'order-received';
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) return 'order-pay';
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) return 'cart';
		if ( is_front_page() ) return 'home';
		if ( function_exists( 'is_shop' ) && is_shop() ) return 'shop';
		if ( function_exists( 'is_product' ) && is_product() ) return 'product';
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) return 'product-cat';
		if ( is_search() ) return 'search';
		if ( is_404() ) return '404';
		if ( is_home() || is_category() || is_tag() || is_date() || is_author() ) return 'blog';
		if ( is_singular( 'post' ) ) return 'post';
		if ( is_page() ) return 'page:' . get_queried_object_id();
		if ( is_singular() ) return 'single:' . get_post_type();
		return is_archive() ? 'archive' : 'other';
	}

	/** Adds texts to a kind of page (written only when something new was found). */
	/**
	 * @param bool  $replace a full page view: its list of PHP texts replaces the previous one (texts no longer
	 *                       shown disappear); texts written by JavaScript ('j') and added by hand are kept.
	 * @param array $items   content shown on the page (type:id)
	 */
	public static function add_texts( $type, $texts, $url = '', $replace = false, $items = null ) {
		if ( '' === (string) $type || ( ! $texts && null === $items ) ) return;
		// The site check reads several pages at once: each request updates the shared list in turn,
		// from its latest version, so that no page overwrites what another one just added.
		$locked = self::lock();
		wp_cache_delete( self::MAP, 'options' );
		try { self::merge_texts( $type, $texts, $url, $replace, $items ); }
		finally { if ( $locked ) self::unlock(); }
	}

	/** Short database lock (an INSERT is atomic; a lock older than 15 s is considered abandoned). */
	private static function lock() {
		global $wpdb;
		for ( $i = 0; $i < 50; $i++ ) {
			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'lt_page_texts_lock', (string) time() ) ) ) return true; // phpcs:ignore WordPress.DB
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lt_page_texts_lock' ) ); // phpcs:ignore WordPress.DB
			if ( $since && $since < time() - 15 ) self::unlock();
			usleep( 100000 );
		}
		return false; // still written, as before, rather than losing the texts of this page
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'lt_page_texts_lock' ) ); // phpcs:ignore WordPress.DB
	}

	private static function merge_texts( $type, $texts, $url, $replace, $items ) {
		$map   = (array) get_option( self::MAP, array() );
		$entry = $map[ $type ] ?? array( 'keys' => array() );
		$old   = (array) $entry['keys'];
		$new   = array_diff_key( $texts, $old );
		$keys  = $replace ? array_filter( $old, function( $text ) { return 'j' === ( $text[2] ?? '' ) || 'manual' === ( $text[1] ?? '' ); } ) : $old;
		// Known texts take the latest place found (zone, block, position); a JavaScript / manual text stays one.
		foreach ( $texts as $field => $text ) {
			if ( isset( $old[ $field ] ) ) {
				if ( isset( $old[ $field ][2] ) && ! isset( $text[2] ) ) $text[2] = $old[ $field ][2];
				foreach ( array( 'z', 'c', 'k', 'o' ) as $info ) if ( ! isset( $text[ $info ] ) && isset( $old[ $field ][ $info ] ) && empty( $text['z'] ) ) $text[ $info ] = $old[ $field ][ $info ];
			}
			$keys[ $field ] = $text;
		}
		$items_changed = null !== $items && array_values( $items ) !== array_values( (array) ( $entry['items'] ?? array() ) );
		if ( serialize( $keys ) === serialize( $old ) && ! $items_changed && ( ! empty( $entry['url'] ) || ! $url ) ) return; // nothing new: no database write
		$entry['keys'] = array_slice( $keys, 0, self::MAX_KEYS, true );
		if ( null !== $items ) $entry['items'] = array_values( array_slice( $items, 0, 50 ) );
		if ( $url ) $entry['url'] = $url;
		$entry['time'] = time();
		$map[ $type ]  = $entry;
		update_option( self::MAP, $map, false );
		if ( $new ) self::register( array_filter( $new, function( $text ) { return 'site' === $text[1] || 'manual' === $text[1] || LT_Frontend_Filters::domain_allowed( $text[1] ); } ) );
	}

	/**
	 * Texts per kind of page, plus the texts found on most pages (header, footer, menus, mini-cart), shown once
	 * as "Every page" instead of in every page.
	 * @return array [ map, common field => true ]
	 */
	public static function groups() {
		$map   = (array) get_option( self::MAP, array() );
		$count = array();
		foreach ( $map as $entry ) foreach ( array_keys( (array) ( $entry['keys'] ?? array() ) ) as $field ) $count[ $field ] = ( $count[ $field ] ?? 0 ) + 1;
		$limit  = max( 3, (int) ceil( count( $map ) * 0.6 ) );
		$common = count( $map ) >= 3 ? array_filter( $count, function( $n ) use ( $limit ) { return $n >= $limit; } ) : array();
		return array( $map, array_fill_keys( array_keys( $common ), true ) );
	}

	/** Texts of one kind of page ("common" = every page): [ texts => [ field => [source, domain] ], url ]. */
	public static function type_data( $type ) {
		list( $map, $common ) = self::groups();
		if ( 'common' === $type ) {
			$texts = array();
			foreach ( $map as $entry ) foreach ( (array) $entry['keys'] as $field => $text ) if ( isset( $common[ $field ] ) ) $texts[ $field ] = $text;
			return array( 'texts' => $texts, 'url' => home_url( '/' ), 'title' => self::label( 'common' ) );
		}
		if ( ! isset( $map[ $type ] ) ) return null;
		return array( 'texts' => array_diff_key( (array) $map[ $type ]['keys'], $common ), 'url' => $map[ $type ]['url'] ?? '', 'title' => self::label( $type ), 'items' => (array) ( $map[ $type ]['items'] ?? array() ) );
	}

	/** Field => labels of the kinds of page showing it (for "where is this text?"). */
	public static function where_map() {
		list( $map, $common ) = self::groups();
		$where = array();
		foreach ( $map as $type => $entry ) foreach ( array_keys( (array) $entry['keys'] ) as $field ) if ( ! isset( $common[ $field ] ) ) $where[ $field ][] = $type;
		foreach ( array_keys( $common ) as $field ) $where[ $field ] = array( 'common' );
		return $where;
	}

	public static function label( $type ) {
		$labels = array(
			'common'         => __( 'Every page (header, footer, menus, mini-cart)', 'lightweight-translation' ),
			'home'           => __( 'Home page', 'lightweight-translation' ),
			'shop'           => __( 'Shop', 'lightweight-translation' ),
			'product'        => __( 'Product pages', 'lightweight-translation' ),
			'product-cat'    => __( 'Product categories & tags', 'lightweight-translation' ),
			'cart'           => __( 'Cart', 'lightweight-translation' ),
			'checkout'       => __( 'Checkout', 'lightweight-translation' ),
			'order-received' => __( 'Order received (thank you page)', 'lightweight-translation' ),
			'order-pay'      => __( 'Order payment', 'lightweight-translation' ),
			'account'        => __( 'My account', 'lightweight-translation' ),
			'search'         => __( 'Search results', 'lightweight-translation' ),
			'404'            => __( 'Page not found (404)', 'lightweight-translation' ),
			'blog'           => __( 'Blog', 'lightweight-translation' ),
			'post'           => __( 'Blog posts', 'lightweight-translation' ),
			'archive'        => __( 'Archives', 'lightweight-translation' ),
			'other'          => __( 'Other pages', 'lightweight-translation' ),
			'messages'       => __( 'Messages (add to cart, forms, errors…)', 'lightweight-translation' ),
		);
		if ( isset( $labels[ $type ] ) ) return $labels[ $type ];
		if ( 0 === strpos( $type, 'account:' ) ) {
			$endpoint = substr( $type, 8 );
			$items    = function_exists( 'wc_get_account_menu_items' ) ? (array) wc_get_account_menu_items() : array();
			return $labels['account'] . ' › ' . ( $items[ $endpoint ] ?? ucfirst( str_replace( '-', ' ', $endpoint ) ) );
		}
		if ( 0 === strpos( $type, 'page:' ) ) { $title = get_the_title( (int) substr( $type, 5 ) ); return sprintf( __( 'Page: %s', 'lightweight-translation' ), '' !== $title ? $title : '#' . substr( $type, 5 ) ); }
		if ( 0 === strpos( $type, 'single:' ) ) { $object = get_post_type_object( substr( $type, 7 ) ); return $object ? $object->labels->name : $type; }
		return $type;
	}

	/** The usual pages of the site, in a sensible order: type => URL (used by "Read all pages"). */
	public static function standard_pages() {
		$pages = array( 'home' => home_url( '/' ) );
		$skip  = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$pages['shop'] = wc_get_page_permalink( 'shop' );
			$product = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( $product ) $pages['product'] = get_permalink( $product[0] );
			$category = get_terms( array( 'taxonomy' => 'product_cat', 'number' => 1, 'hide_empty' => true ) );
			if ( $category && ! is_wp_error( $category ) ) $pages['product-cat'] = get_term_link( $category[0] );
			$pages['cart']     = wc_get_cart_url();
			$pages['checkout'] = wc_get_checkout_url();
			$pages['account']  = wc_get_page_permalink( 'myaccount' );
			if ( function_exists( 'wc_get_account_menu_items' ) && function_exists( 'wc_get_account_endpoint_url' ) ) {
				foreach ( array_keys( (array) wc_get_account_menu_items() ) as $endpoint ) if ( ! in_array( $endpoint, array( 'dashboard', 'customer-logout' ), true ) ) $pages[ 'account:' . $endpoint ] = wc_get_account_endpoint_url( $endpoint );
			}
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $special ) $skip[] = (int) wc_get_page_id( $special );
		}
		if ( $skip[1] ) $pages['blog'] = get_permalink( $skip[1] );
		$post = get_posts( array( 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( $post ) $pages['post'] = get_permalink( $post[0] );
		$pages['search'] = add_query_arg( 's', 'a', home_url( '/' ) );
		$pages['404']    = home_url( '/lt-page-not-found-check/' );
		foreach ( get_pages( array( 'number' => 30, 'post_status' => 'publish' ) ) as $page ) if ( ! in_array( (int) $page->ID, $skip, true ) ) $pages[ 'page:' . $page->ID ] = get_permalink( $page );
		return array_filter( $pages, 'is_string' );
	}

	/** A product to put in the cart before reading the checkout (an empty cart has no checkout). */
	public static function sample_cart_url() {
		if ( ! function_exists( 'wc_get_products' ) ) return '';
		$ids = wc_get_products( array( 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'limit' => 1, 'return' => 'ids' ) );
		return $ids ? add_query_arg( 'add-to-cart', (int) $ids[0], home_url( '/' ) ) : '';
	}

	/**
	 * Makes sure every translatable text of the page is in Interface texts (adds the missing ones as
	 * "to translate") and returns the field keys to show.
	 * @return array [ keys, disabled domains => count ]
	 */
	public static function prepare( $page ) {
		global $wpdb;
		$keys      = array();
		$disabled  = array();
		$sources   = array();
		foreach ( (array) $page['texts'] as $field => $text ) {
			list( $source, $domain ) = $text;
			// Checked again here: the plugin may have been enabled since the visit.
			if ( ! in_array( $domain, array( 'site', 'manual' ), true ) && ! LT_Frontend_Filters::domain_allowed( $domain ) ) { $disabled[ $domain ] = ( $disabled[ $domain ] ?? 0 ) + 1; continue; }
			$field = sanitize_key( $field );
			$keys[] = $field;
			$keys[] = 'text_' . md5( $source ); // a translation added by hand with the visible wording
			$sources[ $field ] = array( $source, $domain );
		}
		self::register( $sources );
		arsort( $disabled );
		return array( array_values( array_unique( $keys ) ), $disabled );
	}

	/**
	 * Makes texts translatable: adds them to Interface texts ("to translate" in every language) with their origin.
	 * @param array $texts field => [ source, domain ]
	 */
	public static function register( $texts ) {
		global $wpdb;
		$router    = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! $texts || ! $languages ) return;
		$table    = LT_Translation_Store::table();
		$existing = array();
		foreach ( array_chunk( array_keys( $texts ), 300 ) as $chunk ) {
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT language_code, field_key FROM $table WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $found as $row ) $existing[ $row->language_code ][ $row->field_key ] = true;
		}
		$index   = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$changed = false;
		$added = false;
		foreach ( $texts as $field => $info ) {
			foreach ( $languages as $language ) if ( ! isset( $existing[ $language ][ $field ] ) ) { LT_Translation_Store::collect_empty( $language, $field, $info[0] ); $added = true; }
			if ( ! isset( $index[ $field ] ) && 0 !== strpos( $field, 'text_' ) ) { $index[ $field ] = array( 'd' => $info[1], 't' => 'l', 's' => 'page', 'a' => 'f' ); $changed = true; }
		}
		if ( $changed ) update_option( LT_Code_Scanner::INDEX, $index, false );
		if ( $added || $changed ) LT_Content_Catalog::strings_changed();
	}

	/* ---------- Admin bar menu ---------- */

	private static function admin_url( $page, $args = array() ) { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }

	public function menu( $bar ) {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) return;
		$router    = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! $languages ) return;
		$current = $router->current_language();
		$target  = $router->is_secondary() ? $current : $languages[0];
		$shop    = current_user_can( lt_capability() );
		$texts   = $shop ? self::admin_url( 'lt-strings', array( 'page_type' => self::page_type() ) ) : '';
		$bar->add_node( array(
			'id'    => 'lt-translate',
			'title' => '🌐 ' . esc_html__( 'Translate', 'lightweight-translation' ) . ' <span style="opacity:.75">' . esc_html( strtoupper( $current ) ) . '</span>',
			'href'  => $texts ?: '#',
			'meta'  => array( 'title' => __( 'Translate what is displayed on this page', 'lightweight-translation' ) ),
		) );
		$add = function( $id, $title, $href, $parent = 'lt-translate' ) use ( $bar ) { $bar->add_node( array( 'parent' => $parent, 'id' => 'lt-translate-' . $id, 'title' => esc_html( $title ), 'href' => $href ) ); };
		$flag = LT_Language_Router::flag( $target ) . ' ' . strtoupper( $target );

		// The item shown: page, post, product, shop page, or category / tag.
		$object = get_queried_object();
		if ( function_exists( 'is_shop' ) && is_shop() ) $object = get_post( wc_get_page_id( 'shop' ) );
		if ( $object instanceof WP_Post && current_user_can( 'edit_post', $object->ID ) && ( $link = get_edit_post_link( $object->ID, 'raw' ) ) ) {
			$type   = get_post_type_object( $object->post_type );
			$blocks = function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $object );
			/* translators: 1: item type (page, product…), 2: language */
			$add( 'item', sprintf( __( 'Translate this %1$s (%2$s)', 'lightweight-translation' ), $type ? strtolower( $type->labels->singular_name ) : 'page', $flag ), $blocks ? add_query_arg( 'lt_lang', $target, $link ) : $link . '#lt-translations' );
		} elseif ( $object instanceof WP_Term && current_user_can( 'manage_categories' ) && ( $link = get_edit_term_link( $object->term_id, $object->taxonomy ) ) ) {
			$tax = get_taxonomy( $object->taxonomy );
			$add( 'item', sprintf( __( 'Translate this %1$s (%2$s)', 'lightweight-translation' ), $tax ? strtolower( $tax->labels->singular_name ) : 'category', $flag ), $link );
		}
		if ( $shop ) {
			/* translators: %s: kind of page (Cart, My account…) */
			$add( 'texts', sprintf( __( 'Texts of “%s” (buttons, messages, widgets…)', 'lightweight-translation' ), self::label( self::page_type() ) ), $texts );
			$add( 'common', __( 'Texts of every page (header, footer, menus)', 'lightweight-translation' ), self::admin_url( 'lt-strings', array( 'page_type' => 'common' ) ) );
			$add( 'pages', __( 'All pages of the site…', 'lightweight-translation' ), self::admin_url( 'lt-strings' ) );
			$add( 'find', __( 'Find a text…', 'lightweight-translation' ), self::admin_url( 'lt-find' ) );
		}
		// Same page in the other languages.
		$bar->add_group( array( 'parent' => 'lt-translate', 'id' => 'lt-translate-view', 'meta' => array( 'class' => 'ab-sub-secondary' ) ) );
		foreach ( $router->languages() as $language ) {
			if ( $language === $current ) continue;
			/* translators: %s: language */
			$add( 'view-' . $language, sprintf( __( 'View in %s', 'lightweight-translation' ), LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ), LT_Language_Router::url( $language ), 'lt-translate-view' );
		}
	}
}

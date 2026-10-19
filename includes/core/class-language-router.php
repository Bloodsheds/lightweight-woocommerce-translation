<?php
defined( 'ABSPATH' ) || exit;
class LT_Language_Router {
	private static $instance; private $lang = ''; private $path = ''; private $forced = false;
	/**
	 * current_language() runs for every interface text and many meta reads (thousands of times per page):
	 * the language settings, the async-request check, the language of the calling page and the URL prefix
	 * are worked out once per request. A change of the language settings clears them.
	 */
	private $settings = array(); private $async = null; private $async_lang = null; private $url_lang = null;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	public function forget_settings() { $this->settings = array(); $this->async_lang = null; $this->url_lang = null; }
	private function __construct() {
		foreach ( array( 'lt_default_language', 'lt_secondary_languages' ) as $option ) foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) add_action( $hook . $option, array( $this, 'forget_settings' ) );
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', function( $vars ) { $vars[] = 'lt_lang'; $vars[] = 'lt_path'; return $vars; } );
		add_action( 'parse_request', array( $this, 'parse' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'redirect_front_page_slug' ), 2 );
		add_action( 'template_redirect', array( $this, 'protect_hidden_language' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'redirect_canonical' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'debug_node' ), 999 );
		add_action( 'wp_head', array( $this, 'seo' ), 1 );
		add_action( 'wp_head', array( $this, 'redirect_script' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_remove_core_canonical' ) );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_action( 'wp', array( $this, 'persist_cookie' ) );
		// AJAX calls of the page (side cart, theme, blocks) say which language the page is in.
		add_action( 'wp_head', array( $this, 'ajax_language_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'ajax_language_headers' ), 20 );
		add_action( 'admin_init', array( $this, 'menu_box' ) );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_menu_switcher' ), 10, 2 );
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'menu_item_fields' ), 10, 5 );
		add_filter( 'nav_menu_css_class', array( $this, 'menu_item_classes' ), 10, 4 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'add_menu_switcher_languages' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'menu_language_link_attributes' ), 10, 4 );
		add_action( 'wp_ajax_lt_add_menu_switcher', array( $this, 'ajax_add_menu_switcher' ) );
		add_action( 'admin_post_lt_add_menu_switcher', array( $this, 'admin_add_menu_switcher' ) );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'render_menu_switcher' ), 10, 4 );
		add_filter( 'post_link', array( $this, 'post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'post_link' ), 20, 2 );
		add_filter( 'page_link', array( $this, 'page_link' ), 20, 2 );
		add_filter( 'term_link', array( $this, 'term_link' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
	}
	/** Language WordPress itself is set to (Settings › General › Site language): "fr" for fr_FR; English if unknown. */
	public static function site_language() {
		$code = sanitize_key( strtolower( substr( (string) get_option( 'WPLANG', '' ) ?: ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' ), 0, 2 ) ) );
		return strlen( $code ) === 2 ? $code : 'en';
	}
	public function languages() {
		if ( isset( $this->settings['languages'] ) ) return $this->settings['languages'];
		$saved = get_option( 'lt_default_language', false );
		$default = false !== $saved ? $saved : self::site_language();
		$secondary = get_option( 'lt_secondary_languages', '' );
		$list = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( array( $default ), explode( ',', $secondary ) ) ) ) ) );
		// Without a saved original language, the site language can change during the request (switch_to_locale): not kept.
		if ( false !== $saved ) $this->settings['languages'] = $list;
		return $list;
	}
	public function default_language() {
		if ( isset( $this->settings['default'] ) ) return $this->settings['default'];
		$saved = get_option( 'lt_default_language', false );
		$default = sanitize_key( false !== $saved ? $saved : self::site_language() );
		if ( false !== $saved ) $this->settings['default'] = $default;
		return $default;
	}
	public function selector_languages() { $languages = array_values( array_filter( $this->languages(), function( $l ) { return ! $this->is_hidden( $l ); } ) ); /* hidden languages: never in the switcher (editors preview them via /xx/) */ if ( ! get_option( 'lt_selector_include_default', 1 ) ) $languages = array_values( array_diff( $languages, array( $this->default_language() ) ) ); return $languages; }
	private function is_async_request() {
		if ( null !== $this->async ) return $this->async;
		$async = wp_doing_ajax() || ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX ) || defined( 'REST_REQUEST' ) || isset( $_REQUEST['wc-ajax'] ) || isset( $_GET['rest_route'] );
		// REST_REQUEST is only defined while WordPress parses the request: a "no" is final once the page is being built.
		if ( $async || did_action( 'wp' ) ) $this->async = $async;
		return $async;
	}
	/** Language of the page of this site that sent the request ('' when unknown). */
	private function referer_language() {
		$referer = (string) wp_get_raw_referer();
		if ( '' === $referer || wp_parse_url( $referer, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) return '';
		$first = explode( '/', self::relative_path( $referer ) )[0];
		return in_array( $first, $this->languages(), true ) ? $first : $this->default_language();
	}
	/** AJAX/REST calls made from wp-admin (block editor, quick edit…) must never be translated. */
	private function is_admin_referer() { $referer = wp_get_raw_referer(); return $referer && 0 === strpos( $referer, admin_url() ); }
	public function current_language() {
		if ( ! $this->lang && $this->is_async_request() ) {
			if ( null === $this->async_lang ) $this->async_lang = $this->async_language();
			if ( '' !== $this->async_lang ) $this->lang = $this->async_lang;
		}
		// Before WordPress parses the URL (themes like WoodMart read their options very early), read the
		// language prefix directly from the address so early texts are translated too.
		if ( ! $this->lang && ! $this->forced && ! did_action( 'parse_request' ) && ! is_admin() && ! $this->is_async_request() ) {
			if ( null === $this->url_lang ) {
				$first = explode( '/', self::relative_path( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) )[0];
				$this->url_lang = $first && $first !== $this->default_language() && in_array( $first, $this->languages(), true ) ? $first : '';
			}
			if ( '' !== $this->url_lang ) return $this->url_lang;
		}
		return $this->lang ?: $this->default_language();
	}
	/** Language of the page that made an AJAX / REST call ('' when unknown or when the call comes from wp-admin). */
	private function async_language() {
		if ( ! isset( $_REQUEST['lt_lang'] ) && ! isset( $_SERVER['HTTP_X_LT_LANG'] ) && $this->is_admin_referer() ) return '';
		// Language of the page that made the call (side cart, theme AJAX…), read from its address; the cookie is
		// only a fallback (another tab may have changed it).
		$candidate = isset( $_REQUEST['lt_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lt_lang'] ) ) : ( isset( $_SERVER['HTTP_X_LT_LANG'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_LT_LANG'] ) ) : $this->referer_language() );
		if ( '' === $candidate ) $candidate = isset( $_COOKIE['lt_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['lt_lang'] ) ) : '';
		return in_array( $candidate, $this->languages(), true ) ? $candidate : '';
	}
	public function is_secondary() { return $this->current_language() !== $this->default_language(); }
	public function rewrite() {
		$languages = array_map( function( $language ) { return preg_quote( $language, '#' ); }, $this->languages() );
		if ( $languages ) add_rewrite_rule( '^(' . implode( '|', $languages ) . ')(?:/(.*))?/?$', 'index.php?lt_lang=$matches[1]&lt_path=$matches[2]', 'top' );
	}
	public function maybe_flush_rewrite_rules() { if ( get_option( 'lt_flush_rewrite_rules', 0 ) ) { delete_option( 'lt_flush_rewrite_rules' ); flush_rewrite_rules( false ); } }
	public function parse( $wp ) {
		if ( empty( $wp->query_vars['lt_lang'] ) || ! in_array( $wp->query_vars['lt_lang'], $this->languages(), true ) ) return;
		$this->lang = sanitize_key( $wp->query_vars['lt_lang'] );
		$raw        = trim( (string) ( $wp->query_vars['lt_path'] ?? '' ), '/' );
		$this->path = $raw;
		// Remove our private vars: WordPress must receive exactly the same query as for the default language.
		// While lt_lang/lt_path stayed in the query, WP_Query never recognised /fr/ as the front page and showed
		// the blog index ("Blog – Nothing Found").
		unset( $wp->query_vars['lt_lang'], $wp->query_vars['lt_path'] );
		$paged = 0;
		if ( preg_match( '#(?:^|/)page/(\d+)$#', $raw, $m ) ) { $paged = absint( $m[1] ); $raw = trim( preg_replace( '#(?:^|/)page/\d+$#', '', $raw ), '/' ); }
		if ( $paged > 1 ) $wp->query_vars['paged'] = $paged;
		$path = $this->translate_path_bases( $raw, $this->lang, true );
		if ( $path ) {
			// Only routable public content belongs in visitor URLs. WoodMart's internal blocks,
			// sidebars and abandoned carts are rendered inside pages, never resolved as pages.
			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$post = $this->resolve_post_request( $path, $post_types );
			if ( $post ) {
				if ( 'page' === $post->post_type ) {
					$wp->query_vars['page_id'] = $post->ID;
					unset( $wp->query_vars['p'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
				} else {
					$wp->query_vars['p'] = $post->ID;
					$wp->query_vars['post_type'] = $post->post_type;
					unset( $wp->query_vars['page_id'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
				}
			}
			elseif ( ! $this->resolve_term_request( $wp, $path ) ) {
				// Not a page / post / category by itself: an endpoint or extra part after it (/fr/mon-compte/orders/,
				// /fr/commande/order-received/123/, /fr/blog/feed/…). Rebuild the original-language URL and let
				// WordPress's own rewrite rules read it, so every plugin endpoint keeps working in every language.
				$vars = $this->match_original_path( $raw, $post_types ); // endpoint names and values are not URL bases
				if ( null !== $vars ) {
					unset( $wp->query_vars['page_id'], $wp->query_vars['p'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
					$wp->query_vars = array_merge( $wp->query_vars, $vars );
				} else {
					$wp->query_vars['pagename'] = $path;
				}
			}
		} elseif ( empty( $wp->query_vars['page_id'] ) && empty( $wp->query_vars['p'] ) && empty( $wp->query_vars['pagename'] ) && 'page' === get_option( 'show_on_front' ) && absint( get_option( 'page_on_front' ) ) ) {
			// /fr/ = static home page (Settings > Reading), loaded explicitly.
			$wp->query_vars['page_id'] = absint( get_option( 'page_on_front' ) );
			unset( $wp->query_vars['p'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
		}
		// Empty path with "latest posts" on the front: nothing to add, WordPress handles it like the default home.
		do_action( 'lt_language_request_parsed', $wp );
	}
	/**
	 * Translated path with an extra part: finds the longest beginning that is a known page / post / term,
	 * swaps it for its original-language URL, then matches WordPress's rewrite rules on the result.
	 * @return array|null query vars, or null when nothing matched
	 */
	private function match_original_path( $path, $post_types ) {
		$segments = explode( '/', trim( $path, '/' ) );
		for ( $k = count( $segments ) - 1; $k >= 0; $k-- ) {
			$prefix = implode( '/', array_slice( $segments, 0, $k ) );
			$rest   = implode( '/', array_slice( $segments, $k ) );
			$base   = $this->translate_path_bases( $prefix, $this->lang, true );
			if ( '' !== $prefix ) {
				$post = $this->resolve_post_request( $prefix, $post_types );
				$link = '';
				if ( $post ) {
					$link = $this->with_language( $this->default_language(), function() use ( $post ) { return get_permalink( $post ); } );
				} else {
					$probe = new stdClass();
					$probe->query_vars = array();
					$term = $this->resolve_term_request( $probe, $prefix ) ? get_term_by( 'slug', $probe->query_vars['term'], $probe->query_vars['taxonomy'] ) : null;
					if ( $term instanceof WP_Term ) $link = $this->with_language( $this->default_language(), function() use ( $term ) { return get_term_link( $term ); } );
				}
				if ( is_string( $link ) && '' !== $link ) $base = self::relative_path( $link );
			}
			$vars = self::match_rewrite_rules( trim( $base . '/' . $rest, '/' ) );
			if ( $vars ) return $vars;
		}
		return null;
	}

	/** Same matching as WP::parse_request(), on an original-language path. */
	private static function match_rewrite_rules( $request ) {
		global $wp_rewrite, $wp;
		$rules = $wp_rewrite ? $wp_rewrite->wp_rewrite_rules() : array();
		if ( ! $rules || '' === $request ) return null;
		$public = $wp instanceof WP ? (array) $wp->public_query_vars : array();
		foreach ( $rules as $match => $query ) {
			if ( ! preg_match( "#^$match#", $request, $matches ) && ! preg_match( "#^$match#", urldecode( $request ), $matches ) ) continue;
			if ( false !== strpos( $query, 'lt_lang=' ) ) continue; // our own /fr/… rule
			if ( $wp_rewrite->use_verbose_page_rules && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) && ! get_page_by_path( $matches[ $varmatch[1] ] ) ) continue;
			$query = preg_replace( '!^.+\?!', '', $query );
			parse_str( WP_MatchesMapRegex::apply( $query, $matches ), $vars );
			$out = array();
			foreach ( $vars as $key => $value ) if ( ! $public || in_array( $key, $public, true ) ) $out[ $key ] = is_string( $value ) ? str_replace( '%2F', '/', $value ) : $value;
			return $out;
		}
		return null;
	}

	private function resolve_post_request( $path, $post_types ) {
		foreach ( LT_Translation_Store::find_slugs( $this->lang, basename( $path ) ) as $row ) {
			if ( 'term' === $row->object_type || ! in_array( $row->object_type, $post_types, true ) ) continue;
			$candidate = get_post( $row->object_id );
			if ( ! $candidate instanceof WP_Post || $candidate->post_type !== $row->object_type ) continue;
			$valid_slug = LT_Translation_Store::translate( $this->lang, $candidate->post_type, $candidate->ID, 'slug', $candidate->post_name );
			if ( sanitize_title( $valid_slug ) === sanitize_title( basename( $path ) ) && $this->path_matches_url( $path, get_permalink( $candidate ) ) ) return $candidate;
		}
		// get_page_by_path accepts an array of post types. One indexed lookup per path is
		// enough; probing every registered type separately grows costly on plugin-heavy sites.
		$ambiguous = array();
		foreach ( array_unique( array( $path, basename( $path ) ) ) as $candidate_path ) {
			$candidate = get_page_by_path( $candidate_path, OBJECT, $post_types );
			if ( $candidate instanceof WP_Post && $this->path_matches_url( $path, get_permalink( $candidate ) ) ) return $candidate;
			if ( $candidate instanceof WP_Post ) $ambiguous[] = $candidate_path;
		}
		// A slug shared by two post types can return the wrong candidate first.
		// Only that rare case needs the old per-type fallback.
		foreach ( $ambiguous as $candidate_path ) foreach ( $post_types as $post_type ) {
			$candidate = get_page_by_path( $candidate_path, OBJECT, $post_type );
			if ( $candidate instanceof WP_Post && $this->path_matches_url( $path, get_permalink( $candidate ) ) ) return $candidate;
		}

		return false;
	}
	private function resolve_term_request( $wp, $path ) {
		$requested_slug = sanitize_title( basename( $path ) );
		$term           = false;
		foreach ( LT_Translation_Store::find_slugs( $this->lang, $requested_slug, 'term' ) as $row ) {
			$candidate = get_term( $row->object_id );
			if ( ! $candidate instanceof WP_Term || is_wp_error( $candidate ) ) continue;
			$translated = LT_Translation_Store::translate( $this->lang, 'term', $candidate->term_id, 'slug', $candidate->slug );
			$link = get_term_link( $candidate );
			if ( sanitize_title( $translated ) === $requested_slug && ! is_wp_error( $link ) && $this->path_matches_url( $path, $link ) ) { $term = $candidate; break; }
		}
		if ( ! $term ) {
			$taxonomies = LT_Content_Catalog::public_taxonomies();
			$candidates = $taxonomies ? get_terms( array( 'taxonomy' => $taxonomies, 'slug' => $requested_slug, 'hide_empty' => false, 'suppress_filter' => true ) ) : array();
			if ( ! is_wp_error( $candidates ) ) foreach ( $candidates as $candidate ) {
				$link = get_term_link( $candidate );
				if ( ! is_wp_error( $link ) && $this->path_matches_url( $path, $link ) ) { $term = $candidate; break; }
			}
		}
		if ( ! $term || is_wp_error( $term ) ) return false;
		$taxonomy = get_taxonomy( $term->taxonomy );
		$query_var = $taxonomy && $taxonomy->query_var ? $taxonomy->query_var : $term->taxonomy;
		$wp->query_vars[ $query_var ] = $term->slug;
		$wp->query_vars['taxonomy']   = $term->taxonomy;
		$wp->query_vars['term']       = $term->slug;
		unset( $wp->query_vars['pagename'], $wp->query_vars['name'] );
		return true;
	}
	private function path_matches_url( $requested_path, $url ) {
		$path      = trim( rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) ), '/' );
		$home_path = trim( rawurldecode( (string) parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );
		if ( $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) $path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
		foreach ( $this->languages() as $language ) if ( $path === $language || 0 === strpos( $path, $language . '/' ) ) { $path = ltrim( substr( $path, strlen( $language ) ), '/' ); break; }
		$requested_path = $this->translate_path_bases( trim( rawurldecode( $requested_path ), '/' ), $this->lang, true );
		$path = $this->translate_path_bases( $path, $this->lang, true );
		return $requested_path === $path;
	}
	/** URL path without the WordPress home sub-directory (e.g. /shop/fr/x → fr/x when home is example.com/shop). */
	public static function relative_path( $url ) {
		$path      = trim( (string) parse_url( (string) $url, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) $path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
		return $path;
	}
	private function base_map( $language ) {
		$saved = (array) get_option( 'lt_url_bases', array() );
		$map   = array();
		foreach ( explode( ',', $saved[ $language ] ?? '' ) as $pair ) { $parts = array_map( 'trim', explode( '=', $pair, 2 ) ); if ( 2 === count( $parts ) && sanitize_title( $parts[0] ) && sanitize_title( $parts[1] ) ) $map[ sanitize_title( $parts[0] ) ] = sanitize_title( $parts[1] ); }
		return $map;
	}
	public function translate_path_bases( $path, $language, $reverse = false ) {
		$map = $this->base_map( $language );
		if ( $reverse ) $map = array_flip( $map );
		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		$limit = count( $parts ) > 1 ? count( $parts ) - 1 : count( $parts );
		for ( $index = 0; $index < $limit; $index++ ) if ( isset( $map[ $parts[ $index ] ] ) ) $parts[ $index ] = $map[ $parts[ $index ] ];
		return implode( '/', $parts );
	}
	/** Static home page (Settings > Reading): always served as / or /fr/, never with its slug. */
	public static function is_front_page_post( $post ) {
		$post_id = $post instanceof WP_Post ? $post->ID : ( is_scalar( $post ) ? absint( $post ) : 0 );
		return $post_id && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id;
	}
	/** /fr/accueil/ (or /fr/home-translated/) → 301 to /fr/. */
	public function redirect_front_page_slug() {
		if ( ! $this->lang || is_admin() || '' === $this->path || ! self::is_front_page_post( get_queried_object() ) ) return;
		wp_safe_redirect( self::url( $this->lang ), 301 );
		exit;
	}
	/** WordPress would "canonicalise" /fr/ (front page) back to / and /fr/x to the default URL: our router owns these URLs. */
	public function redirect_canonical( $redirect_url, $requested_url = '' ) { return $this->lang ? false : $redirect_url; }
	/** Diagnostic for administrators: add ?lt_debug=1 to any front-end URL, details appear in the admin bar. */
	public function debug_node( $bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) || ( empty( $_GET['lt_debug'] ) && ! get_option( 'lt_debug_mode', 0 ) ) ) return; // phpcs:ignore WordPress.Security.NonceVerification
		global $wp;
		$language = $this->current_language();
		$bar->add_node( array( 'id' => 'lt-debug', 'title' => '🐞 LT ' . strtoupper( $language ) . ' · V' . LT_VERSION, 'href' => admin_url( 'admin.php?page=lt-preferences#tools' ) ) );
		$lines = array(
			'language: ' . $language . ' (from URL: ' . ( $this->lang ?: '-' ) . ', path: ' . ( '' === $this->path ? '(empty)' : $this->path ) . ', cookie: ' . sanitize_key( $_COOKIE['lt_lang'] ?? '-' ) . ')' . ( $this->is_hidden( $language ) ? ' · HIDDEN language' : '' ),
			'rewrite rule: ' . ( $wp->matched_rule ?? '-' ) . ' → ' . ( $wp->matched_query ?? '-' ),
			'query vars: ' . wp_json_encode( $wp->query_vars ),
			'page type: front_page=' . ( is_front_page() ? 'yes' : 'no' ) . ' home=' . ( is_home() ? 'yes' : 'no' ) . ' page=' . ( is_page() ? 'yes' : 'no' ) . ' singular=' . ( is_singular() ? 'yes' : 'no' ) . ' archive=' . ( is_archive() ? 'yes' : 'no' ) . ' search=' . ( is_search() ? 'yes' : 'no' ) . ' 404=' . ( is_404() ? 'yes' : 'no' ),
			'queried object: ' . get_queried_object_id() . ' (' . ( is_object( get_queried_object() ) ? get_class( get_queried_object() ) : '-' ) . ') · show_on_front=' . get_option( 'show_on_front' ) . ' page_on_front=' . get_option( 'page_on_front' ),
		);
		// Translation status of what is displayed.
		$object = get_queried_object();
		if ( $language !== $this->default_language() && class_exists( 'LT_Content_Catalog' ) ) {
			$item = null;
			if ( $object instanceof WP_Post ) $item = LT_Content_Catalog::item( 'post:' . $object->post_type, $object->ID );
			elseif ( $object instanceof WP_Term ) $item = LT_Content_Catalog::item( 'tax:' . $object->taxonomy, $object->term_id );
			if ( $item ) {
				$states = array();
				foreach ( $item['fields'] as $field => $source ) { list( $state ) = LT_Content_Catalog::field_status( $item['type'], $item['id'], $field, $source, $language ); $states[] = $field . '=' . $state; }
				$lines[] = 'translation (' . $language . '): ' . ( $states ? implode( ', ', $states ) : 'no translatable field' ) . '  [outdated = original changed since; missing = original shown]';
			}
		}
		if ( is_search() && class_exists( 'LT_Frontend_Filters' ) ) $lines[] = 'search "' . get_search_query() . '": matching languages: ' . implode( ', ', $this->languages() ) . '; display: ' . strtoupper( $language );
		$caches = array_keys( array_filter( array( 'LiteSpeed' => defined( 'LSCWP_V' ), 'WP Rocket' => defined( 'WP_ROCKET_VERSION' ), 'W3 Total Cache' => defined( 'W3TC' ), 'WP Super Cache' => function_exists( 'wp_cache_clear_cache' ) ) ) );
		$lines[] = 'page cache: ' . ( $caches ? implode( ', ', $caches ) . ' (logged-in admins usually bypass it: test in a private window)' : 'none detected' );
		$stats   = LT_Translation_Store::$stats;
		$lines[] = 'performance (so far on this page): ' . $stats['queries'] . ' translation queries · ' . $stats['primed'] . ' items preloaded in batch · ' . $stats['strings'] . ' translated interface texts loaded for this request';
		$lines[] = 'alternate URLs: ' . implode( ' | ', array_map( function( $l ) { return $l . '=' . self::url( $l ); }, $this->languages() ) );
		foreach ( $lines as $i => $line ) $bar->add_node( array( 'id' => 'lt-debug-' . $i, 'parent' => 'lt-debug', 'title' => esc_html( $line ) ) );
	}

	/** Editors browse every language freely (testing): never redirected. */
	private function is_editor() { return is_user_logged_in() && current_user_can( 'edit_posts' ); }
	private function sticky() { return (bool) get_option( 'lt_sticky_language', 1 ); }
	/** Language the visitor chose ('' when none / not usable). */
	private function chosen_language() {
		$cookie = isset( $_COOKIE['lt_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['lt_lang'] ) ) : '';
		return in_array( $cookie, $this->languages(), true ) && ! $this->is_hidden( $cookie ) ? $cookie : '';
	}
	/**
	 * On a page of the original language (no /xx/ prefix):
	 * - a visitor who chose another language (back button, old link, e-mail, search result…) is sent to the same
	 *   page in his language;
	 * - a first-time visitor can be sent to his browser language (option).
	 */
	public function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_404() || $this->is_async_request() || $this->current_language() !== $this->default_language() ) return;
		if ( 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || $this->is_bot() || $this->is_editor() ) return;
		$chosen = $this->chosen_language();
		if ( isset( $_COOKIE['lt_lang'] ) ) {
			if ( ! $this->sticky() || ! $chosen || $chosen === $this->default_language() ) return;
			$lang = $chosen;
		} else {
			if ( ! get_option( 'lt_auto_redirect', 0 ) ) return;
			$lang = $this->browser_language();
			if ( ! $lang || $lang === $this->default_language() ) return;
		}
		// Real translated address of the current page (translated slug + URL words), query string kept.
		$target = self::url( $lang );
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) $target .= '?' . wp_unslash( $_SERVER['QUERY_STRING'] );
		header( 'Vary: Accept-Language, Cookie' );
		wp_safe_redirect( $target, 302 );
		exit;
	}
	private function browser_language() {
		$header = strtolower( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' );
		foreach ( $this->languages() as $language ) if ( ! $this->is_hidden( $language ) && preg_match( '/(?:^|[,;])\s*' . preg_quote( $language, '/' ) . '(?:[-_][a-z]+)?(?:[,;]|$)/', $header ) ) return $language;
		return $this->default_language();
	}
	private function is_bot() { return (bool) preg_match( '/bot|crawl|spider|slurp|lighthouse|pagespeed|facebookexternalhit|preview/i', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); }
	/**
	 * Very small script in the <head>, before WooCommerce's scripts:
	 * - the side cart kept by the browser (sessionStorage) was built in another language: forget it, so
	 *   WooCommerce asks the server for a fresh one in the language of this page;
	 * - AJAX requests sent with jQuery carry an "X-LT-Lang" header (addresses are not modified).
	 */
	public function ajax_language_script() {
		if ( is_admin() ) return;
		$language = $this->current_language();
		echo '<script>(function(l){try{var s=window.sessionStorage;if(s&&s.getItem("lt_cart_lang")!==l){for(var i=s.length-1;i>=0;i--){var k=s.key(i);if(k&&(k.indexOf("wc_fragments_")===0||k.indexOf("wc_cart_hash_")===0))s.removeItem(k);}s.setItem("lt_cart_lang",l);}}catch(e){}window.ltLang=l;})(' . wp_json_encode( $language ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	public function ajax_language_headers() {
		if ( is_admin() ) return;
		$language = wp_json_encode( $this->current_language() );
		wp_add_inline_script( 'jquery-core', 'jQuery.ajaxPrefilter(function(o,a,x){if(!o.crossDomain)x.setRequestHeader("X-LT-Lang",' . $language . ');});' );
		wp_add_inline_script( 'wp-api-fetch', 'wp.apiFetch.use(function(o,n){o.headers=Object.assign({},o.headers,{"X-LT-Lang":' . $language . '});return n(o);});' );
	}

	public function persist_cookie() {
		if ( headers_sent() || is_admin() || is_feed() || is_404() || $this->is_async_request() ) return;
		$language = $this->current_language();
		if ( ( $_COOKIE['lt_lang'] ?? '' ) === $language ) return;
		if ( $language === $this->default_language() && $this->chosen_language() && $this->sticky() ) return; // a page in the original language does not erase the visitor's choice
		// Not HttpOnly: the cache-proof redirect script below reads and writes it too.
		setcookie( 'lt_lang', $language, time() + MONTH_IN_SECONDS * 6, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
	}
	/**
	 * Same logic in the browser, so it also works when the page comes from a page cache (LiteSpeed, Hostinger…)
	 * and PHP never runs: remember the language of every visited page, and on the very first visit of an
	 * original-language page redirect to the visitor's browser language.
	 */
	public function redirect_script() {
		if ( is_admin() || is_feed() || is_404() ) return;
		$current = $this->current_language();
		$default = $this->default_language();
		$targets = array();
		if ( $current === $default ) foreach ( $this->languages() as $language ) if ( $language !== $current && ! $this->is_hidden( $language ) ) $targets[ $language ] = self::url( $language );
		$data = array( 'current' => $current, 'def' => $default, 'targets' => (object) $targets, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'sticky' => $this->sticky(), 'browser' => (bool) get_option( 'lt_auto_redirect', 0 ), 'editor' => $this->is_editor() );
		// Same rules as maybe_redirect(), in the browser: works with cached pages and with the Back button
		// (pages restored from the browser's memory run "pageshow", not the server).
		echo "<script>(function(d){try{function get(){var m=document.cookie.match(/(?:^|; )lt_lang=([^;]*)/);return m?m[1]:'';}"
			. "function set(l){document.cookie='lt_lang='+l+'; path='+d.path+'; max-age=15552000; SameSite=Lax'+(d.secure?'; Secure':'');}"
			. "function choose(l){set(l);document.cookie='lt_lang_choice='+l+'; path='+d.path+'; max-age=600; SameSite=Lax'+(d.secure?'; Secure':'');}"
			. "window.ltSetLang=set;"
			// Choosing a language in any selector = the visitor's choice, remembered before leaving the page. Kept here
			// (every page) rather than in the selector script, which a cached menu may not request.
			. "document.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('a[data-lt-lang]'):null;if(a)choose(a.getAttribute('data-lt-lang'));},true);"
			. "function go(l){if(d.targets[l]){var t=new URL(d.targets[l],location.href),c=new URL(location.href);c.searchParams.forEach(function(v,k){if(!t.searchParams.has(k))t.searchParams.append(k,v);});t.hash=c.hash;if(t.href!==location.href){location.replace(t.href);return true;}}return false;}"
			. "function run(){var c=get();"
			. "if(d.current!==d.def){if(c!==d.current)set(d.current);return;}"
			. "if(d.editor||/bot|crawl|spider|slurp|lighthouse|pagespeed/i.test(navigator.userAgent)){if(!c)set(d.current);return;}"
			. "if(c){if(d.sticky&&c!==d.def)go(c);return;}"
			. "set(d.current);if(!d.browser)return;"
			. "var l=(navigator.languages||[navigator.language||'']);for(var i=0;i<l.length;i++){var k=String(l[i]).toLowerCase().split('-')[0];if(k===d.current)return;if(go(k))return;}}"
			. "run();window.addEventListener('pageshow',function(e){if(e.persisted)run();});"
			. "}catch(e){}})(" . wp_json_encode( $data ) . ");</script>\n";
	}

	/** Runs $callback as if the current request were in $language (e.g. to get a permalink in that language). */
	public function with_language( $language, $callback ) {
		$previous     = array( $this->lang, $this->forced );
		$this->lang   = $language === $this->default_language() ? '' : $language;
		$this->forced = true;
		try { return $callback(); } finally { list( $this->lang, $this->forced ) = $previous; }
	}
	public static function url( $language ) {
		$language = sanitize_key( $language );
		if ( ! get_option( 'permalink_structure' ) ) {
			$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
			$url = home_url( self::relative_path( $uri ) );
			$query = array();
			wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
			unset( $query['lt_lang'], $query['lt_path'] );
			if ( $language !== self::instance()->default_language() ) $query['lt_lang'] = $language;
			return add_query_arg( $query, $url );
		}
		$path = self::without_language_prefix( self::relative_path( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$object = get_queried_object();
		if ( self::is_front_page_post( $object ) || ( is_front_page() && ! is_paged() ) ) return self::with_request_query( trailingslashit( home_url( $language === self::instance()->default_language() ? '' : $language ) ) );
		if ( $object instanceof WP_Post || $object instanceof WP_Term ) {
			$url = self::object_language_url( $object, $language, $path );
			if ( $url ) return self::with_request_query( $url );
		}
		$path = self::instance()->translate_path_bases( $path, self::instance()->current_language(), true );
		$path = self::instance()->translate_path_bases( $path, $language );
		return self::with_request_query( trailingslashit( home_url( $language === self::instance()->default_language() ? $path : $language . '/' . $path ) ) );
	}
	/** Remove exactly one complete language segment, never the beginning of a slug such as "fragrance". */
	private static function without_language_prefix( $path ) {
		$parts = explode( '/', trim( (string) $path, '/' ) );
		if ( in_array( $parts[0], self::instance()->languages(), true ) ) array_shift( $parts );
		return implode( '/', $parts );
	}
	/** Translate the resolved page/term permalink, retaining everything after that object (endpoints, IDs, pages). */
	private static function object_language_url( $object, $language, $path ) {
		$router = self::instance();
		$permalink = function() use ( $object ) { return $object instanceof WP_Post ? get_permalink( $object ) : get_term_link( $object ); };
		$target = $router->with_language( $language, $permalink );
		if ( ! is_string( $target ) || '' === $target ) return '';
		$request = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		$suffix = array();
		foreach ( array_unique( array( $router->current_language(), $router->default_language() ) ) as $source_language ) {
			$source = $router->with_language( $source_language, $permalink );
			if ( ! is_string( $source ) || '' === $source ) continue;
			$base = self::without_language_prefix( self::relative_path( $source ) );
			$segments = '' === $base ? array() : explode( '/', $base );
			if ( count( $request ) < count( $segments ) || array_map( 'rawurldecode', $segments ) !== array_map( 'rawurldecode', array_slice( $request, 0, count( $segments ) ) ) ) continue;
			$suffix = array_slice( $request, count( $segments ) );
			break;
		}
		return trailingslashit( $target ) . ( $suffix ? trailingslashit( implode( '/', $suffix ) ) : '' );
	}
	private static function with_request_query( $url ) { $uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); $query = array(); wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query ); unset( $query['lt_lang'], $query['lt_path'], $query['lt_collect'] ); return $query ? add_query_arg( $query, $url ) : $url; }
	private static function replace_last_segment( $path, $slug ) { $parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) ); if ( $parts ) array_pop( $parts ); $parts[] = sanitize_title( $slug ); return implode( '/', $parts ); }
	/** Known languages: code => [native name, flag]. */
	public static function catalog() {
		return array(
			'pt' => array( 'Português', '🇵🇹' ), 'br' => array( 'Português (Brasil)', '🇧🇷' ), 'fr' => array( 'Français', '🇫🇷' ), 'en' => array( 'English', '🇬🇧' ), 'us' => array( 'English (US)', '🇺🇸' ),
			'es' => array( 'Español', '🇪🇸' ), 'de' => array( 'Deutsch', '🇩🇪' ), 'it' => array( 'Italiano', '🇮🇹' ), 'nl' => array( 'Nederlands', '🇳🇱' ), 'be' => array( 'Belgique', '🇧🇪' ),
			'ca' => array( 'Català', '🏴' ), 'pl' => array( 'Polski', '🇵🇱' ), 'ro' => array( 'Română', '🇷🇴' ), 'cs' => array( 'Čeština', '🇨🇿' ), 'sk' => array( 'Slovenčina', '🇸🇰' ),
			'hu' => array( 'Magyar', '🇭🇺' ), 'el' => array( 'Ελληνικά', '🇬🇷' ), 'sv' => array( 'Svenska', '🇸🇪' ), 'da' => array( 'Dansk', '🇩🇰' ), 'fi' => array( 'Suomi', '🇫🇮' ),
			'no' => array( 'Norsk', '🇳🇴' ), 'ru' => array( 'Русский', '🇷🇺' ), 'uk' => array( 'Українська', '🇺🇦' ), 'tr' => array( 'Türkçe', '🇹🇷' ), 'ar' => array( 'العربية', '🇸🇦' ),
			'he' => array( 'עברית', '🇮🇱' ), 'zh' => array( '中文', '🇨🇳' ), 'ja' => array( '日本語', '🇯🇵' ), 'ko' => array( '한국어', '🇰🇷' ), 'hi' => array( 'हिन्दी', '🇮🇳' ),
		);
	}
	public static function labels() { return array_map( function( $entry ) { return $entry[0]; }, self::catalog() ); }
	public static function flag( $lang ) { $catalog = self::catalog(); return $catalog[ $lang ][1] ?? '🌐'; }
	public static function language_label( $lang ) {
		$custom = (array) get_option( 'lt_language_labels', array() );
		if ( 'code' === get_option( 'lt_language_label_mode', 'native' ) ) return strtoupper( $lang );
		if ( ! empty( $custom[ $lang ] ) ) return $custom[ $lang ];
		$labels = self::labels();
		return $labels[ $lang ] ?? strtoupper( $lang );
	}
	/** Languages "in preparation": only logged-in editors can see them (switcher, URLs, hreflang). */
	public function is_hidden( $language ) { return in_array( $language, (array) get_option( 'lt_hidden_languages', array() ), true ) && $language !== $this->default_language(); }
	public function can_see_hidden() { return is_user_logged_in() && current_user_can( 'edit_posts' ); }
	public function protect_hidden_language() {
		if ( $this->lang && $this->is_hidden( $this->lang ) && ! $this->can_see_hidden() ) { wp_safe_redirect( self::url( $this->default_language() ), 302 ); exit; }
	}
	public static function switcher( $atts = array() ) { self::selector_script(); $show_flag = get_option( 'lt_selector_show_flag', 1 ); $show_name = get_option( 'lt_selector_show_name', 1 ); $style = isset( $atts['style'] ) ? sanitize_key( $atts['style'] ) : 'boxed'; if ( ! in_array( $style, array( 'boxed', 'plain', 'list' ), true ) ) $style = 'boxed'; $current = self::instance()->current_language(); if ( 'list' === $style ) return self::language_list_markup( $current ); $current_label = ( $show_flag ? self::flag( $current ) . ' ' : '' ) . ( $show_name ? self::language_label( $current ) : strtoupper( $current ) ); $id = 'lt-language-switcher-' . wp_rand( 1000, 999999 ); $out = '<div id="' . esc_attr( $id ) . '" class="lt-language-switcher-wrap lt-language-switcher-wrap--' . esc_attr( $style ) . ' wd-event-click"><button type="button" class="lt-language-switcher-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '-menu"><span class="lt-language-switcher-current">' . esc_html( $current_label ) . '</span><span class="lt-language-switcher-chevron" aria-hidden="true"></span></button><ul id="' . esc_attr( $id ) . '-menu" class="lt-language-switcher-menu wd-dropdown wd-sub-menu" role="listbox" aria-hidden="true">'; foreach ( self::instance()->selector_languages() as $lang ) { if ( $lang === $current ) continue; $label = ( $show_flag ? self::flag( $lang ) . ' ' : '' ) . ( $show_name ? self::language_label( $lang ) : strtoupper( $lang ) ); $out .= '<li role="none"><a class="lt-language-switcher-option" role="option" data-lt-lang="' . esc_attr( $lang ) . '" href="' . esc_url( self::url( $lang ) ) . '">' . esc_html( $label ) . '</a></li>'; } return $out . '</ul></div>'; }
	private static function language_list_markup( $current ) { $show_flag = get_option( 'lt_selector_show_flag', 1 ); $show_name = get_option( 'lt_selector_show_name', 1 ); $out = '<ul class="lt-language-switcher-list" aria-label="' . esc_attr__( 'Available languages', 'lightweight-translation' ) . '">'; foreach ( self::instance()->selector_languages() as $lang ) { $label = ( $show_flag ? self::flag( $lang ) . ' ' : '' ) . ( $show_name ? self::language_label( $lang ) : strtoupper( $lang ) ); $out .= '<li>'; if ( $lang === $current ) $out .= '<span aria-current="page">' . esc_html( $label ) . '</span>'; else $out .= '<a class="lt-language-switcher-option" data-lt-lang="' . esc_attr( $lang ) . '" href="' . esc_url( self::url( $lang ) ) . '">' . esc_html( $label ) . '</a>'; $out .= '</li>'; } return $out . '</ul>'; }
	public function menu_box() { add_meta_box( 'lt-language-switcher', __( 'Language selector', 'lightweight-translation' ), array( $this, 'menu_box_html' ), 'nav-menus', 'side', 'default' ); }
	public function menu_box_html() { global $nav_menu_selected_id; $menu_id = isset( $_REQUEST['menu'] ) ? absint( $_REQUEST['menu'] ) : absint( $nav_menu_selected_id ?? 0 ); $url = $menu_id ? wp_nonce_url( add_query_arg( array( 'action' => 'lt_add_menu_switcher', 'menu_id' => $menu_id ), admin_url( 'admin-post.php' ) ), 'lt_add_menu_switcher' ) : '#'; echo '<div class="lt-menu-box"><p>' . esc_html__( 'Add a language selector to this menu.', 'lightweight-translation' ) . '</p><p class="description">' . esc_html__( 'Display options are configured under Light translations.', 'lightweight-translation' ) . '</p><p><a class="button-secondary ' . ( $menu_id ? '' : 'disabled' ) . '" href="' . esc_url( $url ) . '">' . esc_html__( 'Add to menu', 'lightweight-translation' ) . '</a></p>' . ( isset( $_GET['lt_added'] ) ? '<p class="lt-menu-result">' . esc_html__( 'Language selector added.', 'lightweight-translation' ) . '</p>' : '' ) . '</div>'; }
	private function create_menu_switcher( $menu_id ) { $items = wp_get_nav_menu_items( $menu_id ); foreach ( (array) $items as $item ) if ( '1' === get_post_meta( $item->ID, '_lt_switcher', true ) ) return $item->ID; $item_id = wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => __( 'Language', 'lightweight-translation' ), 'menu-item-url' => '#lt-language-switcher', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) ); if ( $item_id && ! is_wp_error( $item_id ) ) { update_post_meta( $item_id, '_lt_switcher', '1' ); update_post_meta( $item_id, '_lt_switcher_style', 'theme' ); } return $item_id; }
	public function menu_item_fields( $item_id, $item, $depth, $args, $id ) { if ( '1' !== get_post_meta( $item_id, '_lt_switcher', true ) ) return; $style = $this->menu_switcher_style( $item_id ); echo '<p class="description description-wide field-lt-switcher-style"><label for="edit-menu-item-lt-switcher-style-' . esc_attr( $item_id ) . '"><strong>' . esc_html__( 'Language selector appearance', 'lightweight-translation' ) . '</strong><br><select id="edit-menu-item-lt-switcher-style-' . esc_attr( $item_id ) . '" name="lt_switcher_style[' . esc_attr( $item_id ) . ']"><option value="theme" ' . selected( $style, 'theme', false ) . '>' . esc_html__( 'Match the active theme (recommended)', 'lightweight-translation' ) . '</option><option value="boxed" ' . selected( $style, 'boxed', false ) . '>' . esc_html__( 'Boxed dropdown', 'lightweight-translation' ) . '</option><option value="plain" ' . selected( $style, 'plain', false ) . '>' . esc_html__( 'Plain dropdown', 'lightweight-translation' ) . '</option><option value="list" ' . selected( $style, 'list', false ) . '>' . esc_html__( 'Bulleted language list', 'lightweight-translation' ) . '</option></select><span class="description">' . esc_html__( 'The theme option uses the menu and dropdown design already active on your site.', 'lightweight-translation' ) . '</span></label></p>'; }
	public function save_menu_switcher( $menu_id, $menu_item_db_id = 0 ) { if ( ! current_user_can( 'edit_theme_options' ) ) return; if ( $menu_item_db_id && '1' === get_post_meta( $menu_item_db_id, '_lt_switcher', true ) && isset( $_POST['lt_switcher_style'][ $menu_item_db_id ] ) ) { $style = sanitize_key( wp_unslash( $_POST['lt_switcher_style'][ $menu_item_db_id ] ) ); update_post_meta( $menu_item_db_id, '_lt_switcher_style', in_array( $style, array( 'theme', 'boxed', 'plain', 'list' ), true ) ? $style : 'theme' ); } if ( ! empty( $_POST['lt_add_switcher'] ) ) $this->create_menu_switcher( $menu_id ); }
	public function ajax_add_menu_switcher() { check_ajax_referer( 'lt_add_menu_switcher', 'nonce' ); if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'lightweight-translation' ) ), 403 ); $menu_id = absint( $_POST['menu_id'] ?? 0 ); if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) wp_send_json_error( array( 'message' => __( 'Select and save a menu first.', 'lightweight-translation' ) ), 400 ); $item_id = $this->create_menu_switcher( $menu_id ); if ( ! $item_id || is_wp_error( $item_id ) ) wp_send_json_error( array( 'message' => __( 'Unable to add the selector.', 'lightweight-translation' ) ), 500 ); wp_send_json_success( array( 'message' => __( 'Language selector added. Reloading…', 'lightweight-translation' ) ) ); }
	public function admin_add_menu_switcher() { check_admin_referer( 'lt_add_menu_switcher' ); if ( ! current_user_can( 'edit_theme_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'lightweight-translation' ) ); $menu_id = absint( $_GET['menu_id'] ?? 0 ); if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) wp_die( esc_html__( 'Select and save a menu first.', 'lightweight-translation' ) ); $item_id = $this->create_menu_switcher( $menu_id ); if ( ! $item_id || is_wp_error( $item_id ) ) wp_die( esc_html__( 'Unable to add the selector.', 'lightweight-translation' ) ); wp_safe_redirect( add_query_arg( array( 'action' => 'edit', 'menu' => $menu_id, 'lt_added' => 1 ), admin_url( 'nav-menus.php' ) ) ); exit; }
	public function render_menu_switcher( $output, $item, $depth, $args ) {
		if ( ! is_object( $item ) || '1' !== get_post_meta( $item->ID, '_lt_switcher', true ) ) return $output;
		self::selector_script();
		$style = $this->menu_switcher_style( $item->ID );
		if ( 'list' === $style ) return self::language_list_markup( $this->current_language() );
		if ( 'theme' === $style ) return $this->menu_switcher_theme_markup();
		// Phone menus (WoodMart mobile menu, off-canvas menus of most themes) open sub-menus with their own arrow,
		// which expects a plain <ul class="sub-menu"> right inside the item: give them exactly that.
		$classes = is_object( $args ) ? (string) ( $args->menu_class ?? '' ) . ' ' . (string) ( $args->container_class ?? '' ) . ' ' . (string) ( $args->theme_location ?? '' ) : '';
		if ( preg_match( '/mobile|mobil|offcanvas|off-canvas|handheld|responsive|drawer/i', $classes ) ) return $this->menu_switcher_mobile_markup();
		return $this->menu_switcher_markup( $style );
	}
	private function menu_switcher_style( $item_id ) { $style = get_post_meta( absint( $item_id ), '_lt_switcher_style', true ); return in_array( $style, array( 'theme', 'boxed', 'plain', 'list' ), true ) ? $style : 'theme'; }
	private function menu_switcher_theme_markup() {
		$show_flag = get_option( 'lt_selector_show_flag', 1 );
		$show_name = get_option( 'lt_selector_show_name', 1 );
		$current   = $this->current_language();
		$name      = $show_name ? self::language_label( $current ) : strtoupper( $current );
		return '<a href="' . esc_url( self::url( $current ) ) . '" aria-current="true" role="button" aria-haspopup="true" class="woodmart-nav-link lt-language-switcher-trigger lt-language-switcher-trigger--theme"><span class="nav-link-text">' . ( $show_flag ? '<span class="wpml-ls-flag">' . esc_html( self::flag( $current ) ) . '</span>' : '' ) . '<span class="wpml-ls-native">' . esc_html( $name ) . '</span></span></a>';
	}
	/** Let the active theme's normal menu walker render the language links as native submenu items. */
	public function add_menu_switcher_languages( $items, $args ) {
		if ( is_admin() || ! is_array( $items ) ) return $items;
		$show_flag = get_option( 'lt_selector_show_flag', 1 );
		$show_name = get_option( 'lt_selector_show_name', 1 );
		$languages = $this->selector_languages();
		foreach ( $items as $parent ) {
			if ( ! is_object( $parent ) || '1' !== get_post_meta( $parent->ID, '_lt_switcher', true ) || 'theme' !== $this->menu_switcher_style( $parent->ID ) ) continue;
			self::selector_script();
			$parent_id = absint( $parent->ID );
			foreach ( $languages as $index => $language ) {
				if ( $language === $this->current_language() ) continue;
				$name = $show_name ? self::language_label( $language ) : strtoupper( $language );
				$id = -1 * ( $parent_id * 1000 + $index + 1 );
				$items[] = (object) array(
					'ID' => $id, 'db_id' => $id, 'menu_item_parent' => (string) $parent_id, 'lt_language' => $language,
					'object_id' => $id, 'object' => 'custom', 'type' => 'custom', 'type_label' => __( 'Custom Link', 'lightweight-translation' ),
					'title' => ( $show_flag ? '<span class="wpml-ls-flag">' . esc_html( self::flag( $language ) ) . '</span> ' : '' ) . esc_html( $name ),
					'url' => self::url( $language ), 'target' => '', 'attr_title' => sprintf( __( 'Switch to %s', 'lightweight-translation' ), $name ),
					'description' => '', 'classes' => array( 'menu-item', 'lt-language-switcher-option' ), 'xfn' => '', 'current' => false,
					'current_item_parent' => false, 'current_item_ancestor' => false, 'post_parent' => 0, 'post_title' => $name, 'post_type' => 'nav_menu_item',
				);
			}
		}
		return $items;
	}
	public function menu_language_link_attributes( $atts, $item, $args, $depth ) {
		if ( is_object( $item ) && ! empty( $item->lt_language ) ) $atts['data-lt-lang'] = sanitize_key( $item->lt_language );
		return $atts;
	}
	private function menu_switcher_mobile_markup() {
		$show_flag = get_option( 'lt_selector_show_flag', 1 );
		$show_name = get_option( 'lt_selector_show_name', 1 );
		$current   = $this->current_language();
		$label     = function( $lang ) use ( $show_flag, $show_name ) { return ( $show_flag ? '<span class="wpml-ls-flag">' . esc_html( self::flag( $lang ) ) . '</span>' : '' ) . '<span class="wpml-ls-native">' . esc_html( $show_name ? self::language_label( $lang ) : strtoupper( $lang ) ) . '</span>'; };
		$out = '<a href="' . esc_url( self::url( $current ) ) . '" class="woodmart-nav-link lt-language-switcher-mobile-trigger"><span class="nav-link-text">' . $label( $current ) . '</span></a><ul class="sub-menu wd-sub-menu lt-language-switcher-mobile">';
		foreach ( $this->selector_languages() as $lang ) {
			if ( $lang === $current ) continue;
			$out .= '<li class="menu-item item-level-1"><a href="' . esc_url( self::url( $lang ) ) . '" data-lt-lang="' . esc_attr( $lang ) . '" class="woodmart-nav-link lt-language-switcher-option"><span class="nav-link-text">' . $label( $lang ) . '</span></a></li>';
		}
		return $out . '</ul>';
	}
	private function menu_switcher_markup( $style = 'boxed' ) { $style = in_array( $style, array( 'boxed', 'plain' ), true ) ? $style : 'boxed'; $show_flag = get_option( 'lt_selector_show_flag', 1 ); $show_name = get_option( 'lt_selector_show_name', 1 ); $current = $this->current_language(); $id = 'lt-language-switcher-' . wp_rand( 1000, 999999 ); $current_name = $show_name ? self::language_label( $current ) : strtoupper( $current ); $out = '<a href="' . esc_url( self::url( $current ) ) . '" class="woodmart-nav-link lt-language-switcher-trigger lt-language-switcher-trigger--' . esc_attr( $style ) . '" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '"><span class="nav-link-text">' . ( $show_flag ? '<span class="wpml-ls-flag">' . esc_html( self::flag( $current ) ) . '</span>' : '' ) . '<span class="wpml-ls-native">' . esc_html( $current_name ) . '</span></span></a><div id="' . esc_attr( $id ) . '" class="color-scheme-dark wd-design-default wd-dropdown-menu wd-dropdown lt-language-switcher-menu lt-language-switcher-menu--' . esc_attr( $style ) . '" aria-hidden="true"><div class="container wd-entry-content"><ul class="wd-sub-menu color-scheme-dark" role="listbox">'; foreach ( $this->selector_languages() as $lang ) { if ( $lang === $current ) continue; $name = $show_name ? self::language_label( $lang ) : strtoupper( $lang ); $out .= '<li class="menu-item item-level-1 wd-event-hover" role="none"><a title="' . esc_attr( sprintf( __( 'Switch to %s', 'lightweight-translation' ), $name ) ) . '" href="' . esc_url( self::url( $lang ) ) . '" data-lt-lang="' . esc_attr( $lang ) . '" aria-label="' . esc_attr( sprintf( __( 'Switch to %s', 'lightweight-translation' ), $name ) ) . '" class="woodmart-nav-link lt-language-switcher-option" role="option">' . ( $show_flag ? '<span class="wpml-ls-flag">' . esc_html( self::flag( $lang ) ) . '</span>' : '' ) . '<span class="wpml-ls-native">' . esc_html( $name ) . '</span></a></li>'; } return $out . '</ul></div></div>'; }
	public function menu_item_classes( $classes, $item, $args, $depth ) { if ( is_object( $item ) && '1' === get_post_meta( $item->ID, '_lt_switcher', true ) ) { if ( ! is_admin() ) self::selector_script(); $style = $this->menu_switcher_style( $item->ID ); $classes = array_merge( $classes, array( 'lt-menu-switcher-item', 'lt-menu-switcher--' . $style ) ); if ( 'theme' === $style || 'plain' === $style || 'boxed' === $style ) $classes[] = 'menu-item-has-children'; if ( 'boxed' !== $style && 'plain' !== $style ) $classes = array_values( array_diff( $classes, array( 'wd-event-click' ) ) ); } return array_unique( $classes ); }
	/** The style is needed before the selector is displayed (head); its script only on pages that show a selector. */
	public function frontend_assets() { wp_enqueue_style( 'lt-frontend', plugins_url( 'assets/css/frontend.css', LT_FILE ), array(), LT_VERSION ); wp_register_script( 'lt-frontend', plugins_url( 'assets/js/frontend.js', LT_FILE ), array(), LT_VERSION, true ); }
	/** Called when a selector is rendered: the script joins the footer, or is printed at once if the footer scripts are already out. */
	private static function selector_script() {
		if ( wp_script_is( 'lt-frontend', 'done' ) || wp_script_is( 'lt-frontend', 'enqueued' ) ) return;
		if ( ! wp_script_is( 'lt-frontend', 'registered' ) ) wp_register_script( 'lt-frontend', plugins_url( 'assets/js/frontend.js', LT_FILE ), array(), LT_VERSION, true );
		if ( did_action( 'wp_print_footer_scripts' ) ) wp_print_scripts( 'lt-frontend' );
		else wp_enqueue_script( 'lt-frontend' );
	}
	public function post_link( $url, $post ) { if ( ! $this->is_secondary() || ! $post || ! $post->ID || ! get_option( 'permalink_structure' ) ) return $url; if ( self::is_front_page_post( $post ) ) return trailingslashit( home_url( $this->current_language() ) ); $path = self::relative_path( $url ); $slug = LT_Translation_Store::translate( $this->current_language(), $post->post_type, $post->ID, 'slug', $post->post_name ); $path = $this->translate_path_bases( self::replace_last_segment( $path, $slug ), $this->current_language() ); return trailingslashit( home_url( $this->current_language() . '/' . $path ) ); }
	public function page_link( $url, $post_id ) { $post = get_post( $post_id ); return $post instanceof WP_Post ? $this->post_link( $url, $post ) : $url; }
	public function term_link( $url, $term, $taxonomy ) { if ( ! $this->is_secondary() || ! $term || ! $term->term_id || ! get_option( 'permalink_structure' ) ) return $url; $path = self::relative_path( $url ); $slug = LT_Translation_Store::translate( $this->current_language(), 'term', $term->term_id, 'slug', $term->slug ); $path = $this->translate_path_bases( self::replace_last_segment( $path, $slug ), $this->current_language() ); return trailingslashit( home_url( $this->current_language() . '/' . $path ) ); }
	private function has_seo_plugin() { return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ); }
	/** WordPress core prints its own canonical: remove it when we print ours (avoids two different canonicals). */
	public function maybe_remove_core_canonical() { if ( get_option( 'lt_seo_tags', 1 ) && ! $this->has_seo_plugin() ) remove_action( 'wp_head', 'rel_canonical' ); }
	public function seo() {
		if ( is_admin() || is_404() || is_search() || ! get_option( 'lt_seo_tags', 1 ) ) return;
		foreach ( $this->languages() as $lang ) if ( ! $this->is_hidden( $lang ) ) echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( self::url( $lang ) ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( self::url( $this->default_language() ) ) . '" />' . "\n";
		if ( ! $this->has_seo_plugin() ) echo '<link rel="canonical" href="' . esc_url( self::url( $this->current_language() ) ) . '" />' . "\n";
	}
	/** <html lang="fr"> on translated pages. */
	public function language_attributes( $output ) { return $this->is_secondary() && ! is_admin() ? preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $this->current_language() ) . '"', $output ) : $output; }
}

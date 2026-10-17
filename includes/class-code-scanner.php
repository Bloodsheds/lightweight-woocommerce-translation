<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scans the PHP code of chosen plugins / theme for translatable texts (__(), _e(), esc_html__(), _x(),
 * _n(), _nx()…) and adds them to "Interface texts", so they can be translated without having to browse
 * every page. Texts used in customer messages (WooCommerce notices, errors, AJAX responses,
 * exceptions, JS messages passed to scripts) are flagged as "messages".
 *
 * The scan runs by small batches (AJAX) so big plugins like WooCommerce never time out.
 */
class LT_Code_Scanner {
	const INDEX      = 'lt_string_index'; // field_key => [d => domain, t => m|l, s => target]
	const BATCH_TIME = 8;                 // seconds per AJAX batch
	const MAX_FILE   = 1500000;           // bytes, bigger files are skipped

	/** Translation functions: name => positions of [text, plural, context, domain] (null = none). */
	const FUNCTIONS = array(
		'__' => array( 0, null, null, 1 ), '_e' => array( 0, null, null, 1 ), 'translate' => array( 0, null, null, 1 ),
		'esc_html__' => array( 0, null, null, 1 ), 'esc_html_e' => array( 0, null, null, 1 ), 'esc_attr__' => array( 0, null, null, 1 ), 'esc_attr_e' => array( 0, null, null, 1 ),
		'_x' => array( 0, null, 1, 2 ), '_ex' => array( 0, null, 1, 2 ), 'esc_html_x' => array( 0, null, 1, 2 ), 'esc_attr_x' => array( 0, null, 1, 2 ),
		'_n' => array( 0, 1, null, 3 ), '_n_noop' => array( 0, 1, null, 2 ), '_nx' => array( 0, 1, 3, 4 ), '_nx_noop' => array( 0, 1, 2, 3 ),
	);
	/** Calls / words that mean "this text is shown to the customer as a message". */
	const MESSAGE_HINTS = '/^(wc_add_notice|wc_print_notice|wc_add_wp_error_notices|wp_send_json|wp_send_json_error|wp_send_json_success|wp_die|add_error|add_notice|wc_print_notices|wc_get_notice|wp_localize_script|wp_add_inline_script|wc_add_to_cart_message|add_to_cart_message)$|exception$|error$|notice$/i';
	const MESSAGE_KEYS  = '/i18n|message|error|notice|alert|confirm|warning|success|fail|invalid|required|empty|added|removed|select|choose|please/i';
	const FRONT_JS      = '/(frontend|wc-blocks-checkout|wc-blocks-components|blocks-checkout|blocks-components|mini-cart|price-format|wc-cart-fragments|add-to-cart|single-product|checkout|cart)[^\/]*\.js$/i';
	const JS_STRING     = '"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'';
	const SKIP_DIRS     = '#/(vendor|node_modules|tests?|\.git|languages|i18n/languages|packages/[^/]+/tests|bin|cli|lib/[^/]*phpunit)/#i';
	const ADMIN_DIRS    = '#/(admin|includes/admin|src/Admin|src/Internal/Admin|wp-admin|backend|dashboard)/#i';

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'wp_ajax_lt_scan_code', array( $this, 'ajax' ) ); }

	/** Plugins and themes that can be scanned: key => [label, dir, domain, active]. */
	public static function targets() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$targets = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir = dirname( $file );
			if ( '.' === $dir || 'lightweight-translation' === $dir ) continue;
			$targets[ 'plugin:' . $dir ] = array( 'label' => $data['Name'], 'dir' => WP_PLUGIN_DIR . '/' . $dir, 'domain' => $data['TextDomain'] ?: $dir, 'active' => is_plugin_active( $file ) );
		}
		$theme = wp_get_theme();
		foreach ( array_filter( array( $theme, $theme->parent() ) ) as $item ) {
			$targets[ 'theme:' . $item->get_stylesheet() ] = array( 'label' => __( 'Theme', 'lightweight-translation' ) . ' ' . $item->get( 'Name' ), 'dir' => $item->get_stylesheet_directory(), 'domain' => $item->get( 'TextDomain' ) ?: $item->get_stylesheet(), 'active' => true );
		}
		uasort( $targets, function( $a, $b ) { return $a['active'] === $b['active'] ? strcasecmp( $a['label'], $b['label'] ) : ( $a['active'] ? -1 : 1 ); } );
		return $targets;
	}

	private static function files( $dir, $skip_admin ) {
		$files = array();
		if ( ! is_dir( $dir ) ) return $files;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$extension = strtolower( $file->getExtension() );
			$relative  = '/' . ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			if ( 'js' === $extension ) {
				// Only front-end bundles (block checkout, cart, mini cart…): texts displayed by JavaScript.
				if ( ! preg_match( self::FRONT_JS, basename( $relative ) ) || preg_match( '/editor|admin|\.asset\./i', $relative ) || $file->getSize() > 4 * self::MAX_FILE ) continue;
			} elseif ( 'php' !== $extension || $file->getSize() > self::MAX_FILE ) {
				continue;
			}
			if ( preg_match( self::SKIP_DIRS, $relative ) || ( $skip_admin && preg_match( self::ADMIN_DIRS, $relative ) ) ) continue;
			$files[] = $file->getPathname();
		}
		sort( $files );
		return $files;
	}

	private static function name_tokens() {
		static $types = null;
		if ( null === $types ) { $types = array( T_STRING ); if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) $types[] = T_NAME_FULLY_QUALIFIED; if ( defined( 'T_NAME_QUALIFIED' ) ) $types[] = T_NAME_QUALIFIED; }
		return $types;
	}

	private static function literal( $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) return null;
		$raw = $token[1];
		if ( "'" === $raw[0] ) return str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), substr( $raw, 1, -1 ) );
		return stripcslashes( substr( $raw, 1, -1 ) );
	}

	/* Audience detection (who sees the text). Admin signals exclude a text, front signals confirm it. */
	const ADMIN_CLASS = '/admin|settings|meta_?box|wizard|install|upgrad|updater|tracker|import|export|(^|_)cli|report|dashboard|onboard|setup|system_?status|list_?table|privacy|tools|debug|logger|inbox|marketplace|(^|_)rest(_|$)|rest_?api|controller|schema|migration|deprecat|customizer/i';
	const ADMIN_FUNC  = '/(^|_)(admin|settings|options_page|meta_?box|metabox|setup|wizard|install|upgrade|cli|schema|columns?|bulk|screen|help|dashboard|report|tools|form_fields|admin_options|register_routes|collection_params|item_schema|privacy|exporter|eraser|plugin_row|action_links|row_meta|activation|deactivation|uninstall|items_per_page|pagination|list_table|screen_option)(_|$)/i';
	const ADMIN_CALLS = '/^(add_menu_page|add_submenu_page|add_options_page|add_management_page|add_settings_field|add_settings_section|add_settings_error|register_setting|add_meta_box|register_post_type|register_taxonomy|register_post_status|register_rest_route|register_block_type|_doing_it_wrong|wc_doing_it_wrong|_deprecated_function|_deprecated_argument|_deprecated_hook|wc_deprecated_function|wc_deprecated_argument|error_log|trigger_error|wc_get_logger|wp_admin_notice|wc_help_tip|wc_back_link|submit_button|settings_fields|do_settings_sections|wp_nonce_ays|add_contextual_help|wc_admin_url)$|cli$/i';
	const ADMIN_KEYS  = '/^(desc|desc_tip|tooltip|help|help_tip|menu_name|all_items|add_new|add_new_item|edit_item|new_item|view_item|view_items|search_items|not_found|not_found_in_trash|parent_item|parent_item_colon|update_item|new_item_name|singular_name|items_list|items_list_navigation|filter_items_list|item_published|item_published_privately|item_updated|item_reverted_to_draft|item_scheduled|featured_image|set_featured_image|remove_featured_image|use_featured_image|insert_into_item|uploaded_to_this_item|archives|separate_items_with_commas|add_or_remove_items|choose_from_most_used|popular_items|back_to_items)$/i';
	const ADMIN_CONTEXT = '/admin|dashboard|screen|list[ _-]?table|pagination|row[ _-]?action|bulk[ _-]?action|screen[ _-]?reader|column/i';
	const ADMIN_UI_TEXT = '/^(number of items per page|items per page|per page|row actions|screen options|select a bulk action)$/i';
	const FRONT_HINTS = '/front|public|cart|checkout|my_?account|shop|single_?product|template|shortcode|widget|store_?api|display|loop|order_?received|thank|mini_?cart|quick_?view|wishlist|compare|add_to_cart|login_form|register_form|lost_password|review|coupon|notice/i';
	const FRONT_CALLS = '/^(wc_add_notice|wc_print_notice|wc_print_notices|wc_add_to_cart_message|wc_get_template|wc_get_template_html|woocommerce_form_field|wp_localize_script|wp_add_inline_script)$/i';
	const FRONT_PATH  = '#/(templates?|template-parts|woocommerce|views/front|public|frontend|shortcodes?|widgets?)/#i';
	const ADMIN_CAPS  = '/^(manage_|edit_(others|plugins|theme|users|shop_)|install_|activate_|delete_|update_|administrator|export|import|list_users)/';

	/**
	 * Extracts translatable texts from PHP source.
	 * @param string $code PHP source.
	 * @param string $path Relative path of the file (for templates detection).
	 * @return array[] [text, plural|null, context|null, domain|null, is_message, audience (front|admin|unknown)]
	 */
	public static function extract( $code, $path = '' ) {
		$tokens = @token_get_all( $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$tokens = array_values( array_filter( $tokens, function( $t ) { return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ); } ) );
		$found  = array();
		$count  = count( $tokens );
		$names  = self::name_tokens();
		$front_file = (bool) preg_match( self::FRONT_PATH, $path );
		$statement_start = 0;
		$depth   = 0;
		$scopes  = array(); // [type, name, depth, admin_guard, front_guard]
		$pending = null;
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			// Scope tracking: which class / function are we in?
			if ( is_array( $t ) && ( T_CLASS === $t[0] || ( defined( 'T_TRAIT' ) && T_TRAIT === $t[0] ) ) && ! ( is_array( $tokens[ $i - 1 ] ?? null ) && T_DOUBLE_COLON === $tokens[ $i - 1 ][0] ) ) {
				$pending = array( 'class', is_array( $tokens[ $i + 1 ] ?? null ) && T_STRING === $tokens[ $i + 1 ][0] ? $tokens[ $i + 1 ][1] : '' );
			} elseif ( is_array( $t ) && T_FUNCTION === $t[0] ) {
				$n = $tokens[ $i + 1 ] ?? null;
				if ( '&' === $n ) $n = $tokens[ $i + 2 ] ?? null;
				$pending = array( 'function', is_array( $n ) && T_STRING === $n[0] ? $n[1] : '' );
			}
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$depth++;
				if ( $pending ) { $scopes[] = array( $pending[0], $pending[1], $depth, false, false ); $pending = null; }
			} elseif ( '}' === $t ) {
				if ( $scopes && end( $scopes )[2] === $depth ) array_pop( $scopes );
				$depth--;
			} elseif ( ';' === $t && $pending && 'function' === $pending[0] ) {
				$pending = null; // abstract / interface method
			}
			if ( in_array( $t, array( ';', '{', '}' ), true ) ) { $statement_start = $i + 1; continue; }
			if ( ! is_array( $t ) || ! in_array( $t[0], $names, true ) || '(' !== ( $tokens[ $i + 1 ] ?? '' ) ) continue;
			$name = ltrim( $t[1], '\\' );
			// Guards inside the current function: is_admin(), current_user_can( 'manage_…' ).
			if ( $scopes && in_array( $name, array( 'is_admin', 'is_network_admin', 'is_super_admin', 'check_admin_referer', 'current_user_can' ), true ) ) {
				$negated = '!' === ( $tokens[ $i - 1 ] ?? '' );
				$cap     = 'current_user_can' === $name ? (string) self::literal( $tokens[ $i + 2 ] ?? null ) : 'admin';
				$top     = count( $scopes ) - 1;
				if ( 'current_user_can' === $name ) {
					// current_user_can( 'manage_…' ) protects admin code, negated or not ( if ( ! … ) return; ).
					if ( preg_match( self::ADMIN_CAPS, $cap ) ) $scopes[ $top ][3] = true;
				} elseif ( $negated ) {
					$scopes[ $top ][4] = true; // if ( ! is_admin() ) → shop code
				} else {
					$scopes[ $top ][3] = true;
				}
				continue;
			}
			if ( ! isset( self::FUNCTIONS[ $name ] ) ) continue;
			$prev = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $prev ) && in_array( $prev[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) continue; // a definition or a method, not the WP function
			// Split arguments at depth 0.
			$args = array( array() ); $level = 0; $j = $i + 2;
			for ( ; $j < $count; $j++ ) {
				$a = $tokens[ $j ];
				if ( in_array( $a, array( '(', '[' ), true ) || ( is_array( $a ) && T_CURLY_OPEN === $a[0] ) ) $level++;
				if ( in_array( $a, array( ')', ']', '}' ), true ) ) { if ( 0 === $level ) break; $level--; }
				if ( ',' === $a && 0 === $level ) { $args[] = array(); continue; }
				$args[ count( $args ) - 1 ][] = $a;
			}
			$map   = self::FUNCTIONS[ $name ];
			$value = function( $position ) use ( $args ) { return ( null !== $position && isset( $args[ $position ] ) && 1 === count( $args[ $position ] ) ) ? self::literal( $args[ $position ][0] ) : null; };
			$text  = $value( $map[0] );
			if ( null === $text || '' === trim( $text ) ) continue;
			$context = $value( $map[2] );
			// Generic WordPress list-table labels often live outside an /admin/ directory and have no admin guard.
			// Their context or exact wording still identifies them as dashboard-only UI, so keep them out of the shop catalog.
			if ( preg_match( self::ADMIN_UI_TEXT, trim( wp_strip_all_tags( $text ) ) ) || ( null !== $context && preg_match( self::ADMIN_CONTEXT, $context ) ) ) { $i = $j; continue; }
			// Nothing to translate: only placeholders / HTML / punctuation ( "%1$s%2$s", "<del>%s</del>" ).
			if ( ! preg_match( '/\p{L}{2,}/u', strip_tags( preg_replace( '/%(\d+\$)?[sdfu]|&[a-z]+;/i', ' ', $text ) ) ) ) { $i = $j; continue; }

			// Signals from the statement around the call.
			$message = false; $admin = false; $front = false;
			for ( $k = $statement_start; $k < min( $count, $j + 1 ); $k++ ) {
				$s = $tokens[ $k ];
				if ( ! is_array( $s ) ) continue;
				if ( T_THROW === $s[0] ) $message = true;
				if ( ! in_array( $s[0], $names, true ) ) continue;
				$called = ltrim( $s[1], '\\' );
				if ( preg_match( self::MESSAGE_HINTS, $called ) ) $message = true;
				if ( preg_match( self::ADMIN_CALLS, $called ) ) $admin = true;
				if ( preg_match( self::FRONT_CALLS, $called ) ) $front = true;
			}
			// Key of the array value: 'i18n_view_cart' => __( … ), 'add_new_item' => __( … ).
			$back = $i - 1;
			while ( $back > $statement_start && ( '(' === $tokens[ $back ] || ( is_array( $tokens[ $back ] ) && in_array( $tokens[ $back ][0], $names, true ) ) ) ) $back--;
			if ( is_array( $tokens[ $back ] ?? null ) && T_DOUBLE_ARROW === $tokens[ $back ][0] ) {
				$key = (string) self::literal( $tokens[ $back - 1 ] ?? null );
				if ( preg_match( self::MESSAGE_KEYS, $key ) ) $message = true;
				if ( 0 === strpos( $key, 'i18n' ) ) $front = true;
				if ( preg_match( self::ADMIN_KEYS, $key ) ) $admin = true;
			}
			// Signals from the enclosing class / functions.
			foreach ( $scopes as $scope ) {
				if ( $scope[3] ) $admin = true;
				if ( $scope[4] ) $front = true;
				if ( '' === $scope[1] ) continue;
				if ( preg_match( 'class' === $scope[0] ? self::ADMIN_CLASS : self::ADMIN_FUNC, $scope[1] ) ) $admin = true;
				if ( preg_match( self::FRONT_HINTS, $scope[1] ) ) $front = true;
			}
			if ( $front_file ) $front = true;
			$explicit_front = $front && ( $front_file || preg_match( '/wc_add_notice|wc_print_notice|wc_add_to_cart_message/i', implode( ' ', array_map( function( $x ) { return is_array( $x ) ? $x[1] : $x; }, array_slice( $tokens, $statement_start, $j - $statement_start + 1 ) ) ) ) );
			$audience = ( $admin && ! $explicit_front ) ? 'admin' : ( $front ? 'front' : 'unknown' );
			$found[] = array( $text, $value( $map[1] ), $context, $value( $map[3] ), $message, $audience );
			$i = $j;
		}
		return $found;
	}

	/**
	 * Deletes the texts of the index matching $filter that have no translation yet (translations are kept).
	 * @return array The cleaned index.
	 */
	public static function purge( $index, $filter ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		$keys  = array_keys( array_filter( $index, $filter, ARRAY_FILTER_USE_BOTH ) ); // $filter( $entry, $field_key )
		foreach ( array_chunk( $keys, 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$kept = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT field_key FROM $table WHERE object_type='string' AND object_id=0 AND status IN ('translated','reviewed') AND translated_text <> '' AND field_key IN ($in)", $chunk ) ); // phpcs:ignore WordPress.DB
			$drop = array_values( array_diff( $chunk, $kept ) );
			if ( ! $drop ) continue;
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $drop ), '%s' ) ) . ')', $drop ) ); // phpcs:ignore WordPress.DB
			foreach ( $drop as $field ) unset( $index[ $field ] );
		}
		return $index;
	}

	/** JavaScript string literal → PHP string. */
	private static function js_literal( $double, $single ) {
		$raw = '' !== $double ? $double : str_replace( array( "\\'", '"' ), array( "'", '\\"' ), $single );
		$out = json_decode( '"' . $raw . '"' );
		return is_string( $out ) ? $out : stripcslashes( $raw );
	}

	/**
	 * Texts translated in JavaScript with wp.i18n: __( 'text', 'domain' ), _x(), _n(), _nx(), also in minified
	 * bundles where calls look like (0,o.__)("text","domain").
	 * @return array[] same format as extract(): [text, plural, context, domain, is_message, audience]
	 */
	public static function extract_js( $code ) {
		$s     = self::JS_STRING;
		$found = array();
		$specs = array(
			'__'  => array( '/(?:^|[^\w$])__\)?\(\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*\)/', array( 0, null, null, 1 ) ),
			'_x'  => array( '/(?:^|[^\w$])_x\)?\(\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*\)/', array( 0, null, 1, 2 ) ),
			'_n'  => array( '/(?:^|[^\w$])_n\)?\(\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*,[^,()]+,\s*(?:' . $s . ')\s*\)/', array( 0, 1, null, 2 ) ),
			'_nx' => array( '/(?:^|[^\w$])_nx\)?\(\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*,[^,()]+,\s*(?:' . $s . ')\s*,\s*(?:' . $s . ')\s*\)/', array( 0, 1, 2, 3 ) ),
		);
		foreach ( $specs as $spec ) {
			if ( ! preg_match_all( $spec[0], $code, $matches, PREG_SET_ORDER ) ) continue;
			foreach ( $matches as $m ) {
				$values = array();
				for ( $k = 1; $k < count( $m ); $k += 2 ) $values[] = self::js_literal( $m[ $k ] ?? '', $m[ $k + 1 ] ?? '' );
				$get  = function( $i ) use ( $values ) { return null === $i ? null : ( $values[ $i ] ?? null ); };
				$text = $get( $spec[1][0] );
				if ( null === $text || ! preg_match( '/\p{L}{2,}/u', strip_tags( preg_replace( '/%(\d+\$)?[sdfu]|&[a-z]+;/i', ' ', $text ) ) ) ) continue;
				$found[] = array( $text, $get( $spec[1][1] ), $get( $spec[1][2] ), $get( $spec[1][3] ), false, 'front' );
			}
		}
		return $found;
	}

	/** Visible text in the site language (what the customer reads before translation). */
	private static function source( $text, $context, $domain, $number = 1 ) {
		if ( null !== $context ) return translate_with_gettext_context( $text, $context, $domain );
		return translate( $text, $domain );
	}

	public function ajax() {
		check_ajax_referer( 'lt_scan_code', 'nonce' );
		if ( ! current_user_can( lt_capability() ) ) wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		$targets  = self::targets();
		$key      = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		$offset   = absint( $_POST['offset'] ?? 0 );
		$mode     = sanitize_key( $_POST['mode'] ?? ( empty( $_POST['messages_only'] ) ? 'all' : 'messages' ) ); // messages | all | strict
		$reset    = ! empty( $_POST['reset'] ) && 0 === $offset;
		if ( ! isset( $targets[ $key ] ) ) wp_send_json_error( array( 'message' => 'Unknown plugin' ), 400 );
		$target = $targets[ $key ];
		$path   = sanitize_key( $_POST['path'] ?? '' ); // optional sub-folder, e.g. "emails" = WooCommerce e-mail texts only
		$files  = self::files( $target['dir'], true );
		if ( $path ) $files = array_values( array_filter( $files, function( $file ) use ( $path, $target ) { return false !== stripos( str_replace( '\\', '/', substr( $file, strlen( $target['dir'] ) ) ), '/' . $path . '/' ); } ) );
		$total  = count( $files );
		$router = LT_Language_Router::instance();
		$langs  = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Read texts in the site language, not the admin user's language.
		$switched = get_locale() !== determine_locale() && switch_to_locale( get_locale() );
		$index    = (array) get_option( self::INDEX, array() );
		$js_map   = (array) get_option( 'lt_js_strings', array() ); // domain => [ msgid (with context) => plural msgid ]
		if ( $reset ) $index = self::purge( $index, function( $entry ) use ( $key ) { return ( $entry['s'] ?? '' ) === $key; } );
		$domains  = array();
		$added    = 0;
		$start    = microtime( true );
		for ( ; $offset < $total && microtime( true ) - $start < self::BATCH_TIME; $offset++ ) {
			$code = file_get_contents( $files[ $offset ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $code || false === strpos( $code, '_' ) ) continue;
			$relative = '/' . ltrim( str_replace( '\\', '/', substr( $files[ $offset ], strlen( $target['dir'] ) ) ), '/' );
			$is_js = '.js' === strtolower( substr( $files[ $offset ], -3 ) );
			foreach ( $is_js ? self::extract_js( $code ) : self::extract( $code, $relative ) as $entry ) {
				list( $text, $plural, $context, $domain, $is_message, $audience ) = $entry;
				if ( 'admin' === $audience ) continue;                                // never shown to customers
				if ( 'messages' === $mode && ! $is_message ) continue;
				if ( 'strict' === $mode && 'front' !== $audience ) continue;
				if ( 'smart' === $mode && 'front' !== $audience && ! ( $is_message && 'unknown' === $audience ) ) continue; // recommended: shop texts + customer messages
				$domain = $domain ?: $target['domain'];
				$domains[ $domain ] = true;
				$forms  = array( $text => 1 );
				if ( null !== $plural ) $forms[ $plural ] = 2;
				foreach ( $forms as $msgid => $number ) {
					$lookup = null !== $context ? $context . "\x04" . $msgid : $msgid;
					$field  = 'string_' . md5( $lookup );
					$source = 2 === $number ? _n( $text, $plural, 2, $domain ) : self::source( $msgid, $context, $domain ); // phpcs:ignore WordPress.WP.I18n
					if ( null !== $context && 2 === $number ) $source = _nx( $text, $plural, 2, $context, $domain ); // phpcs:ignore WordPress.WP.I18n
					foreach ( $langs as $language ) LT_Translation_Store::collect_empty( $language, $field, $source );
					if ( ! isset( $index[ $field ] ) ) $added++;
					// A plugin reusing a WooCommerce / theme text: the text stays listed under WooCommerce / the theme.
					$owner = ( isset( $index[ $field ]['d'] ) && in_array( LT_Sources::group_of( $field, $index[ $field ] ), LT_Sources::BASE, true ) && ! in_array( LT_Sources::group_of( $field, array( 'd' => $domain ) ), LT_Sources::BASE, true ) ) ? $index[ $field ]['d'] : $domain;
					$index[ $field ] = array( 'd' => $owner, 't' => ( $is_message || ! empty( $index[ $field ]['t'] ) && 'm' === $index[ $field ]['t'] ) ? 'm' : 'l', 's' => $key, 'a' => 'front' === $audience ? 'f' : 'u', 'p' => $relative ) + ( ( 'emails' === $path || ! empty( $index[ $field ]['e'] ) ) ? array( 'e' => 1 ) : array() ) + ( ( $is_js || ! empty( $index[ $field ]['j'] ) ) ? array( 'j' => 1 ) : array() ) + ( $index[ $field ] ?? array() );
				}
				if ( $is_js ) $js_map[ $domain ][ null !== $context ? $context . "\x04" . $text : $text ] = (string) $plural; // used to translate JavaScript texts
			}
		}
		if ( $switched ) restore_previous_locale();
		update_option( self::INDEX, $index, false );
		update_option( 'lt_js_strings', $js_map, false );
		// Make sure the scanned text domains are translated on the site.
		$current = LT_Frontend_Filters::domains();
		if ( ! in_array( '*', $current, true ) ) update_option( 'lt_string_domains', implode( ', ', array_unique( array_merge( $current, array_keys( $domains ) ) ) ) );
		LT_Content_Catalog::strings_changed();
		wp_send_json_success( array( 'offset' => $offset, 'total' => $total, 'added' => $added, 'done' => $offset >= $total ) );
	}
}

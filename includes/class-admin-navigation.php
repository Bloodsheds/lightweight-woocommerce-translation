<?php
defined( 'ABSPATH' ) || exit;

/** One navigation model; legacy URLs remain valid, without duplicate menu entries. */
class LT_Admin_Navigation {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'organise' ), 999 );
		add_filter( 'submenu_file', array( $this, 'selected' ) );
		add_filter( 'parent_file', function( $file ) { return self::owns_page() ? 'lt-settings' : $file; } );
		add_filter( 'admin_body_class', function( $classes ) { return self::owns_page() ? $classes . ' lt-app' : $classes; } );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_lt_overview_progress', array( $this, 'overview_progress_ajax' ) );
		add_filter( 'admin_title', function( $title ) { return self::owns_page() && 'lt-settings' !== self::page() ? __( 'Translations', 'lightweight-translation' ) . ' ‹ ' . get_bloginfo( 'name' ) . ' — WordPress' : $title; } );
		add_action( 'in_admin_header', array( $this, 'header' ) );
		add_action( 'admin_head', array( $this, 'hide_entries' ) );
		add_action( 'admin_footer', array( $this, 'back_link' ) );
	}
	public static function page() { return sanitize_key( $_GET['page'] ?? '' ); } // phpcs:ignore WordPress.Security.NonceVerification
	public static function owns_page() { return in_array( self::page(), array( 'lt-settings', 'lt-preferences', 'lt-integrations', 'lt-status', 'lt-strings', 'lt-scan', 'lt-find', 'lt-theme', 'lt-plugins', 'lt-woocommerce', 'lt-emails', 'lt-taxonomies', 'lt-export', 'lt-help', 'lt-translate' ), true ); }
	public static function url( $page, $args = array() ) { return add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) ); }

	public function menu() {
		$cap = lt_capability();
		add_menu_page( __( 'Translations', 'lightweight-translation' ), __( 'Translations', 'lightweight-translation' ), $cap, 'lt-settings', array( $this, 'overview' ), 'dashicons-translation', 56 );
		add_submenu_page( 'lt-settings', __( 'Overview', 'lightweight-translation' ), __( 'Overview', 'lightweight-translation' ), $cap, 'lt-settings', array( $this, 'overview' ) );
		add_submenu_page( 'lt-settings', __( 'Settings', 'lightweight-translation' ), __( 'Settings', 'lightweight-translation' ), $cap, 'lt-preferences', array( LT_Settings_Page::instance(), 'page' ) );
		add_submenu_page( 'lt-settings', __( 'Translate', 'lightweight-translation' ), __( 'Translate', 'lightweight-translation' ), $cap, 'lt-translate', array( LT_Detected_Text::instance(), 'page' ) );
	}
	public function organise() {
		global $submenu;
		// Secondary screens (reached from the five sections). Old links to them stay valid.
		$hidden = array(
			'lt-translate' => array( 'LT_Detected_Text', 'page' ), 'lt-find' => array( 'LT_Find_Text', 'page' ),
			'lt-scan' => array( 'LT_Site_Scan', 'page' ), 'lt-strings' => array( 'LT_String_Translation', 'page' ),
			'lt-theme' => array( 'LT_String_Translation', 'page_theme' ), 'lt-plugins' => array( 'LT_String_Translation', 'page_plugins' ),
			'lt-taxonomies' => array( 'LT_Taxonomy_Translation', 'page' ), 'lt-help' => array( 'LT_Help_Page', 'page' ),
			'lt-integrations' => array( __CLASS__, 'integrations' ), // former tab: its content is now on the overview
		);
		if ( LT_Modules::active( 'woocommerce' ) ) { $hidden['lt-woocommerce'] = array( 'LT_String_Translation', 'page_woocommerce' ); $hidden['lt-emails'] = array( 'LT_Email_Page', 'page' ); }
		// Secondary screens stay in the menu, hidden: WordPress then knows they belong to "Translations",
		// keeps its menu open on them and highlights "Translate" (a page without parent loses both).
		$labels  = self::main();
		$entries = array();
		foreach ( $submenu['lt-settings'] ?? array() as $entry ) $entries[ $entry[2] ] = $entry;
		foreach ( $hidden as $slug => $callback ) {
			if ( isset( $entries[ $slug ] ) ) continue;
			add_submenu_page( 'lt-settings', __( 'Translate', 'lightweight-translation' ), __( 'Translate', 'lightweight-translation' ), lt_capability(), $slug, array( $callback[0]::instance(), $callback[1] ) );
			$added = end( $submenu['lt-settings'] );
			if ( $added && $slug === $added[2] ) $entries[ $slug ] = $added;
		}
		$list = array();
		foreach ( $labels as $slug => $label ) if ( isset( $entries[ $slug ] ) ) { $entries[ $slug ][0] = $label; $list[] = $entries[ $slug ]; }
		foreach ( $hidden as $slug => $callback ) if ( isset( $entries[ $slug ] ) && ! isset( $labels[ $slug ] ) ) { $entries[ $slug ][4] = 'lt-menu-hidden'; $list[] = $entries[ $slug ]; }
		$submenu['lt-settings'] = $list; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		self::$hidden_slugs = array_diff( array_keys( $hidden ), array_keys( $labels ) );
	}

	/** @var string[] Screens kept in the menu but not shown in it. */
	private static $hidden_slugs = array();

	/** Hides the secondary screens in the WordPress menu (class when supported, link otherwise). */
	public function hide_entries() {
		if ( ! self::$hidden_slugs ) return;
		$rules = array( '#adminmenu .lt-menu-hidden' );
		foreach ( self::$hidden_slugs as $slug ) $rules[] = '#adminmenu .wp-submenu li:has(> a[href="admin.php?page=' . $slug . '"])';
		echo '<style>' . implode( ',', $rules ) . '{display:none!important}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	public function selected( $file ) {
		$page = self::page();
		if ( ! self::owns_page() ) return $file;
		if ( in_array( $page, array( 'lt-status', 'lt-strings', 'lt-find', 'lt-taxonomies', 'lt-translate', 'lt-woocommerce', 'lt-theme', 'lt-plugins', 'lt-emails' ), true ) ) return 'lt-status';
		if ( 'lt-integrations' === $page ) return 'lt-settings';
		return 'lt-help' === $page ? 'lt-preferences' : $page;
	}
	public function assets() {
		if ( self::owns_page() ) wp_enqueue_style( 'lt-navigation', plugins_url( 'assets/navigation.css', LT_FILE ), array(), LT_VERSION );
		if ( 'lt-settings' === self::page() ) {
			wp_enqueue_script( 'lt-overview', plugins_url( 'assets/overview.js', LT_FILE ), array(), LT_VERSION, true );
			wp_localize_script( 'lt-overview', 'ltOverview', array(
				'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lt_overview_progress' ),
				'loading' => __( 'Loading progress…', 'lightweight-translation' ),
				'error' => __( 'Progress is temporarily unavailable. You can still continue translating.', 'lightweight-translation' ),
				'summary' => __( '%1$d of %2$d texts translated · %3$d to review', 'lightweight-translation' ),
				'progress' => __( 'Translation progress', 'lightweight-translation' ),
			) );
		}
	}
	public function overview_progress_ajax() {
		check_ajax_referer( 'lt_overview_progress', 'nonce' );
		if ( ! current_user_can( lt_capability() ) ) wp_send_json_error( null, 403 );
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		wp_send_json_success( LT_Content_Catalog::overview_progress( $languages ) );
	}
	/** The five sections, in the order of the work: see where you are, translate, check, exchange files, set up. */
	public static function main() {
		return array(
			'lt-settings'    => __( 'Overview', 'lightweight-translation' ),
			'lt-status'      => __( 'Translate', 'lightweight-translation' ),
			'lt-scan'        => __( 'Check the site', 'lightweight-translation' ),
			'lt-export'      => __( 'Import / Export', 'lightweight-translation' ),
			'lt-preferences' => __( 'Settings', 'lightweight-translation' ),
		);
	}

	/** Where texts come from: one tab each, the same on every translation screen. key => [label, url, short description]. */
	public static function sources() {
		$sources = array(
			'pages' => array( __( 'Pages & posts', 'lightweight-translation' ), self::url( 'lt-status', array( 'source' => 'pages' ) ), __( 'Titles, content and addresses of your pages and posts, and the texts found on your public pages.', 'lightweight-translation' ) ),
			'menus' => array( __( 'Menus', 'lightweight-translation' ), self::url( 'lt-status', array( 'source' => 'menus' ) ), __( 'The links of your navigation menus.', 'lightweight-translation' ) ),
		);
		if ( LT_Modules::active( 'woocommerce' ) ) $sources['woocommerce'] = array( 'WooCommerce', self::url( 'lt-status', array( 'source' => 'woocommerce' ) ), __( 'Products, categories and attributes, then the shop texts: buttons, cart, checkout, payment and shipping methods, e-mails.', 'lightweight-translation' ) );
		$sources['theme']   = array( __( 'Theme', 'lightweight-translation' ), self::url( 'lt-theme' ), __( 'Texts written by your theme, its options (footer, copyright…), widgets and layouts.', 'lightweight-translation' ) );
		$sources['plugins'] = array( __( 'Plugins', 'lightweight-translation' ), self::url( 'lt-plugins' ), __( 'Texts of your other plugins. Texts they reuse from WooCommerce or the theme are translated there, once.', 'lightweight-translation' ) );
		$sources['other']   = array( __( 'Other content', 'lightweight-translation' ), self::url( 'lt-status', array( 'source' => 'other' ) ), __( 'Any other content type of your site.', 'lightweight-translation' ) );
		return $sources;
	}

	public function header() {
		if ( ! self::owns_page() || ! current_user_can( lt_capability() ) ) return;
		$current = $this->selected( '' );
		echo '<nav class="nav-tab-wrapper lt-app-mainnav" aria-label="' . esc_attr__( 'Plugin sections', 'lightweight-translation' ) . '">';
		foreach ( self::main() as $page => $label ) echo '<a class="nav-tab' . ( $page === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $page ) ) . '"' . ( $page === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		echo '</nav>';
		if ( 'lt-status' !== $current ) return;
		// Second level: where the text comes from. A search box finds any text, whatever its source.
		$source = self::translation_source();
		echo '<div class="lt-app-subbar"><nav class="nav-tab-wrapper lt-app-subnav" aria-label="' . esc_attr__( 'Translation sources', 'lightweight-translation' ) . '">';
		foreach ( self::sources() as $key => $entry ) echo '<a class="nav-tab' . ( $key === $source ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $entry[1] ) . '"' . ( $key === $source ? ' aria-current="page"' : '' ) . '>' . esc_html( $entry[0] ) . '</a>';
		echo '</nav><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="lt-subbar-search" role="search"><input type="hidden" name="page" value="lt-find"><input type="search" name="s" aria-label="' . esc_attr__( 'Find a text', 'lightweight-translation' ) . '" placeholder="' . esc_attr__( 'Find a text…', 'lightweight-translation' ) . '"><button class="button">' . esc_html__( 'Search', 'lightweight-translation' ) . '</button></form></div>';
		// Breadcrumb: where you are, each level clickable. Its parent level is also the "Back" link of the left column.
		$crumbs = self::crumbs();
		$back   = count( $crumbs ) > 1 ? $crumbs[ count( $crumbs ) - 2 ] : $crumbs[0];
		echo '<nav class="lt-breadcrumb" aria-label="' . esc_attr__( 'Breadcrumb', 'lightweight-translation' ) . '" data-back="' . esc_url( $back[1] ) . '" data-back-label="' . esc_attr( sprintf( __( 'Back to %s', 'lightweight-translation' ), $back[0] ) ) . '"><ol>';
		foreach ( $crumbs as $i => $crumb ) {
			$last = count( $crumbs ) - 1 === $i;
			echo '<li>' . ( $last || '' === $crumb[1] ? '<span' . ( $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $crumb[0] ) . '</span>' : '<a href="' . esc_url( $crumb[1] ) . '">' . esc_html( $crumb[0] ) . '</a>' ) . '</li>';
		}
		echo '</ol></nav>';
	}

	/** "← Back" at the top of every left column, to the level above (from the breadcrumb). */
	public function back_link() {
		if ( ! self::owns_page() || 'lt-status' !== $this->selected( '' ) ) return;
		echo '<script>(function(){var b=document.querySelector(".lt-breadcrumb");if(!b||!b.dataset.back)return;document.querySelectorAll(".lt-side").forEach(function(side){var a=document.createElement("a");a.className="lt-place lt-back";a.href=b.dataset.back;a.textContent="\u2190 "+b.dataset.backLabel;side.insertBefore(a,side.firstChild);});})();</script>';
	}

	/** Source (tab) of a content type of the Translate screen. */
	public static function group_source( $group ) {
		list( $kind, $name ) = array_pad( explode( ':', (string) $group, 2 ), 2, '' );
		if ( 'menu' === $kind ) return 'menus';
		if ( 'attribute' === $kind || in_array( $name, array( 'product', 'product_variation' ), true ) ) return 'woocommerce';
		if ( 'tax' === $kind ) {
			$taxonomy = get_taxonomy( $name );
			if ( $taxonomy && in_array( 'product', (array) $taxonomy->object_type, true ) ) return 'woocommerce';
			if ( 0 === strpos( $name, 'pa_' ) || 0 === strpos( $name, 'product_' ) ) return 'woocommerce';
			return 'other';
		}
		if ( 'post' === $kind && in_array( $name, array( 'page', 'post' ), true ) ) return 'pages';
		if ( 'post' === $kind && in_array( $name, LT_Content_Catalog::EXTRA_POST_TYPES, true ) && 'portfolio' !== $name ) return 'theme';
		return 'other';
	}

	private static function translation_source() {
		$page = self::page();
		$source = sanitize_key( $_GET['source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $source, array( 'pages', 'menus', 'woocommerce', 'theme', 'plugins', 'other' ), true ) ) return $source;
		if ( 'lt-strings' === $page && '' !== $source ) {
			$source_group = LT_Sources::domains()[ $source ] ?? '';
			if ( 'woocommerce' === $source_group ) return 'woocommerce';
			if ( 'theme' === $source_group ) return 'theme';
			if ( 'other' === $source_group || in_array( $source, array( 'manual', 'unknown' ), true ) ) return 'other';
			return 'plugins';
		}
		if ( in_array( $page, array( 'lt-woocommerce', 'lt-emails' ), true ) || 'lt-taxonomies' === $page && LT_Modules::active( 'woocommerce' ) ) return 'woocommerce';
		if ( 'lt-theme' === $page ) return 'theme';
		if ( 'lt-plugins' === $page ) return 'plugins';
		if ( in_array( $page, array( 'lt-strings', 'lt-find', 'lt-translate' ), true ) ) return 'pages' === sanitize_key( $_GET['view'] ?? '' ) ? 'pages' : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$group = sanitize_text_field( wp_unslash( $_GET['group'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return '' !== $group ? self::group_source( $group ) : 'pages';
	}

	/** Text sections of WooCommerce or of the theme: key => label. */
	private static function text_sections( $source ) {
		return 'woocommerce' === $source
			? array( 'texts' => __( 'Buttons & messages', 'lightweight-translation' ), 'settings' => __( 'Shop settings (payment, shipping…)', 'lightweight-translation' ), 'emails' => __( 'E-mails', 'lightweight-translation' ) )
			: array( 'texts' => __( 'Theme texts', 'lightweight-translation' ), 'settings' => __( 'Theme options (footer, copyright…)', 'lightweight-translation' ), 'widgets' => __( 'Widgets', 'lightweight-translation' ) );
	}

	/** Areas of the WooCommerce / theme texts (cart, checkout, footer…): key => label. */
	private static function areas( $source ) {
		$areas = array();
		if ( 'woocommerce' === $source && class_exists( 'LT_WooCommerce_Text_Groups' ) ) foreach ( LT_WooCommerce_Text_Groups::areas() as $key => $details ) $areas[ $key ] = $details[0];
		elseif ( 'theme' === $source ) $areas = LT_String_Translation::theme_areas();
		return $areas;
	}

	/** Where the current screen is: [label, url] from the overview down to the current list. */
	private static function crumbs() {
		$page   = self::page();
		$source = self::translation_source();
		$get    = function( $key ) { return sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) ); }; // phpcs:ignore WordPress.Security.NonceVerification
		$crumbs = array( array( __( 'Translations', 'lightweight-translation' ), self::url( 'lt-settings' ) ) );
		$sources = self::sources();
		if ( isset( $sources[ $source ] ) ) $crumbs[] = array( $sources[ $source ][0], $sources[ $source ][1] );
		if ( 'lt-status' === $page && '' !== $get( 'group' ) ) {
			$groups = LT_Content_Catalog::groups();
			if ( isset( $groups[ $get( 'group' ) ] ) ) $crumbs[] = array( $groups[ $get( 'group' ) ], self::url( 'lt-status', array( 'group' => $get( 'group' ) ) ) );
		} elseif ( in_array( $page, array( 'lt-woocommerce', 'lt-theme', 'lt-emails' ), true ) ) {
			$sections = self::text_sections( $source );
			$sub      = 'lt-emails' === $page ? 'emails' : sanitize_key( $get( 'sub' ) );
			if ( ! isset( $sections[ $sub ] ) ) $sub = 'texts';
			$crumbs[] = array( $sections[ $sub ], self::url( 'lt-' . $source, 'texts' === $sub ? array() : array( 'sub' => $sub ) ) );
			$area  = sanitize_key( $get( 'woocommerce' === $source ? 'area' : 'theme_area' ) );
			$areas = 'texts' === $sub ? self::areas( $source ) : array();
			if ( isset( $areas[ $area ] ) ) $crumbs[] = array( $areas[ $area ], '' );
		} elseif ( 'lt-plugins' === $page && 0 === strpos( $get( 'group' ), 'plugin:' ) ) {
			$crumbs[] = array( LT_Sources::label( $get( 'group' ) ), '' );
		} elseif ( 'lt-find' === $page ) {
			$crumbs[] = array( __( 'Find a text', 'lightweight-translation' ), '' );
		} elseif ( 'lt-strings' === $page ) {
			$crumbs[] = array( 'pages' === $source ? __( 'Texts found on public pages', 'lightweight-translation' ) : __( 'Texts by source', 'lightweight-translation' ), '' );
		} elseif ( 'lt-taxonomies' === $page ) {
			$crumbs[] = array( __( 'Categories & attributes', 'lightweight-translation' ), '' );
		}
		return $crumbs;
	}

	/**
	 * Left column of WooCommerce and of the theme: their content (products, categories, layouts…) AND their
	 * texts (buttons, options, e-mails…) in one list, the same on every screen of the source.
	 * @param array|null $todo group => [items, todo] to show what is left (Translate screen only).
	 */
	public static function source_sidebar( $source, $todo = null ) {
		$page  = self::page();
		$group = sanitize_text_field( wp_unslash( $_GET['group'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$sub   = sanitize_key( $_GET['sub'] ?? 'texts' ); // phpcs:ignore WordPress.Security.NonceVerification
		$area  = sanitize_key( 'woocommerce' === $source ? ( $_GET['area'] ?? '' ) : ( $_GET['theme_area'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$link  = function( $label, $url, $active, $badge = '', $child = false ) {
			echo '<a class="lt-place' . ( $child ? ' lt-place-child' : '' ) . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '><span>' . esc_html( $label ) . '</span>' . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		// Content types of this source, in the order a shop owner works.
		$content = array();
		foreach ( LT_Content_Catalog::groups() as $key => $label ) if ( 'post:product_variation' !== $key && 'string' !== $key && self::group_source( $key ) === $source ) $content[ $key ] = $label;
		$rank = function( $key ) { $order = array( 'post:product' => 0, 'tax:product_cat' => 1, 'tax:product_tag' => 2, 'attribute' => 3 ); return $order[ $key ] ?? ( 0 === strpos( $key, 'tax:pa_' ) ? 4 : 5 ); };
		uksort( $content, function( $a, $b ) use ( $rank ) { return ( $rank( $a ) - $rank( $b ) ) ?: strcmp( $a, $b ); } );
		if ( null !== $todo ) $content = array_intersect_key( $content, array_filter( $todo, function( $t ) { return ! empty( $t['items'] ); } ) );
		$current_content = 'lt-status' === $page ? $group : '';
		$content_list = function( $title ) use ( $content, $todo, $current_content, $link ) {
			if ( ! $content ) return;
			echo '<h4>' . esc_html( $title ) . '</h4>';
			foreach ( $content as $key => $label ) {
				$badge = '';
				if ( isset( $todo[ $key ] ) ) $badge = $todo[ $key ]['todo'] ? '<span class="lt-count todo">' . esc_html( sprintf( __( '%d to do', 'lightweight-translation' ), $todo[ $key ]['todo'] ) ) . '</span>' : '<span class="lt-count done">✓ ' . esc_html( number_format_i18n( $todo[ $key ]['items'] ) ) . '</span>';
				$link( $label, self::url( 'lt-status', array( 'group' => $key ) ), $key === $current_content, $badge );
			}
		};
		$texts_page = 'woocommerce' === $source ? 'lt-woocommerce' : 'lt-theme';
		$on_texts   = $texts_page === $page || ( 'woocommerce' === $source && 'lt-emails' === $page );
		if ( 'lt-emails' === $page ) $sub = 'emails';
		$sections = self::text_sections( $source );
		$areas    = self::areas( $source );
		$texts_list = function( $title ) use ( $sections, $areas, $texts_page, $on_texts, $sub, $area, $source, $link ) {
			echo '<h4>' . esc_html( $title ) . '</h4>';
			foreach ( $sections as $key => $label ) {
				$active = $on_texts && $key === $sub;
				$link( $label, self::url( $texts_page, 'texts' === $key ? array() : array( 'sub' => $key ) ), $active && '' === $area );
				if ( ! $active || 'texts' !== $key ) continue;
				foreach ( $areas as $area_key => $area_label ) $link( $area_label, self::url( $texts_page, array( 'woocommerce' === $source ? 'area' : 'theme_area' => $area_key ) ), $area === $area_key, '', true );
			}
		};
		if ( 'woocommerce' === $source ) { $content_list( __( 'Catalog', 'lightweight-translation' ) ); $texts_list( __( 'Shop texts', 'lightweight-translation' ) ); }
		else { $texts_list( __( 'Theme texts', 'lightweight-translation' ) ); $content_list( __( 'Layouts & blocks', 'lightweight-translation' ) ); }
	}

	public function overview() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Your site, in every language', 'lightweight-translation' ) . '</h1><p class="lt-intro">' . esc_html__( 'Choose a language, translate your content, then check the result on your site.', 'lightweight-translation' ) . '</p>';
		if ( ! $languages ) {
			echo '<div class="lt-surface"><h2>' . esc_html__( 'Add your first language', 'lightweight-translation' ) . '</h2><p>' . esc_html__( 'Your original content stays in place. Choose the languages you want to offer your visitors.', 'lightweight-translation' ) . '</p><a class="button button-primary" href="' . esc_url( self::url( 'lt-preferences' ) ) . '">' . esc_html__( 'Choose languages', 'lightweight-translation' ) . '</a></div></div>';
			return;
		}
		echo '<div class="lt-dashboard-grid" id="lt-overview-progress">';
		foreach ( $languages as $language ) {
			echo '<section class="lt-surface" data-language="' . esc_attr( $language ) . '"><h2>' . esc_html( LT_Language_Router::flag( $language ) . ' ' . LT_Language_Router::language_label( $language ) ) . '</h2><div class="lt-large-number">—</div><progress max="100" value="0" aria-label="' . esc_attr__( 'Translation progress', 'lightweight-translation' ) . '"></progress><p class="lt-progress-copy" aria-live="polite">' . esc_html__( 'Loading progress…', 'lightweight-translation' ) . '</p><a class="button button-primary" href="' . esc_url( self::url( 'lt-status', array( 'lang' => $language, 'todo' => 1 ) ) ) . '">' . esc_html__( 'Continue translating', 'lightweight-translation' ) . '</a> <a class="button" target="_blank" rel="noopener" href="' . esc_url( home_url( '/' . $language . '/' ) ) . '">' . esc_html__( 'Preview', 'lightweight-translation' ) . '</a></section>';
		}
		echo '</div>';
		// How adding a translation works, in three steps.
		echo '<div class="lt-surface lt-how"><h2>' . esc_html__( 'How to add a translation', 'lightweight-translation' ) . '</h2><ol class="lt-steps">';
		echo '<li><strong>' . esc_html__( 'Choose where the text comes from', 'lightweight-translation' ) . '</strong><span>' . esc_html__( 'Your pages, your menus, WooCommerce, your theme or another plugin. Each source has its own tab in “Translate”.', 'lightweight-translation' ) . '</span></li>';
		echo '<li><strong>' . esc_html__( 'Click a line and type the translation', 'lightweight-translation' ) . '</strong><span>' . esc_html__( 'The original is shown next to the field. Save: the translation is online at once.', 'lightweight-translation' ) . '</span></li>';
		echo '<li><strong>' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</strong><span>' . esc_html__( 'Texts still in the original language are highlighted in red; click one to translate it.', 'lightweight-translation' ) . '</span></li>';
		echo '</ol><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="lt-search-row"><input type="hidden" name="page" value="lt-find"><input type="search" name="s" aria-label="' . esc_attr__( 'Search a text', 'lightweight-translation' ) . '" placeholder="' . esc_attr__( 'You know the text? Search it: a title, button, message…', 'lightweight-translation' ) . '"><button class="button">' . esc_html__( 'Search', 'lightweight-translation' ) . '</button></form></div>';
		// Where texts come from: the same sources as the tabs of "Translate".
		echo '<h2 class="lt-section-title">' . esc_html__( 'Where are your texts?', 'lightweight-translation' ) . '</h2><div class="lt-dashboard-grid">';
		foreach ( self::sources() as $key => $source ) {
			if ( 'other' === $key ) continue;
			$note = '';
			if ( 'theme' === $key ) $note = sprintf( __( 'Active theme: %s.', 'lightweight-translation' ), wp_get_theme()->get( 'Name' ) );
			if ( 'woocommerce' === $key && LT_Modules::active( 'product-bundles' ) ) $note = __( 'Product Bundles detected: component titles and descriptions are translated with their product.', 'lightweight-translation' );
			echo '<a class="lt-surface lt-action-card" href="' . esc_url( $source[1] ) . '"><h2>' . esc_html( $source[0] ) . '</h2><p>' . esc_html( $source[2] ) . '</p>' . ( $note ? '<p class="lt-card-note">' . esc_html( $note ) . '</p>' : '' ) . '<span aria-hidden="true">→</span></a>';
		}
		echo '<a class="lt-surface lt-action-card" href="' . esc_url( self::url( 'lt-scan' ) ) . '"><h2>' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</h2><p>' . esc_html__( 'Not sure where a text comes from? Check the site: it lists what visitors still see in the original language.', 'lightweight-translation' ) . '</p><span aria-hidden="true">→</span></a>';
		echo '</div></div>';
	}

	/** Former "Integrations" tab: old links still open the overview, where every source is listed. */
	public function integrations() { $this->overview(); }
}

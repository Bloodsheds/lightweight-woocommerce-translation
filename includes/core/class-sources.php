<?php
defined( 'ABSPATH' ) || exit;

/**
 * Where an interface text comes from, sorted the way a shop owner thinks about it:
 *  - "woocommerce": WooCommerce itself (shop texts + shop settings: payment, shipping, store notice…);
 *  - "theme": the theme, its parent and its companion plugin (e.g. WoodMart + WoodMart Core), theme options, widgets;
 *  - "plugin:{dir}": every other plugin, one group each;
 *  - "wordpress": WordPress itself (site title, core texts);
 *  - "other": texts added by hand or of unknown origin.
 * A text is ONE key (md5 of its wording), so a plugin that reuses a WooCommerce text shares its translation.
 */
class LT_Sources {
	const BASE = array( 'woocommerce', 'theme' ); // groups whose texts other plugins reuse

	private static $domains;
	private static $options;
	private static $targets;
	private static $theme;

	/** Plugins and themes (cached for the request). */
	private static function targets() { return null !== self::$targets ? self::$targets : ( self::$targets = LT_Code_Scanner::targets() ); }

	/** Theme + parent theme: [name, slugs, text domains]. */
	public static function theme() {
		if ( null !== self::$theme ) return self::$theme;
		$theme = wp_get_theme();
		$items = array_filter( array( $theme->parent(), $theme ) );
		$name  = $items ? (string) reset( $items )->get( 'Name' ) : '';
		$slugs = array(); $domains = array();
		foreach ( $items as $item ) {
			$slugs[]   = strtolower( $item->get_template() );
			$slugs[]   = strtolower( $item->get_stylesheet() );
			$domains[] = (string) ( $item->get( 'TextDomain' ) ?: $item->get_stylesheet() );
		}
		return self::$theme = array( $name, array_values( array_unique( array_filter( $slugs ) ) ), array_values( array_unique( array_filter( $domains ) ) ) );
	}

	/** Group of a plugin folder: WooCommerce, the theme (its companion plugin) or the plugin itself. */
	public static function plugin_group( $dir, $name = '' ) {
		$dir = strtolower( (string) $dir );
		if ( 'woocommerce' === $dir ) return 'woocommerce';
		list( $theme_name, $slugs ) = self::theme();
		foreach ( $slugs as $slug ) if ( $dir === $slug || 0 === strpos( $dir, $slug . '-' ) || 0 === strpos( $dir, $slug . '_' ) ) return 'theme';
		if ( '' !== $theme_name && '' !== $name && 0 === stripos( $name, $theme_name . ' ' ) ) return 'theme';
		return 'plugin:' . $dir;
	}

	/** Text domain => group. */
	public static function domains() {
		if ( null !== self::$domains ) return self::$domains;
		$map = array( 'woocommerce' => 'woocommerce', 'default' => 'wordpress', 'widgets' => 'theme', 'manual' => 'other', 'unknown' => 'other' );
		foreach ( self::theme()[2] as $domain ) $map[ $domain ] = 'theme';
		foreach ( self::targets() as $key => $target ) {
			if ( 0 !== strpos( $key, 'plugin:' ) || isset( $map[ $target['domain'] ] ) ) continue;
			$map[ $target['domain'] ] = self::plugin_group( substr( $key, 7 ), $target['label'] );
		}
		return self::$domains = $map;
	}

	/** Group of a text typed in settings, from its option name. */
	public static function option_group( $option ) {
		$option = (string) $option;
		if ( null === self::$options ) self::$options = LT_Admin_Texts::origins();
		if ( isset( self::$options[ $option ] ) ) return self::$options[ $option ];
		if ( in_array( $option, array( 'blogname', 'blogdescription' ), true ) ) return 'wordpress';
		if ( 0 === strpos( $option, 'woocommerce' ) || 0 === strpos( $option, 'wc_' ) ) return 'woocommerce';
		if ( 0 === strpos( $option, 'theme_mods_' ) || 0 === strpos( $option, 'xts-' ) ) return 'theme';
		foreach ( self::theme()[1] as $slug ) if ( 0 === stripos( $option, $slug ) ) return 'theme';
		return 'other';
	}

	/** Group of one text of the index (null entry = not in the index). */
	public static function group_of( $field, $entry ) {
		if ( 0 === strpos( (string) $field, 'text_' ) || ! is_array( $entry ) ) return 'other';
		$domain = (string) ( $entry['d'] ?? '' );
		if ( 'site' === $domain ) return self::option_group( (string) strtok( (string) ( $entry['p'] ?? '' ), ' ' ) );
		if ( '' === $domain ) return 'other';
		$map = self::domains();
		return $map[ $domain ] ?? 'plugin:' . sanitize_key( $domain );
	}

	/** Part of a group a text belongs to: "settings" (typed in settings), "widgets" or "texts" (shown by the code). */
	public static function part_of( $entry ) {
		$domain = is_array( $entry ) ? (string) ( $entry['d'] ?? '' ) : '';
		return 'site' === $domain ? 'settings' : ( 'widgets' === $domain ? 'widgets' : 'texts' );
	}

	public static function label( $group ) {
		if ( 'woocommerce' === $group ) return 'WooCommerce';
		if ( 'theme' === $group ) return __( 'Theme', 'lightweight-translation' );
		if ( 'wordpress' === $group ) return 'WordPress';
		if ( 'other' === $group ) return __( 'Added by hand / unknown origin', 'lightweight-translation' );
		$dir = substr( (string) $group, 7 );
		$targets = self::targets();
		return isset( $targets[ 'plugin:' . $dir ] ) ? $targets[ 'plugin:' . $dir ]['label'] : $dir;
	}

	/** Is the plugin of a group switched on? (inactive plugins are listed last, dimmed) */
	public static function active( $group ) {
		if ( 'woocommerce' === $group ) return LT_Modules::active( 'woocommerce' );
		if ( 0 !== strpos( (string) $group, 'plugin:' ) ) return true;
		$targets = self::targets();
		return ! isset( $targets[ $group ] ) || $targets[ $group ]['active'];
	}

	/* ---------- Admin: sections ---------- */

	/** Admin page of a group. */
	public static function url( $group, $args = array() ) {
		$page = ( 'woocommerce' === $group && function_exists( 'WC' ) ) ? 'lt-woocommerce' : ( 'theme' === $group ? 'lt-theme' : 'lt-plugins' );
		if ( 'lt-plugins' === $page ) $args = array( 'group' => $group ) + $args; // WooCommerce switched off: its old texts are listed like a plugin
		return add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) );
	}

	/** Tabs of a section: key => [label, url]. */
	public static function tabs( $section ) {
		$url = function( $page, $args = array() ) { return add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) ); };
		if ( 'woocommerce' === $section ) return array(
			'texts'    => array( __( 'Shop texts', 'lightweight-translation' ), $url( 'lt-woocommerce' ) ),
			'settings' => array( __( 'Shop settings (payment, shipping…)', 'lightweight-translation' ), $url( 'lt-woocommerce', array( 'sub' => 'settings' ) ) ),
			'products' => array( __( 'Products', 'lightweight-translation' ), $url( 'lt-status', array( 'group' => 'post:product' ) ) ),
			'terms'    => array( __( 'Categories & attributes', 'lightweight-translation' ), $url( 'lt-taxonomies' ) ),
			'emails'   => array( __( 'E-mails', 'lightweight-translation' ), $url( 'lt-woocommerce', array( 'sub' => 'emails' ) ) ),
		);
		if ( 'theme' === $section ) return array(
			'texts'    => array( __( 'Theme texts', 'lightweight-translation' ), $url( 'lt-theme' ) ),
			'settings' => array( __( 'Theme options (footer, copyright…)', 'lightweight-translation' ), $url( 'lt-theme', array( 'sub' => 'settings' ) ) ),
			'widgets'  => array( __( 'Widgets', 'lightweight-translation' ), $url( 'lt-theme', array( 'sub' => 'widgets' ) ) ),
			'content'  => array( __( 'Theme layouts & content', 'lightweight-translation' ), $url( 'lt-status', array( 'source' => 'theme' ) ) ),
		);
		return array();
	}

	/** Title and tabs of a section, shared by its pages (E-mails and Categories are WooCommerce tabs). */
	public static function header( $section, $current ) {
		$titles = array( 'woocommerce' => 'WooCommerce', 'theme' => self::label( 'theme' ), 'plugins' => __( 'Plugins', 'lightweight-translation' ) );
		echo '<h1>' . esc_html( $titles[ $section ] ?? '' ) . '</h1>';
		$tabs = self::tabs( $section );
		if ( ! $tabs ) return;
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';
		foreach ( $tabs as $key => list( $label, $link ) ) echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
		echo '</nav>';
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/** Public account navigation: WooCommerce labels plus labels overridden/added by the theme or extensions. */
class LT_Account_Translations {
	private static $originals;
	public static function boot() {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 1000 );
		add_action( 'lt_language_request_parsed', array( __CLASS__, 'endpoints' ) );
	}
	/** WC's priority-zero parser ran before LT recovered the endpoint from the translated page path. */
	public static function endpoints( $request ) {
		if ( ! function_exists( 'WC' ) || ! WC()->query || ! is_callable( array( WC()->query, 'get_query_vars' ) ) ) return;
		foreach ( WC()->query->get_query_vars() as $key => $slug ) {
			if ( $slug && isset( $request->query_vars[ $slug ] ) && ! isset( $request->query_vars[ $key ] ) ) $request->query_vars[ $key ] = $request->query_vars[ $slug ];
		}
	}
	private static function originals( $refresh = false ) {
		if ( null === self::$originals || $refresh ) self::$originals = LT_Email_Translations::with_original( function() {
			$labels = array();
			foreach ( array( 'Dashboard', 'Orders', 'Downloads', 'Payment methods', 'Account details', 'Log out' ) as $label ) $labels[ $label ] = translate( $label, 'woocommerce' );
			$labels['Address'] = _n( 'Address', 'Addresses', 1, 'woocommerce' );
			$labels['Addresses'] = _n( 'Address', 'Addresses', 2, 'woocommerce' );
			return array( 'labels' => $labels, 'menu' => function_exists( 'wc_get_account_menu_items' ) ? wc_get_account_menu_items() : array() );
		} );
		return self::$originals;
	}
	private static function field( $endpoint, $source, $labels ) {
		$native = array_search( $source, $labels, true );
		return false === $native ? 'string_' . md5( 'account_menu:' . $endpoint . ':' . $source ) : LT_String_Keys::field( 'woocommerce', $native );
	}
	/** Collected without browsing a private customer page, in the existing Customer account / CSV section. */
	public static function entries() {
		$originals = self::originals( true );
		$out = array();
		foreach ( $originals['labels'] as $msgid => $source ) $out[ LT_String_Keys::field( 'woocommerce', $msgid ) ] = array( 'source' => $source, 'p' => 'includes/wc-account-functions.php', 'wc_areas' => array( 'account' ), 'legacy' => LT_String_Keys::legacy( $msgid ) );
		foreach ( $originals['menu'] as $endpoint => $source ) {
			if ( ! is_string( $source ) || '' === trim( $source ) ) continue;
			$field = self::field( $endpoint, $source, $originals['labels'] );
			$out[ $field ] = array( 'source' => $source, 'p' => 'My account navigation / ' . $endpoint, 'wc_areas' => array( 'account' ) );
			if ( false !== ( $msgid = array_search( $source, $originals['labels'], true ) ) ) $out[ $field ]['legacy'] = LT_String_Keys::legacy( $msgid );
		}
		return $out;
	}
	public static function menu( $items ) {
		if ( LT_Email_Translations::reading_original() || ( is_admin() && ! wp_doing_ajax() ) || ! is_array( $items ) || ! LT_Language_Router::instance()->is_secondary() ) return $items;
		$language = LT_Language_Router::instance()->current_language();
		$originals = self::originals();
		foreach ( $items as $endpoint => $label ) {
			$source = $originals['menu'][ $endpoint ] ?? $label;
			if ( ! is_string( $source ) ) continue;
			$value = LT_Translation_Store::string_lookup( $language, self::field( $endpoint, $source, $originals['labels'] ), $source );
			if ( null === $value && false !== ( $msgid = array_search( $source, $originals['labels'], true ) ) ) $value = LT_Translation_Store::string_lookup( $language, LT_String_Keys::legacy( $msgid ), $source );
			if ( null === $value ) $value = LT_Translation_Store::by_text( $language, $source );
			// WoodMart uses "Logout" in place of WooCommerce's "Log out".
			if ( null === $value && 'customer-logout' === $endpoint ) $value = LT_Translation_Store::string_lookup( $language, 'string_' . md5( 'Log out' ), $originals['labels']['Log out'] );
			if ( null !== $value ) $items[ $endpoint ] = $value;
		}
		return $items;
	}
}

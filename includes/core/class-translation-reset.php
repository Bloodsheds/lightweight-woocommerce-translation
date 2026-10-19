<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reset the translations of one item (page, product, category…) for one language or all,
 * and keep page caches in sync: when a page or its translation changes, every language
 * version (/fr/…, /en/…) is purged, not only the original address.
 */
class LT_Translation_Reset {
	/**
	 * Interface texts, terms, slugs and theme blocks appear on every page, so saving them clears the whole page
	 * cache. A translation session saves many of them: one complete purge is done 5 minutes after the last save
	 * (15 minutes at most after the first one) instead of one per save. Logged-in editors see changes at once.
	 */
	const PURGE_HOOK  = 'lt_delayed_cache_purge';
	const PURGE_STATE = 'lt_cache_purge_due'; // [ first => time of the first waiting save, due => time of the purge ]
	const PURGE_DELAY = 300;
	const PURGE_MAX   = 900;
	private static $instance;
	private $global = false;
	private $queue = array(); // post IDs whose language URLs must be purged at the end of the request
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'admin_post_lt_reset_translation', array( $this, 'handle' ) );
		add_action( 'admin_post_lt_purge_cache', array( $this, 'handle_purge_all' ) );
		add_action( 'save_post', array( $this, 'queue_post' ), 99 );
		add_action( 'lt_translation_saved', array( $this, 'translation_saved' ) );
		add_action( 'shutdown', array( $this, 'purge_queue' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_all' ) );
		add_action( 'admin_init', array( __CLASS__, 'purge_if_overdue' ) ); // WP-Cron may not run on a quiet site
	}

	public function notice() {
		if ( ! isset( $_GET['lt_reset'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Translation reset: %d stored texts deleted. The page cache of every language was cleared.', 'lightweight-translation' ), absint( $_GET['lt_reset'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ---------- Reset ---------- */

	/** Deletes the stored translations of an item ($language '' = every language). */
	public static function reset( $type, $id, $language = '' ) {
		global $wpdb;
		$table = LT_Translation_Store::table();
		$count = $language
			? $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE object_type=%s AND object_id=%d AND language_code=%s", $type, $id, $language ) ) // phpcs:ignore WordPress.DB
			: $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE object_type=%s AND object_id=%d", $type, $id ) ); // phpcs:ignore WordPress.DB
		LT_Content_Catalog::changed_object( $type, $id );
		LT_Multilingual_Search::invalidate();
		if ( 'term' !== $type && 'attribute' !== $type ) self::instance()->queue_post( $id );
		return (int) $count;
	}

	/** Link to the reset action (with confirmation handled by the caller). */
	public static function url( $type, $id, $language = '', $redirect = '' ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'lt_reset_translation', 'type' => $type, 'id' => absint( $id ), 'lang' => $language, 'redirect' => rawurlencode( $redirect ?: wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ), admin_url( 'admin-post.php' ) ), 'lt_reset_translation' );
	}

	/** Small "Reset" links (one per language + all), for any admin screen. */
	public static function buttons( $type, $id ) {
		$router = LT_Language_Router::instance();
		$html   = '<span class="lt-reset-links">' . esc_html__( 'Reset translation:', 'lightweight-translation' ) . ' ';
		$confirm = esc_attr( wp_json_encode( __( 'Delete this translation? The original is not touched. This cannot be undone.', 'lightweight-translation' ) ) );
		foreach ( $router->languages() as $language ) {
			if ( $language === $router->default_language() ) continue;
			$html .= '<a href="' . esc_url( self::url( $type, $id, $language ) ) . '" onclick="return confirm(' . $confirm . ')">' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</a> · ';
		}
		return $html . '<a href="' . esc_url( self::url( $type, $id ) ) . '" onclick="return confirm(' . $confirm . ')" style="color:#b32d2e">' . esc_html__( 'all languages', 'lightweight-translation' ) . '</a></span>';
	}

	private function can_reset( $type, $id ) {
		if ( 'term' === $type ) { $term = get_term( $id ); $tax = $term instanceof WP_Term ? get_taxonomy( $term->taxonomy ) : null; return $tax && current_user_can( $tax->cap->edit_terms ); }
		if ( 'attribute' === $type ) return current_user_can( 'manage_product_terms' );
		if ( in_array( $type, array( 'string', 'email' ), true ) ) return current_user_can( lt_capability() );
		$post = get_post( $id );
		return $post && $post->post_type === $type && current_user_can( 'edit_post', $id );
	}

	public function handle() {
		check_admin_referer( 'lt_reset_translation' );
		$type     = sanitize_key( $_GET['type'] ?? '' );
		$id       = absint( $_GET['id'] ?? 0 );
		$language = sanitize_key( $_GET['lang'] ?? '' );
		$router   = LT_Language_Router::instance();
		if ( ! $type || ! $id || ! $this->can_reset( $type, $id ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		if ( $language && ( $language === $router->default_language() || ! in_array( $language, $router->languages(), true ) ) ) wp_die( esc_html__( 'Invalid language.', 'lightweight-translation' ) );
		$count    = self::reset( $type, $id, $language );
		$redirect = wp_validate_redirect( rawurldecode( wp_unslash( $_GET['redirect'] ?? '' ) ), admin_url() );
		wp_safe_redirect( add_query_arg( 'lt_reset', $count, $redirect ) );
		exit;
	}

	/* ---------- Page cache ---------- */

	public function queue_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id && ! wp_is_post_revision( $post_id ) ) { $this->queue[ $post_id ] = true; if ( in_array( get_post_type( $post_id ), array( 'wp_block', 'cms_block', 'woodmart_layout', 'wd_popup', 'wd_floating_block' ), true ) ) $this->global = true; }
	}

	public function translation_saved( $row ) {
		if ( in_array( $row['object_type'] ?? '', array( 'string', 'term', 'attribute' ), true ) || 'slug' === ( $row['field_key'] ?? '' ) ) $this->global = true;
		if ( ! empty( $row['object_id'] ) && ! in_array( $row['object_type'] ?? '', array( 'term', 'attribute', 'string', 'email' ), true ) ) $this->queue_post( $row['object_id'] );
	}

	/** Every language address of a post (original + /fr/… with translated slug). */
	public static function post_urls( $post_id ) {
		$router = LT_Language_Router::instance();
		$urls   = array();
		foreach ( $router->languages() as $language ) {
			$url = $router->with_language( $language, function() use ( $post_id ) { return get_permalink( $post_id ); } );
			if ( $url ) $urls[] = $url;
			if ( $language !== $router->default_language() ) $urls[] = trailingslashit( home_url( $language ) ); // home pages list content too
		}
		return array_values( array_unique( $urls ) );
	}

	public static function purge_urls( $urls ) {
		$urls = array_values( array_unique( array_filter( (array) $urls ) ) );
		if ( ! $urls ) return;
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url );                                   // LiteSpeed Cache (Hostinger)
			if ( function_exists( 'w3tc_flush_url' ) ) w3tc_flush_url( $url );          // W3 Total Cache
			if ( function_exists( 'wpsc_delete_url_cache' ) ) wpsc_delete_url_cache( $url ); // WP Super Cache
		}
		if ( function_exists( 'rocket_clean_files' ) ) rocket_clean_files( $urls );     // WP Rocket
		do_action( 'lt_purge_urls', $urls );
	}

	public static function purge_all() {
		// A complete purge also serves any purge still waiting.
		if ( false !== get_option( self::PURGE_STATE, false ) ) delete_option( self::PURGE_STATE );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
		if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
		if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
		do_action( 'lt_purge_all' );
	}

	/** Delays the complete purge after a save: each new save moves it back, within PURGE_MAX of the first one. */
	public static function schedule_purge_all() {
		$now   = time();
		$state = (array) get_option( self::PURGE_STATE, array() );
		$first = isset( $state['first'] ) ? (int) $state['first'] : $now;
		$due   = min( $now + self::PURGE_DELAY, $first + self::PURGE_MAX );
		update_option( self::PURGE_STATE, array( 'first' => $first, 'due' => $due ), true );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
		wp_schedule_single_event( $due, self::PURGE_HOOK );
	}

	public static function purge_if_overdue() {
		$state = get_option( self::PURGE_STATE, false );
		if ( is_array( $state ) && time() >= (int) ( $state['due'] ?? 0 ) ) self::purge_all();
	}

	public function purge_queue() {
		// The saved page itself is purged at once, even when a complete purge is also waiting.
		if ( $this->global ) { $this->global = false; self::schedule_purge_all(); }
		if ( ! $this->queue ) return;
		$urls = array();
		foreach ( array_keys( $this->queue ) as $post_id ) $urls = array_merge( $urls, self::post_urls( $post_id ) );
		$this->queue = array();
		self::purge_urls( $urls );
	}

	public function handle_purge_all() {
		if ( ! current_user_can( lt_capability() ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		check_admin_referer( 'lt_tools' );
		self::purge_all();
		wp_safe_redirect( add_query_arg( array( 'page' => 'lt-settings', 'lt_notice' => rawurlencode( __( 'Page cache cleared (all languages).', 'lightweight-translation' ) ) ), admin_url( 'admin.php' ) ) . '#tools' );
		exit;
	}
}

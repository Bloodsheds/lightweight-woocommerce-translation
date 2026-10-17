<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce e-mails in the customer's language.
 *
 * - The language is stored on the order at checkout (_lt_language) and on the customer account
 *   (user meta lt_language) at registration / login, so account e-mails are translated too.
 * - Only customer e-mails are translated: e-mails sent to the shop (New order, Cancelled, Failed…) stay in
 *   the original language.
 * - Subject, heading and additional content: translated from their template (before {placeholders} are
 *   replaced), per e-mail type: email_subject_{id}, email_heading_{id}, email_additional_content_{id}.
 * - Body texts (greeting, intro, table labels, footer): WooCommerce texts, translated in "Interface texts",
 *   active for the whole e-mail (from the first option read until the e-mail is sent).
 */
class LT_Email_Translations {
	const TEMPLATE_KEYS = '/^(subject|heading|additional_content)(_[a-z_]+)?$/';

	private static $instance;
	private $language = '';
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_language' ) );
		add_action( 'user_register', array( $this, 'save_user_language' ) );
		add_action( 'wp_login', array( $this, 'save_user_language_on_login' ), 10, 2 );
		add_filter( 'woocommerce_email_get_option', array( $this, 'email_option' ), 20, 4 );
		add_action( 'woocommerce_email_sent', array( $this, 'leave' ) );
		add_filter( 'gettext', array( $this, 'gettext' ), 30, 3 );
		add_filter( 'ngettext', array( $this, 'ngettext' ), 30, 5 );
		add_filter( 'gettext_with_context', array( $this, 'gettext_with_context' ), 30, 4 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'item_name' ), 20, 3 );
	}

	/** Language of the visitor: the page language, or the cookie for AJAX checkouts / login forms. */
	public static function visitor_language() {
		$router = LT_Language_Router::instance();
		if ( $router->is_secondary() ) return $router->current_language();
		$cookie = isset( $_COOKIE['lt_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['lt_lang'] ) ) : '';
		return in_array( $cookie, $router->languages(), true ) ? $cookie : $router->default_language();
	}

	public function save_language( $order ) { if ( is_object( $order ) && is_callable( array( $order, 'update_meta_data' ) ) ) $order->update_meta_data( '_lt_language', self::visitor_language() ); }
	public function save_user_language( $user_id ) { if ( ! is_admin() || wp_doing_ajax() ) update_user_meta( $user_id, 'lt_language', self::visitor_language() ); }
	public function save_user_language_on_login( $login, $user = null ) { if ( $user instanceof WP_User && ( ! is_admin() || wp_doing_ajax() ) ) update_user_meta( $user->ID, 'lt_language', self::visitor_language() ); }

	/** Language in which an e-mail must be written ('' = original language). */
	public static function language_for( $email ) {
		if ( ! is_object( $email ) || ! is_callable( array( $email, 'is_customer_email' ) ) || ! $email->is_customer_email() ) return '';
		$object = $email->object ?? null;
		$lang   = '';
		if ( is_object( $object ) && is_callable( array( $object, 'get_meta' ) ) ) {
			$lang = (string) $object->get_meta( '_lt_language' );
			if ( ! $lang && is_callable( array( $object, 'get_parent_id' ) ) && $object->get_parent_id() && function_exists( 'wc_get_order' ) ) { $parent = wc_get_order( $object->get_parent_id() ); $lang = $parent ? (string) $parent->get_meta( '_lt_language' ) : ''; } // refunds
			if ( ! $lang && is_callable( array( $object, 'get_customer_id' ) ) && $object->get_customer_id() ) $lang = (string) get_user_meta( $object->get_customer_id(), 'lt_language', true );
		} elseif ( $object instanceof WP_User ) {
			$lang = (string) get_user_meta( $object->ID, 'lt_language', true );
		} elseif ( ! empty( $email->user_id ) ) {
			$lang = (string) get_user_meta( (int) $email->user_id, 'lt_language', true ); // reset password, new account
		}
		$router = LT_Language_Router::instance();
		$lang   = sanitize_key( $lang );
		return ( $lang && $lang !== $router->default_language() && in_array( $lang, $router->languages(), true ) ) ? $lang : '';
	}

	/** Every option read by an e-mail tells us which e-mail is being prepared: open its language "window". */
	public function email_option( $value, $email = null, $raw = null, $key = '' ) {
		if ( ! is_object( $email ) || empty( $email->id ) ) return $value;
		$lang = self::language_for( $email );
		$this->language = $lang;
		if ( ! $lang || ! preg_match( self::TEMPLATE_KEYS, (string) $key ) ) return $value;
		$source = (string) $value;
		if ( '' === trim( $source ) ) {
			// Empty setting = WooCommerce default text: translate that default (read in the original language).
			$this->language = '';
			$source = self::default_text( $email, $key );
			$this->language = $lang;
			if ( '' === $source ) return $value;
		}
		$translated = LT_Translation_Store::translate( $lang, 'email', 0, 'email_' . $key . '_' . sanitize_key( $email->id ), $source );
		return $translated !== $source ? $translated : $value;
	}

	public function leave() { $this->language = ''; }

	/** Default WooCommerce text of an e-mail setting (subject, heading, additional content, subject_paid…). */
	public static function default_text( $email, $key ) {
		$method = 'get_default_' . $key;
		if ( method_exists( $email, $method ) ) return (string) $email->$method();
		if ( preg_match( '/^(subject|heading)_(\w+)$/', $key, $m ) && method_exists( $email, 'get_default_' . $m[1] ) ) {
			try { $ref = new ReflectionMethod( $email, 'get_default_' . $m[1] ); if ( $ref->getNumberOfParameters() > 0 ) return (string) $email->{'get_default_' . $m[1]}( in_array( $m[2], array( 'paid', 'partial' ), true ) ); } catch ( Throwable $e ) {} // e.g. subject_paid / subject_full
		}
		return (string) ( $email->form_fields[ $key ]['placeholder'] ?? '' );
	}

	/** Translatable template settings of an e-mail: key => label. */
	public static function template_fields( $email ) {
		$fields = array();
		foreach ( (array) ( $email->form_fields ?? array() ) as $key => $field ) {
			if ( preg_match( self::TEMPLATE_KEYS, (string) $key ) && in_array( $field['type'] ?? 'text', array( 'text', 'textarea' ), true ) ) $fields[ $key ] = wp_strip_all_tags( (string) ( $field['title'] ?? $key ) );
		}
		return $fields;
	}

	public function gettext( $translation, $text, $domain ) { if ( ! $this->language || ! $text || ! LT_Frontend_Filters::domain_allowed( $domain ) ) return $translation; return LT_Frontend_Filters::lookup_string( $this->language, $translation, $text ); }
	public function ngettext( $translation, $single, $plural, $number, $domain ) { return $this->gettext( $translation, 1 === (int) $number ? $single : $plural, $domain ); }
	public function gettext_with_context( $translation, $text, $context, $domain ) { return $this->gettext( $translation, $context . "\x04" . $text, $domain ); }

	/** Product names in order lines: e-mail language, or the page language (order received, my account). */
	public function item_name( $name, $item, $is_plain = false ) {
		$lang = $this->language;
		if ( ! $lang ) { $router = LT_Language_Router::instance(); if ( ! is_admin() && $router->is_secondary() ) $lang = $router->current_language(); }
		if ( ! $lang || ! is_object( $item ) || ! is_callable( array( $item, 'get_product' ) ) ) return $name;
		$product = $item->get_product();
		if ( ! $product || ! is_callable( array( $product, 'get_id' ) ) ) return $name;
		// Variations ("T-shirt - Blue") are named after their parent product: translate the parent title.
		if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) && function_exists( 'wc_get_product' ) ) $product = wc_get_product( $product->get_parent_id() );
		if ( ! $product ) return $name;
		$source     = $product->get_name( 'edit' );
		$translated = LT_Translation_Store::translate( $lang, 'product', $product->get_id(), 'title', $source );
		return ( $translated !== $source && '' !== $source ) ? str_replace( $source, $translated, $name ) : $name;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce e-mails in the customer's language.
 *
 * - The language is stored on the order at checkout (_lt_language) and on the customer account
 *   (user meta lt_language) at registration or first login, then changed only by the customer or an editor.
 * - Only customer e-mails are translated: e-mails sent to the shop (New order, Cancelled, Failed…) stay in
 *   the original language.
 * - Subject, heading and additional content: translated from their template (before {placeholders} are
 *   replaced), per e-mail type: email_subject_{id}, email_heading_{id}, email_additional_content_{id}.
 * - Body texts (greeting, intro, table labels, footer): WooCommerce texts, translated in "Interface texts",
 *   active for the whole e-mail (from the first option read until the e-mail is sent).
 */
class LT_Email_Translations {
	const TEMPLATE_KEYS = '/^(subject|heading|additional_content|preheader)(_[a-z0-9_]+)?$/';

	private static $instance;
	private $language = '';
	private $email_context;
	private $date_placeholder;
	private $date_email;
	private $date_order;
	private $date_language;
	private $refreshing_date = false;
	private static $reading_original = false;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_language' ) );
		add_action( 'user_register', array( $this, 'save_user_language' ) );
		add_action( 'wp_login', array( $this, 'save_user_language_on_login' ), 10, 2 );
		add_action( 'wp', array( $this, 'save_explicit_language_choice' ) );
		add_action( 'show_user_profile', array( $this, 'profile_language_field' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_language_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_language' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_language' ) );
		add_action( 'woocommerce_edit_account_form_fields', array( $this, 'account_language_field' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_account_language' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_language' ) );
		add_filter( 'woocommerce_allow_switching_email_locale', array( $this, 'begin_email' ), 20, 2 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'order_table' ), 1, 4 );
		add_filter( 'woocommerce_email_get_option', array( $this, 'email_option' ), 20, 4 );
		add_action( 'woocommerce_email_sent', array( $this, 'leave' ) );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( $this, 'restore_email' ), 20, 2 );
		add_filter( 'option_woocommerce_email_footer_text', array( $this, 'footer' ), 30 );
		add_filter( 'gettext', array( $this, 'gettext' ), 30, 3 );
		add_filter( 'ngettext', array( $this, 'ngettext' ), 30, 5 );
		add_filter( 'ngettext_with_context', array( $this, 'ngettext_with_context' ), 30, 6 );
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
	/** The first frontend registration/login establishes the preference; later navigation never changes it. */
	public function save_user_language( $user_id ) {
		$saved = (string) get_user_meta( $user_id, 'lt_language', true );
		if ( ( ! is_admin() || wp_doing_ajax() ) && ! in_array( $saved, LT_Language_Router::instance()->languages(), true ) ) {
			update_user_meta( $user_id, 'lt_language', self::visitor_language() );
		}
	}
	public function save_user_language_on_login( $login, $user = null ) {
		if ( $user instanceof WP_User ) $this->save_user_language( $user->ID );
	}

	/** A switcher click sets a short-lived marker. Ordinary visits and automatic redirects do not set it. */
	public function save_explicit_language_choice() {
		if ( ! isset( $_COOKIE['lt_lang_choice'] ) || ! is_string( $_COOKIE['lt_lang_choice'] ) || is_admin() || ! is_user_logged_in() ) return;
		$router = LT_Language_Router::instance();
		$lang   = sanitize_key( wp_unslash( $_COOKIE['lt_lang_choice'] ) );
		if ( ! in_array( $lang, $router->languages(), true ) || $router->current_language() !== $lang ) return;
		if ( get_user_meta( get_current_user_id(), 'lt_language', true ) !== $lang ) update_user_meta( get_current_user_id(), 'lt_language', $lang );
		if ( ! headers_sent() ) setcookie( 'lt_lang_choice', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
		unset( $_COOKIE['lt_lang_choice'] );
	}

	private static function language_options( $selected = '' ) {
		$router = LT_Language_Router::instance();
		$options = '';
		foreach ( $router->languages() as $lang ) {
			if ( $router->is_hidden( $lang ) && $lang !== $selected ) continue;
			$options .= '<option value="' . esc_attr( $lang ) . '"' . selected( $selected, $lang, false ) . '>' . esc_html( LT_Language_Router::flag( $lang ) . ' ' . LT_Language_Router::language_label( $lang ) ) . '</option>';
		}
		return $options;
	}

	/** Visible in the native WordPress user profile, without changing its separate admin locale. */
	public function profile_language_field( $user ) {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) return;
		$lang = (string) get_user_meta( $user->ID, 'lt_language', true );
		echo '<h2>' . esc_html__( 'Shop language', 'lightweight-translation' ) . '</h2><table class="form-table" role="presentation"><tr><th><label for="lt_preferred_language">' . esc_html__( 'Preferred language', 'lightweight-translation' ) . '</label></th><td><select name="lt_preferred_language" id="lt_preferred_language"><option value="">' . esc_html__( 'Not set yet', 'lightweight-translation' ) . '</option>' . self::language_options( $lang ) . '</select><p class="description">' . esc_html__( 'Used for this customer’s account e-mails. The WordPress admin language is separate.', 'lightweight-translation' ) . '</p></td></tr></table>';
		wp_nonce_field( 'lt_user_language_' . $user->ID, 'lt_user_language_nonce' );
	}
	public function save_profile_language( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['lt_user_language_nonce'], $_POST['lt_preferred_language'] ) || ! is_string( $_POST['lt_user_language_nonce'] ) || ! is_string( $_POST['lt_preferred_language'] ) ) return;
		$nonce = sanitize_text_field( wp_unslash( $_POST['lt_user_language_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'lt_user_language_' . $user_id ) ) return;
		$lang = sanitize_key( wp_unslash( $_POST['lt_preferred_language'] ) );
		if ( '' === $lang ) { delete_user_meta( $user_id, 'lt_language' ); return; }
		if ( in_array( $lang, LT_Language_Router::instance()->languages(), true ) ) update_user_meta( $user_id, 'lt_language', $lang );
	}

	/** WooCommerce "Account details": the customer can also choose the language directly. */
	public function account_language_field() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) return;
		$lang = (string) get_user_meta( $user_id, 'lt_language', true );
		if ( ! in_array( $lang, LT_Language_Router::instance()->languages(), true ) ) $lang = self::visitor_language();
		if ( isset( $_POST['lt_preferred_language'] ) && is_string( $_POST['lt_preferred_language'] ) ) {
			$submitted = sanitize_key( wp_unslash( $_POST['lt_preferred_language'] ) );
			if ( in_array( $submitted, LT_Language_Router::instance()->languages(), true ) ) $lang = $submitted;
		}
		echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last"><label for="lt_preferred_language">' . esc_html__( 'Preferred language', 'lightweight-translation' ) . '</label><select name="lt_preferred_language" id="lt_preferred_language">' . self::language_options( $lang ) . '</select></p>';
		// WooCommerce offers this hook after Email. Move only our field next to Display name,
		// reusing its native two-column row classes without overriding the account template.
		echo '<script>(function(s){var f=s.closest("form.woocommerce-EditAccountForm");if(!f)return;var input=f.querySelector("#account_display_name"),select=f.querySelector("#lt_preferred_language");var display=input&&input.closest(".form-row"),language=select&&select.closest(".form-row");if(!display||!language)return;display.classList.remove("woocommerce-form-row--wide","form-row-wide");display.classList.add("woocommerce-form-row--first","form-row-first");display.parentNode.insertBefore(language,display.nextSibling);})(document.currentScript);</script>';
	}
	public function validate_account_language( $errors, $user ) {
		if ( ! isset( $_POST['lt_preferred_language'] ) ) return;
		if ( ! is_string( $_POST['lt_preferred_language'] ) ) {
			$errors->add( 'lt_invalid_language', __( 'Please choose an available language.', 'lightweight-translation' ) );
			return;
		}
		$lang = sanitize_key( wp_unslash( $_POST['lt_preferred_language'] ) );
		$router = LT_Language_Router::instance();
		$current = (string) get_user_meta( get_current_user_id(), 'lt_language', true );
		if ( ! in_array( $lang, $router->languages(), true ) || ( $router->is_hidden( $lang ) && $lang !== $current ) ) {
			$errors->add( 'lt_invalid_language', __( 'Please choose an available language.', 'lightweight-translation' ) );
		}
	}
	public function save_account_language( $user_id ) {
		if ( (int) $user_id !== get_current_user_id() || ! isset( $_POST['lt_preferred_language'] ) || ! is_string( $_POST['lt_preferred_language'] ) ) return;
		$lang = sanitize_key( wp_unslash( $_POST['lt_preferred_language'] ) );
		$router = LT_Language_Router::instance();
		if ( ! in_array( $lang, $router->languages(), true ) || ( $router->is_hidden( $lang ) && $lang !== get_user_meta( $user_id, 'lt_language', true ) ) ) return;
		update_user_meta( $user_id, 'lt_language', $lang );
		if ( ! headers_sent() ) setcookie( 'lt_lang', $lang, time() + MONTH_IN_SECONDS * 6, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
		// WooCommerce redirects to Account details immediately after this hook. Match that URL to the saved language.
		if ( $lang !== $router->current_language() ) {
			add_filter( 'woocommerce_get_endpoint_url', function( $url, $endpoint ) use ( $lang ) {
				return 'edit-account' === $endpoint ? LT_Language_Router::url( $lang ) : $url;
			}, 20, 2 );
		}
	}

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
		if ( self::$reading_original || ! is_object( $email ) || empty( $email->id ) ) return $value;
		$lang = self::language_for( $email );
		$this->enter( $email, $lang );
		if ( ! $lang || ! preg_match( self::TEMPLATE_KEYS, (string) $key ) ) return $value;
		$source = self::original( $email, $key );
		if ( '' === trim( $source ) ) return $value;
		$translated = LT_Translation_Store::translate( $lang, 'email', 0, 'email_' . $key . '_' . sanitize_key( $email->id ), $source );
		return $translated !== $source && self::valid_translation( $source, $translated ) ? $translated : $value;
	}

	/** Read sources with both the visitor-language layer and outgoing-mail layer suspended. */
	public static function reading_original() { return self::$reading_original; }
	public static function with_original( $callback ) {
		$before = self::$reading_original;
		self::$reading_original = true;
		try {
			return LT_Language_Router::instance()->with_language( LT_Language_Router::instance()->default_language(), function() use ( $callback ) {
				$locale = (string) get_option( 'WPLANG', '' ) ?: 'en_US';
				$switched = get_locale() !== $locale && switch_to_locale( $locale );
				try { return $callback(); }
				finally { if ( $switched ) restore_previous_locale(); }
			} );
		} finally { self::$reading_original = $before; }
	}

	/** Reads the same original in admin, CSV exports and outgoing e-mails. */
	public static function original( $email, $key ) {
		return self::with_original( function() use ( $email, $key ) {
			$value = (string) $email->get_option( $key );
			return '' !== trim( $value ) ? $value : self::default_text( $email, $key );
		} );
	}

	public function begin_email( $allowed, $email ) { $this->leave(); return $allowed; }
	public function order_table( $order, $sent_to_admin, $plain_text, $email ) {
		if ( is_object( $email ) ) $this->enter( $email, self::language_for( $email ) );
	}
	/** Empty outside an e-mail; the original language is explicit for shop/original-language messages. */
	public function render_language() {
		return ! self::$reading_original && $this->email_context ? ( $this->language ?: LT_Language_Router::instance()->default_language() ) : '';
	}
	private function enter( $email, $language ) {
		if ( $this->email_context !== $email ) $this->leave();
		$this->email_context = $email;
		$this->language = $language;
		// WooCommerce formats {order_date} in trigger(), before it reads enabled/subject options.
		$order = $email->object ?? null;
		if ( ! $language || $this->refreshing_date || ! isset( $email->placeholders['{order_date}'] ) || ! is_object( $order ) || ! is_callable( array( $order, 'get_date_created' ) ) ) return;
		$date = $order->get_date_created();
		if ( $this->date_email === $email && $this->date_order === $order && $this->date_language === $language ) return;
		if ( ! $date ) return;
		if ( $this->date_email !== $email ) { $this->date_email = $email; $this->date_placeholder = $email->placeholders['{order_date}']; }
		$this->date_order = $order; $this->date_language = $language;
		$this->refreshing_date = true;
		try { $email->placeholders['{order_date}'] = wc_format_datetime( $date ); }
		finally { $this->refreshing_date = false; }
	}

	/** Placeholders may move in a sentence, but their names and counts must survive translation. */
	public static function valid_translation( $source, $translated ) {
		if ( '' === trim( $translated ) ) return true;
		$tokens = function( $text ) {
			preg_match_all( '/\{[a-zA-Z_][a-zA-Z0-9_]*\}|%(?:[0-9]+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/', str_replace( '%%', '', $text ), $m );
			sort( $m[0] );
			return $m[0];
		};
		return $tokens( $source ) === $tokens( $translated );
	}

	public function restore_email( $allowed, $email ) { $this->leave(); return $allowed; }
	public function footer( $value ) {
		return ! self::$reading_original && $this->language ? LT_Frontend_Filters::lookup_string( $this->language, $value, LT_Admin_Texts::key( 'woocommerce_email_footer_text', '' ) ) : $value;
	}

	public function leave() {
		if ( $this->date_email ) $this->date_email->placeholders['{order_date}'] = $this->date_placeholder;
		$this->date_email = null; $this->date_order = null; $this->date_language = null; $this->date_placeholder = null; $this->email_context = null; $this->language = '';
	}

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

	public function gettext( $translation, $text, $domain ) { if ( self::$reading_original || ! $this->language || ! $text || ! LT_Frontend_Filters::domain_allowed( $domain ) ) return $translation; return LT_Frontend_Filters::lookup_string( $this->language, $translation, $text, $domain ); }
	public function ngettext( $translation, $single, $plural, $number, $domain ) {
		if ( self::$reading_original || ! $this->language || ! LT_Frontend_Filters::domain_allowed( $domain ) ) return $translation;
		$out = LT_Frontend_Filters::plural_lookup( $this->language, $single, $plural, $number, $domain );
		return null !== $out ? $out : $translation;
	}
	public function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		if ( self::$reading_original || ! $this->language || ! LT_Frontend_Filters::domain_allowed( $domain ) ) return $translation;
		$out = LT_Frontend_Filters::plural_lookup( $this->language, $single, $plural, $number, $domain, $context . "\x04", $context );
		return null !== $out ? $out : $translation;
	}
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

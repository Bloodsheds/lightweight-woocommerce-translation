<?php
defined( 'ABSPATH' ) || exit;

/** Public CF7 form output only. Mail recipients/templates, field names, submitted values and pipes stay original. */
class LT_CF_Seven_Module {
	private static $reading = false;
	public static function boot() {
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'form' ), 20 );
		add_filter( 'wpcf7_display_message', array( __CLASS__, 'message' ), 20, 2 );
		add_filter( 'lt_default_string_domains', function( $domains ) { $domains[] = 'contact-form-7'; return $domains; } );
		add_action( 'admin_init', array( __CLASS__, 'prepare' ), 21 );
	}
	private static function current() { return WPCF7_ContactForm::get_current(); }
	private static function key( $id, $text ) { return 'string_' . md5( 'cf7:' . $id . ':' . self::normal( $text ) ); }
	private static function normal( $text ) { return trim( preg_replace( '/\s+/u', ' ', (string) $text ) ); }
	private static function active() {
		if ( self::$reading || is_admin() ) return false;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = (string) ( $_GET['rest_route'] ?? $_SERVER['REQUEST_URI'] ?? '' );
			if ( ! preg_match( '~/contact-form-7/v1/contact-forms/\d+/(?:feedback(?:/schema)?|refill)(?:[/?]|$)~', $route ) ) return false;
		}
		return LT_Language_Router::instance()->is_secondary();
	}
	private static function translate( $id, $source ) {
		$language = LT_Language_Router::instance()->current_language();
		$plain = self::normal( $source );
		$value = LT_Translation_Store::string_lookup( $language, self::key( $id, $plain ), $plain );
		if ( null === $value ) $value = LT_Translation_Store::by_text( $language, $plain );
		if ( null === $value || ! self::valid( $plain, $value ) ) return $source;
		return preg_replace_callback( '/\S(?:.*\S)?/us', function() use ( $value ) { return $value; }, $source, 1 );
	}
	private static function valid( $source, $translation ) {
		$tokens = function( $text ) { preg_match_all( '/\[[a-zA-Z_][a-zA-Z0-9_:\-]*\]|%(?:\d+\$)?[sdfu]/', $text, $m ); sort( $m[0] ); return $m[0]; };
		return $tokens( $source ) === $tokens( $translation );
	}
	public static function form( $html ) {
		$form = self::current();
		if ( ! $form || ! self::active() ) return $html;
		$id = $form->id();
		return LT_Public_HTML::map( $html, function( $text ) use ( $id ) { return self::translate( $id, $text ); } );
	}
	public static function message( $message, $status ) {
		$form = self::current();
		if ( ! $form || ! self::active() ) return $message;
		$source = (string) ( $form->prop( 'messages' )[ $status ] ?? $message );
		$value = self::translate( $form->id(), $source );
		return $value === $source ? $message : wpcf7_mail_replace_tags( $value );
	}
	public static function entries( $form ) {
		$values = array();
		$add = function( $text ) use ( &$values, $form ) { $text = self::normal( $text ); if ( preg_match( '/\p{L}{2}/u', $text ) && mb_strlen( $text ) <= 2000 ) $values[ self::key( $form->id(), $text ) ] = array( $text, 'contact-form-7' ); return $text; };
		$raw = (string) $form->prop( 'form' );
		$raw = preg_replace( '~\[(?:[^\]"\x27]|"[^"]*"|\x27[^\x27]*\x27)*\]~s', ' ', $raw );
		// With CF7's automatic paragraphs, each line of a label becomes its own text (separated by <br>): both are noted.
		LT_Public_HTML::map( $raw, function( $text ) use ( $add ) {
			$lines = preg_split( '/\R/u', trim( (string) $text ) );
			if ( count( $lines ) > 1 && ( ! defined( 'WPCF7_AUTOP' ) || WPCF7_AUTOP ) ) foreach ( $lines as $line ) $add( $line );
			return $add( $text );
		} );
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( 'submit' === $tag->basetype || $tag->has_option( 'placeholder' ) || $tag->has_option( 'watermark' ) ) { if ( ! empty( $tag->values[0] ) ) $add( $tag->values[0] ); elseif ( 'submit' === $tag->basetype ) $add( __( 'Send', 'contact-form-7' ) ); }
			if ( in_array( $tag->basetype, array( 'select', 'checkbox', 'radio' ), true ) ) foreach ( $tag->labels as $label ) $add( $label );
			if ( 'select' === $tag->basetype && ( $tag->has_option( 'include_blank' ) || empty( $tag->values ) ) ) $add( html_entity_decode( __( '&#8212;Please choose an option&#8212;', 'contact-form-7' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( 'quiz' === $tag->basetype && $tag->pipes instanceof WPCF7_Pipes ) foreach ( $tag->pipes->collect_befores() as $question ) $add( $question );
		}
		foreach ( (array) $form->prop( 'messages' ) as $message ) $add( $message );
		return $values;
	}
	public static function prepare() {
		if ( wp_doing_ajax() || 0 !== strpos( (string) ( $_GET['page'] ?? '' ), 'lt-' ) || ! current_user_can( lt_capability() ) ) return;
		self::$reading = true;
		$site_locale = (string) get_option( 'WPLANG', '' ) ?: 'en_US';
		$switched = get_locale() !== $site_locale && switch_to_locale( $site_locale );
		try {
			$texts = array(); $index = (array) get_option( LT_Code_Scanner::INDEX, array() );
			foreach ( WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) ) as $form ) {
				foreach ( self::entries( $form ) as $field => $info ) {
					$texts[ $field ] = $info;
					$index[ $field ] = array( 'd' => 'contact-form-7', 't' => 'l', 'a' => 'f', 's' => 'plugin:contact-form-7', 'p' => 'Form #' . $form->id() . ' · ' . $form->title(), 'v' => 1 ) + ( $index[ $field ] ?? array() );
				}
			}
			LT_Page_Texts::register( $texts );
			if ( $index !== get_option( LT_Code_Scanner::INDEX, array() ) ) update_option( LT_Code_Scanner::INDEX, $index, false );
		} finally { if ( $switched ) restore_previous_locale(); self::$reading = false; }
	}
}

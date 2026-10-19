<?php
defined( 'ABSPATH' ) || exit;

/** Customer-facing order statuses and the calendar used while rendering an e-mail. */
class LT_Order_Translations {
	private static $rendering_date = false;
	private static $originals;

	public static function boot() {
		add_filter( 'wc_order_statuses', array( __CLASS__, 'statuses' ), 100 );
		add_filter( 'woocommerce_date_format', array( __CLASS__, 'date_format' ), 30 );
		add_filter( 'wp_date', array( __CLASS__, 'date' ), 30, 4 );
	}
	private static function status_key( $status ) {
		$standard = array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' );
		return isset( $standard[ $status ] ) ? 'Order status' . "\x04" . $standard[ $status ] : 'order_status:' . $status;
	}
	private static function original_statuses() {
		return LT_Email_Translations::with_original( function() { return wc_get_order_statuses(); } );
	}
	public static function statuses( $statuses ) {
		if ( LT_Email_Translations::reading_original() || ! is_array( $statuses ) ) return $statuses;
		$language = apply_filters( 'lt_order_render_language', LT_Email_Translations::instance()->render_language() );
		if ( ! $language ) {
			if ( is_admin() ) return $statuses;
			$language = LT_Language_Router::instance()->current_language();
			if ( ! LT_Language_Router::instance()->is_secondary() ) return $statuses;
		}
		$originals = self::original_statuses();
		foreach ( $statuses as $code => $label ) {
			$source = $originals[ $code ] ?? $label;
			$statuses[ $code ] = $language === LT_Language_Router::instance()->default_language() ? $source : self::lookup( $language, self::status_key( $code ), $source, $source );
		}
		return $statuses;
	}
	private static function lookup( $language, $key, $source, $fallback ) {
		$value = LT_Translation_Store::string_lookup( $language, 'string_' . md5( $key ), $source );
		if ( null === $value ) $value = LT_Translation_Store::by_text( $language, $source );
		return null === $value ? $fallback : $value;
	}

	/** Stable original values, independent of the language of the admin or outgoing message. */
	private static function originals() {
		if ( null === self::$originals ) self::$originals = LT_Email_Translations::with_original( function() {
			global $wp_locale;
			$out = array( 'date_format' => (string) get_option( 'date_format', 'F j, Y' ) );
			foreach ( range( 1, 12 ) as $month ) {
				$key = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
				$name = $wp_locale->get_month( $month );
				$out[ 'month_' . $key ] = $name;
				$out[ 'month_short_' . $key ] = $wp_locale->get_month_abbrev( $name );
			}
			foreach ( range( 0, 6 ) as $day ) {
				$name = $wp_locale->get_weekday( $day );
				$out[ 'weekday_' . $day ] = $name;
				$out[ 'weekday_short_' . $day ] = $wp_locale->get_weekday_abbrev( $name );
			}
			return $out;
		} );
		return self::$originals;
	}

	/** Merge these into the normal WooCommerce strings list and its existing CSV workflow. */
	public static function entries() {
		$out = array();
		foreach ( self::original_statuses() as $code => $source ) $out[ 'string_' . md5( self::status_key( $code ) ) ] = array( 'source' => $source, 'p' => 'includes/wc-order-functions.php', 'wc_areas' => array( 'orders', 'emails', 'statuses' ) );
		foreach ( self::originals() as $key => $source ) $out[ 'string_' . md5( 'order_calendar:' . $key ) ] = array( 'source' => $source, 'p' => 'Order dates / ' . $key, 'wc_areas' => array( 'dates' ) );
		return $out;
	}
	private static function date_language() {
		if ( LT_Email_Translations::reading_original() ) return '';
		$language = apply_filters( 'lt_order_render_language', LT_Email_Translations::instance()->render_language() );
		return $language && $language !== LT_Language_Router::instance()->default_language() ? $language : '';
	}
	public static function date_format( $format ) {
		$language = self::date_language();
		if ( ! $language ) return $format;
		$source = self::originals()['date_format'];
		$out = self::lookup( $language, 'order_calendar:date_format', $source, '' );
		// Formats remain ordinary PHP date formats, including escaped literal words.
		if ( '' !== $out && strlen( $out ) <= 120 && wp_strip_all_tags( $out ) === $out ) return $out;
		$defaults = array( 'en' => 'F j, Y', 'fr' => 'j F Y', 'pt' => 'j \d\e F \d\e Y' );
		return in_array( $format, array_values( $defaults ), true ) ? ( $defaults[ $language ] ?? $format ) : $format;
	}

	/** wp_date underlies WC_DateTime::date_i18n; the supplied timestamp/timezone are kept intact. */
	public static function date( $date, $format, $timestamp, $timezone ) {
		if ( self::$rendering_date || in_array( $format, array( 'c', 'r', 'U', DATE_RFC2822, DATE_RFC822, DATE_RFC850, DATE_RFC1036, DATE_RFC1123, DATE_RFC7231, DATE_COOKIE ), true ) || ! preg_match( '/[FDlM]/', $format ) ) return $date;
		$language = self::date_language();
		if ( ! $language ) return $date;
		global $wp_locale;
		if ( ! is_object( $wp_locale ) ) return $date;
		$previous = $wp_locale;
		$copy = clone $wp_locale;
		$calendar = self::calendar( $language );
		$original = self::originals();
		foreach ( range( 1, 12 ) as $month ) {
			$key = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
			$name = self::lookup( $language, 'order_calendar:month_' . $key, $original[ 'month_' . $key ], $calendar['months'][ $month - 1 ] ?? $original[ 'month_' . $key ] );
			$copy->month[ $key ] = $name;
			$copy->month_abbrev[ $name ] = self::lookup( $language, 'order_calendar:month_short_' . $key, $original[ 'month_short_' . $key ], $calendar['months_short'][ $month - 1 ] ?? $original[ 'month_short_' . $key ] );
		}
		foreach ( range( 0, 6 ) as $day ) {
			$name = self::lookup( $language, 'order_calendar:weekday_' . $day, $original[ 'weekday_' . $day ], $calendar['days'][ $day ] ?? $original[ 'weekday_' . $day ] );
			$copy->weekday[ $day ] = $name;
			$copy->weekday_abbrev[ $name ] = self::lookup( $language, 'order_calendar:weekday_short_' . $day, $original[ 'weekday_short_' . $day ], $calendar['days_short'][ $day ] ?? $original[ 'weekday_short_' . $day ] );
		}
		self::$rendering_date = true;
		$wp_locale = $copy;
		try { return wp_date( $format, $timestamp, $timezone ); }
		finally { $wp_locale = $previous; self::$rendering_date = false; }
	}

	/** ICU supplies configured languages without translation APIs; common shop languages have a tiny fallback. */
	private static function calendar( $language ) {
		static $cache = array();
		if ( isset( $cache[ $language ] ) ) return $cache[ $language ];
		$maps = array(
			'en' => array( 'January|February|March|April|May|June|July|August|September|October|November|December', 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec', 'Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday', 'Sun|Mon|Tue|Wed|Thu|Fri|Sat' ),
			'fr' => array( 'janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre', 'janv.|févr.|mars|avr.|mai|juin|juil.|août|sept.|oct.|nov.|déc.', 'dimanche|lundi|mardi|mercredi|jeudi|vendredi|samedi', 'dim.|lun.|mar.|mer.|jeu.|ven.|sam.' ),
			'pt' => array( 'janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro', 'jan.|fev.|mar.|abr.|mai.|jun.|jul.|ago.|set.|out.|nov.|dez.', 'domingo|segunda-feira|terça-feira|quarta-feira|quinta-feira|sexta-feira|sábado', 'dom.|seg.|ter.|qua.|qui.|sex.|sáb.' ),
		);
		$out = isset( $maps[ $language ] ) ? array_combine( array( 'months', 'months_short', 'days', 'days_short' ), array_map( function( $text ) { return explode( '|', $text ); }, $maps[ $language ] ) ) : array();
		if ( class_exists( 'IntlDateFormatter' ) ) {
			foreach ( array( 'months' => 'MMMM', 'months_short' => 'MMM', 'days' => 'EEEE', 'days_short' => 'EEE' ) as $key => $pattern ) {
				try {
					$formatter = new IntlDateFormatter( $language, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, $pattern );
					$names = array();
					foreach ( range( 0, 0 === strpos( $key, 'months' ) ? 11 : 6 ) as $i ) {
						$value = $formatter->format( 0 === strpos( $key, 'months' ) ? gmmktime( 12, 0, 0, $i + 1, 15, 2026 ) : gmmktime( 12, 0, 0, 1, 4 + $i, 2026 ) );
						if ( false === $value || '' === $value ) { $names = array(); break; }
						$names[] = $value;
					}
					if ( $names ) $out[ $key ] = $names;
				} catch ( Throwable $e ) {} // Stored calendar translations remain available without ICU data.
			}
		}
		return $cache[ $language ] = $out;
	}
}

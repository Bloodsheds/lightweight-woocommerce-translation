<?php
defined( 'ABSPATH' ) || exit;

/** Admin-only navigation. Classification is a suggested use, never proof of public visibility. */
class LT_WooCommerce_Text_Groups {
	private static $catalog;
	private static $geography;
	
	public static function boot() {
		add_filter( 'lt_string_export_args', array( __CLASS__, 'export_args' ) );
		add_filter( 'lt_export_string_include', array( __CLASS__, 'export_include' ), 10, 4 );
		add_filter( 'lt_string_view_args', array( __CLASS__, 'view_args' ), 10, 3 );
		add_filter( 'lt_string_tags', array( __CLASS__, 'tags' ), 10, 4 );
	}
	private static function relevant( $args ) { return 'woocommerce' === ( $args['group'] ?? '' ) && 'texts' === ( $args['sub'] ?? '' ); }
	public static function areas() {
		return array(
			'products' => array( __( 'Products & catalog', 'lightweight-translation' ), __( 'Product buttons, stock, options and reviews.', 'lightweight-translation' ) ),
			'cart' => array( __( 'Cart & mini-cart', 'lightweight-translation' ), __( 'Cart contents, coupons, totals and empty cart.', 'lightweight-translation' ) ),
			'checkout' => array( __( 'Checkout & payment', 'lightweight-translation' ), __( 'Addresses, delivery, payment and checkout validation.', 'lightweight-translation' ) ),
			'account' => array( __( 'Customer account', 'lightweight-translation' ), __( 'Sign in, registration, password and personal details.', 'lightweight-translation' ) ),
			'statuses' => array( __( 'Order statuses', 'lightweight-translation' ), __( 'Standard and custom order status labels shown to customers.', 'lightweight-translation' ) ),
			'dates' => array( __( 'Dates & calendar', 'lightweight-translation' ), __( 'Month names, weekdays and date format shared by orders and customer e-mails.', 'lightweight-translation' ) ),
			'orders' => array( __( 'Orders & tracking', 'lightweight-translation' ), __( 'Confirmation, order details and tracking.', 'lightweight-translation' ) ),
			'dynamic' => array( __( 'AJAX, API & JSON', 'lightweight-translation' ), __( 'Texts returned by live shop requests and structured API responses.', 'lightweight-translation' ) ),
			'messages' => array( __( 'Shared labels & messages', 'lightweight-translation' ), __( 'Shared wording and messages whose exact screen depends on the situation.', 'lightweight-translation' ) ),
			'scripts' => array( __( 'JavaScript & blocks', 'lightweight-translation' ), __( 'Interface texts present in WooCommerce frontend scripts.', 'lightweight-translation' ) ),
			'emails' => array( __( 'Customer e-mails', 'lightweight-translation' ), __( 'Messages sent to customers; these are not all displayed on the website.', 'lightweight-translation' ) ),
			'geography' => array( __( 'Countries & regions', 'lightweight-translation' ), __( 'Reference lists used by address selectors. Translate only if needed.', 'lightweight-translation' ) ),
			'other' => array( __( 'Other collected texts', 'lightweight-translation' ), __( 'Older or additional texts without a confirmed customer use. Kept with their translations.', 'lightweight-translation' ) ),
		);
	}
	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$data = json_decode( (string) file_get_contents( __DIR__ . '/catalog.json' ), true );
		$map = array();
		foreach ( (array) ( $data['entries'] ?? array() ) as $entry ) {
			$prefix = '' !== $entry['context'] ? $entry['context'] . "\x04" : '';
			foreach ( array_filter( array( $entry['id'], $entry['plural'] ), 'strlen' ) as $text ) {
				$areas = ! empty( $entry['js'] ) ? array_values( array_unique( array_merge( $entry['areas'], array( 'scripts' ) ) ) ) : $entry['areas'];
				$map[ LT_String_Keys::field( 'woocommerce', $prefix . $text ) ] = $areas;
				$map[ LT_String_Keys::legacy( $prefix . $text ) ] = $areas;
			}
		}
		foreach ( (array) ( $data['reference_keys'] ?? array() ) as $key ) $map[ $key ] = array( 'geography' );
		foreach ( (array) ( $data['excluded_keys'] ?? array() ) as $key ) $map[ $key ] = array( 'other' );
		return self::$catalog = $map;
	}
	/** Original WooCommerce msgids, grouped by country for admin navigation only. */
	private static function geography() {
		if ( null === self::$geography ) self::$geography = json_decode( (string) file_get_contents( __DIR__ . '/geography.json' ), true );
		return (array) self::$geography;
	}
	public static function country_count() { return count( self::geography()['countries'] ?? array() ); }
	/** Numeric IDs are used only by the existing translation drawer, never as storage keys. */
	public static function geography_code( $id ) {
		$codes = array_keys( self::geography()['countries'] ?? array() );
		if ( (int) $id === count( $codes ) + 1 ) return 'continents';
		return $codes[ (int) $id - 1 ] ?? '';
	}
	public static function geography_label( $country ) {
		if ( 'continents' === $country ) return __( 'Continents', 'lightweight-translation' );
		if ( 'other' === $country ) return __( 'Other regions', 'lightweight-translation' );
		$name = self::geography()['countries'][ strtoupper( $country ) ][0] ?? '';
		return $name ? translate( $name, 'woocommerce' ) : __( 'Countries & regions', 'lightweight-translation' );
	}
	private static function geography_field( $text, $context = '' ) {
		return LT_String_Keys::field( 'woocommerce', ( $context ? $context . "\x04" : '' ) . $text );
	}
	public static function geography_keys( $country ) {
		static $cache = array();
		if ( isset( $cache[ $country ] ) ) return $cache[ $country ];
		$data = self::geography();
		if ( 'continents' === $country ) $names = $data['continents'] ?? array();
		elseif ( isset( $data['countries'][ $country ] ) ) {
			$entry = $data['countries'][ $country ];
			$names = array_merge( array( array( $entry[0], '' ) ), $entry[1] );
		} else return array();
		$keys = array();
		foreach ( $names as $name ) $keys[ self::geography_field( $name[0], $name[1] ?? '' ) ] = true;
		return $cache[ $country ] = array_keys( $keys );
	}
	private static function geography_known_keys() {
		static $known = null;
		if ( null !== $known ) return $known;
		$data = self::geography();
		$known = array_fill_keys( self::geography_keys( 'continents' ), true );
		foreach ( array_keys( $data['countries'] ?? array() ) as $country ) foreach ( self::geography_keys( $country ) as $key ) $known[ $key ] = true;
		return $known;
	}
	/** Country name and regions share one panel, while every entry keeps its original string key. */
	public static function geography_model( $id ) {
		$code = self::geography_code( $id );
		if ( ! $code ) return null;
		$keys = self::geography_keys( $code );
		if ( ! $keys ) return null;
		global $wpdb;
		$table = LT_Translation_Store::table();
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT field_key, MAX(source_text) AS source FROM $table WHERE object_type='string' AND object_id=0 AND field_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') GROUP BY field_key', $keys ) ); // phpcs:ignore WordPress.DB
		$sources = array();
		foreach ( (array) $found as $row ) $sources[ $row->field_key ] = $row->source;
		$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$sections = array();
		$offset = 'continents' === $code ? 0 : 1;
		foreach ( array( array_slice( $keys, 0, $offset ), array_slice( $keys, $offset ) ) as $part => $part_keys ) {
			$entries = array();
			foreach ( $part_keys as $key ) {
				if ( ! isset( $sources[ $key ] ) || ! empty( $index[ $key ]['x'] ) ) continue;
				$entries[] = array( 'type' => 'string', 'id' => 0, 'field' => $key, 'source' => (string) $sources[ $key ], 'label' => $part ? '' : __( 'Country', 'lightweight-translation' ) );
			}
			if ( $entries ) $sections[] = array( 'title' => 'continents' === $code ? __( 'Continents', 'lightweight-translation' ) : ( $part ? __( 'Regions', 'lightweight-translation' ) : __( 'Country', 'lightweight-translation' ) ), 'own' => true, 'entries' => $entries );
		}
		if ( ! $sections ) return null;
		$first = 'continents' === $code ? '' : (string) ( $sources[ $keys[0] ] ?? '' );
		$title = $first ?: self::geography_label( $code );
		return array( 'group' => 'geo', 'id' => (int) $id, 'item' => array( 'type' => 'string', 'id' => 0, 'title' => $title, 'edit' => '', 'block_editor' => false ), 'sections' => $sections, 'read' => true );
	}
	private static function geography_row_html( $id, $code, $label, $region_count, $keys, $translations, $languages ) {
		$data = ' data-group="geo" data-id="' . (int) $id . '"';
		$h = '<tr data-key="geo|' . (int) $id . '"><td class="title column-title column-primary"><strong><a class="row-title lt-open" href="#"' . $data . '>' . esc_html( $label ) . '</a></strong>';
		if ( 'continents' !== $code ) $h .= ' <span class="lt-in">' . esc_html( $code ) . '</span>';
		$h .= ' <span class="lt-count">' . esc_html( sprintf( _n( '%d region', '%d regions', $region_count, 'lightweight-translation' ), $region_count ) ) . '</span>';
		$export = add_query_arg( array( 'action' => 'lt_export', 'groups' => array( 'string' ), 'only' => 'todo', 'string_group' => 'woocommerce', 'string_part' => 'texts', 'string_area' => 'geography', 'string_country' => strtolower( $code ), '_wpnonce' => wp_create_nonce( 'lt_export' ) ), admin_url( 'admin-post.php' ) );
		$h .= '<div class="row-actions"><a href="#" class="lt-open"' . $data . '>' . esc_html__( 'Translate here', 'lightweight-translation' ) . '</a> | <a href="' . esc_url( $export ) . '">CSV</a></div></td>';
		foreach ( $languages as $language ) {
			$done = 0;
			foreach ( $keys as $key ) if ( ! empty( $translations[ $key ][ $language ] ) ) $done++;
			$complete = $done === count( $keys );
			$tip = $complete ? sprintf( __( 'Translated in %s', 'lightweight-translation' ), LT_Language_Router::language_label( $language ) ) : sprintf( __( 'Translate into %s', 'lightweight-translation' ), LT_Language_Router::language_label( $language ) );
			$h .= '<td class="column-lang"><a href="#" class="lt-st lt-open ' . ( $complete ? 'done' : 'todo' ) . '"' . $data . ' data-lang="' . esc_attr( $language ) . '" title="' . esc_attr( $tip ) . '"><span class="dashicons ' . ( $complete ? 'dashicons-yes' : 'dashicons-plus' ) . '" aria-hidden="true"></span>' . ( $complete ? '' : esc_html( $done . '/' . count( $keys ) ) ) . '<span class="screen-reader-text">' . esc_html( $tip ) . '</span></a></td>';
		}
		return $h . '</tr>';
	}
	/** Refresh a country line immediately after saving its panel. */
	public static function geography_row_markup( $id, $languages ) {
		$model = self::geography_model( $id );
		if ( ! $model ) return '';
		$code = self::geography_code( $id );
		$entries = array();
		foreach ( $model['sections'] as $section ) foreach ( $section['entries'] as $entry ) $entries[] = $entry;
		$translations = array();
		foreach ( $entries as $entry ) foreach ( $languages as $language ) {
			$state = LT_Status_Panel::field_state( $entry, $language );
			if ( 'ok' === $state['state'] ) $translations[ $entry['field'] ][ $language ] = $state['value'];
		}
		return self::geography_row_html( $id, $code, $model['item']['title'], 'continents' === $code ? count( $entries ) : max( 0, count( $entries ) - 1 ), array_column( $entries, 'field' ), $translations, $languages );
	}
	public static function classify( $field, $entry = array() ) {
		// Calendar translations are shared by orders and e-mails, but edited in one place.
		// The path also corrects indexes saved by older plugin versions.
		if ( 0 === strpos( (string) ( $entry['p'] ?? '' ), 'Order dates / ' ) ) return array( 'dates' );
		$map = self::catalog();
		if ( ! empty( $entry['wc_areas'] ) ) return array_values( array_intersect( (array) $entry['wc_areas'], array_keys( self::areas() ) ) );
		if ( isset( $map[ $field ] ) ) return $map[ $field ];
		$path = (string) ( $entry['p'] ?? '' );
		if ( preg_match( '#(?:i18n/(?:countries|states|continents)|country|region)\b#i', $path ) ) return array( 'geography' );
		if ( ! empty( $entry['e'] ) ) return array( 'emails' );
		return array( 'other' );
	}
	public static function view_args( $args, $texts, $index ) {
		if ( ! self::relevant( $args ) ) return $args;
		unset( $args['view'] );
		$args['area'] = isset( self::areas()[ $args['area'] ] ) ? $args['area'] : '';
		$keys = array();
		foreach ( $texts as $field => $text ) {
			$entry = $index[ $field ] ?? array();
			if ( 'woocommerce' !== LT_Sources::group_of( $field, $entry ) || 'texts' !== LT_Sources::part_of( $entry ) ) continue;
			$areas = self::classify( $field, $entry );
			if ( '' !== $args['area'] && ! in_array( $args['area'], $areas, true ) ) continue;
			if ( '' === $args['area'] && in_array( 'geography', $areas, true ) ) continue;
			$keys[] = $field;
		}
		$args['keys'] = null === $args['keys'] ? $keys : array_values( array_intersect( $args['keys'], $keys ) );
		if ( 'geography' === $args['area'] ) {
			$country = sanitize_key( $_GET['geo_country'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'other' === $country ) $args['keys'] = array_values( array_diff( $args['keys'], array_keys( self::geography_known_keys() ) ) );
			elseif ( $country ) $args['keys'] = array_values( array_intersect( $args['keys'], self::geography_keys( 'continents' === $country ? $country : strtoupper( $country ) ) ) );
		}
		return $args;
	}
	/** Product-style country list: one row per country, one drawer for its name and regions. */
	public static function render_geography_index( $rows, $translations, $languages ) {
		$data = self::geography();
		$by_key = array();
		foreach ( $rows as $row ) $by_key[ $row->field_key ] = $row;
		$search = isset( $_GET['geo_search'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['geo_search'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=lt-woocommerce&area=geography' );
		echo '<div class="lt-head"><div><h2>' . esc_html__( 'Countries & regions', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Open a country to translate its name and all its regions together. Other countries can remain untranslated.', 'lightweight-translation' ) . '</p></div></div>';
		echo '<form method="get" class="lt-search-form"><input type="hidden" name="page" value="lt-woocommerce"><input type="hidden" name="area" value="geography"><p class="search-box"><label class="screen-reader-text" for="lt-geo-search">' . esc_html__( 'Search', 'lightweight-translation' ) . '</label><input id="lt-geo-search" type="search" name="geo_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search a country…', 'lightweight-translation' ) . '"> <button class="button">' . esc_html__( 'Search', 'lightweight-translation' ) . '</button></p></form>';
		echo '<table class="wp-list-table widefat fixed striped table-view-list lt-status-list"><thead><tr><th class="column-title column-primary">' . esc_html__( 'Country', 'lightweight-translation' ) . '</th>';
		foreach ( $languages as $language ) echo '<th class="column-lang">' . esc_html( LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ) ) . '</th>';
		echo '</tr></thead><tbody>';
		$shown = 0;
		$countries_shown = 0;
		$render = function( $id, $code, $label, $keys, $regions ) use ( &$shown, &$countries_shown, $search, $by_key, $translations, $languages ) {
			if ( '' !== $search && false === mb_stripos( $label . ' ' . $code, $search ) ) return;
			$present = array_keys( array_intersect_key( $by_key, array_fill_keys( $keys, true ) ) );
			if ( ! $present ) return;
			$shown++;
			if ( 2 === strlen( $code ) ) $countries_shown++;
			echo self::geography_row_html( $id, $code, $label, $regions, $present, $translations, $languages ); // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$id = 0;
		foreach ( $data['countries'] ?? array() as $code => $entry ) {
			$id++;
			$keys = self::geography_keys( $code );
			$name_key = self::geography_field( $entry[0] );
			$label = isset( $by_key[ $name_key ] ) ? $by_key[ $name_key ]->source : $entry[0];
			$render( $id, $code, $label, $keys, count( $entry[1] ) );
		}
		$render( $id + 1, 'continents', __( 'Continents', 'lightweight-translation' ), self::geography_keys( 'continents' ), count( $data['continents'] ?? array() ) );
		$other = array_values( array_diff( array_keys( $by_key ), array_keys( self::geography_known_keys() ) ) );
		if ( ! $shown ) echo '<tr><td colspan="' . ( count( $languages ) + 1 ) . '">' . esc_html__( 'No items found.', 'lightweight-translation' ) . '</td></tr>';
		echo '</tbody></table><p class="description">' . esc_html( sprintf( _n( '%s country shown', '%s countries shown', $countries_shown, 'lightweight-translation' ), number_format_i18n( $countries_shown ) ) ) . '</p>';
		if ( $other ) echo '<p><a href="' . esc_url( add_query_arg( 'geo_country', 'other', $base ) ) . '">' . esc_html__( 'Other regions', 'lightweight-translation' ) . ' (' . (int) count( $other ) . ')</a></p>';
		LT_Translation_Status::instance()->render_drawer( array( 'page' => 'lt-woocommerce', 'sub' => 'texts', 'area' => 'geography' ) );
	}
	public static function category_filter( $args ) {
		// The shared source sidebar owns this navigation; retain the method for third-party compatibility.
	}

	public static function export_args( $args ) {
		if ( 'woocommerce' !== ( $args['string_group'] ?? '' ) || 'lt-woocommerce' !== ( $_GET['page'] ?? '' ) || 'settings' === ( $_GET['sub'] ?? '' ) ) return $args;
		$args['string_area'] = sanitize_key( $_GET['area'] ?? '' );
		if ( 'geography' === $args['string_area'] ) $args['string_country'] = sanitize_key( $_GET['geo_country'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$args['string_part'] = 'texts';
		return $args;
	}
	public static function export_include( $include, $field, $entry, $request ) {
		if ( ! $include || 'woocommerce' !== ( $request['string_group'] ?? '' ) || ! in_array( $request['string_part'] ?? '', array( 'texts', 'settings' ), true ) ) return $include;
		if ( $request['string_part'] !== LT_Sources::part_of( $entry ) ) return false;
		if ( 'settings' === $request['string_part'] ) return true;
		$area = sanitize_key( $request['string_area'] ?? '' );
		if ( ! $area && in_array( 'geography', self::classify( $field, (array) $entry ), true ) ) return false;
		if ( $area && ! in_array( $area, self::classify( $field, (array) $entry ), true ) ) return false;
		$country = sanitize_key( $request['string_country'] ?? '' );
		if ( 'geography' === $area && $country ) {
			if ( 'other' === $country ) { $known = self::geography_known_keys(); return ! isset( $known[ $field ] ); }
			return in_array( $field, self::geography_keys( 'continents' === $country ? $country : strtoupper( $country ) ), true );
		}
		return true;
	}

	public static function tags( $tags, $row, $entry, $args ) {
		if ( ! self::relevant( $args ) ) return $tags;
		$areas = self::areas();
		$names = array();
		foreach ( $row->keys ?? array( $row->field_key ) as $field ) {
			foreach ( self::classify( $field, $entry ) as $area ) $names[ $area ] = $areas[ $area ][0];
		}
		$tags[] = esc_html( implode( ', ', $names ) );
		return $tags;
	}
}

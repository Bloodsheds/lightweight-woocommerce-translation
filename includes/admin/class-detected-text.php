<?php
defined( 'ABSPATH' ) || exit;

/** Connects a visible wording to stored fields using evidence, never a CSS-based guess. */
class LT_Detected_Text {
	private static $instance;
	private static $strings = array();
	private static $terms = array();
	private static $index;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'wp_ajax_lt_detected_texts', array( $this, 'ajax' ) );
		add_action( 'admin_post_lt_detected_save', array( $this, 'save' ) );
	}
	public static function normal( $text ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); }
	public static function url( $text, $ref, $language, $target = '' ) { return LT_Admin_Navigation::url( 'lt-translate', array_filter( array( 'text' => $text, 'ref' => $ref, 'lang' => $language, 'target' => $target ) ) ); }

	/** Exact originals are indexed. Normalized variants are also found in the recorded page map below. */
	public static function prime( $texts ) {
		global $wpdb;
		$missing = array(); $term_names = array();
		foreach ( $texts as $text ) {
			$normal = self::normal( $text );
			if ( ! array_key_exists( $normal, self::$strings ) ) { self::$strings[ $normal ] = array(); $missing[] = LT_Translation_Store::hash( $text ); }
			if ( ! array_key_exists( $normal, self::$terms ) ) { self::$terms[ $normal ] = array(); $term_names[ $normal ] = (string) $text; }
		}
		foreach ( array_chunk( array_unique( $missing ), 100 ) as $hashes ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, MAX(source_text) AS source FROM ' . LT_Translation_Store::table() . " WHERE object_type='string' AND object_id=0 AND source_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ') GROUP BY field_key', $hashes ) );
			foreach ( (array) $rows as $row ) self::$strings[ self::normal( $row->source ) ][ $row->field_key ] = $row->source;
		}
		$taxonomies = array_values( array_filter( array_merge( array( 'product_cat', 'product_tag' ), function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : array() ), 'taxonomy_exists' ) );
		if ( $taxonomies && $term_names ) foreach ( array_chunk( $term_names, 100, true ) as $batch ) {
			$tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
			$name_placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
			$sql = 'SELECT t.term_id, t.name, tt.taxonomy FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_id=t.term_id WHERE tt.taxonomy IN (' . $tax_placeholders . ') AND t.name IN (' . $name_placeholders . ')';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $taxonomies, array_values( $batch ) ) ) );
			foreach ( (array) $rows as $row ) {
				$normal = self::normal( $row->name );
				if ( isset( $term_names[ $normal ] ) && $normal === self::normal( $term_names[ $normal ] ) ) self::$terms[ $normal ][] = array( 'id' => (int) $row->term_id, 'name' => $row->name, 'taxonomy' => $row->taxonomy );
			}
		}
	}
	private static function candidate( $type, $id, $field, $source, $origin, $segment = null, $group = '' ) {
		$key = hash( 'sha256', $type . ':' . $id . ':' . $field . ':' . LT_Translation_Store::hash( $source ) . ':' . ( null === $segment ? '' : $segment ) );
		return array( 'key' => $key, 'type' => $type, 'id' => (int) $id, 'field' => $field, 'source' => $source, 'origin' => $origin, 'segment' => $segment, 'group' => $group );
	}
	private static function string_candidate( $field, $source, $domain = '' ) {
		if ( null === self::$index ) self::$index = (array) get_option( LT_Code_Scanner::INDEX, array() );
		$entry = self::$index[ $field ] ?? null;
		if ( '' !== $domain ) $entry = array( 'd' => $domain ) + (array) $entry;
		$group = LT_Sources::group_of( $field, $entry );
		$origin = 'other' === $group ? __( 'Origin not identified', 'lightweight-translation' ) : LT_Sources::label( $group );
		return self::candidate( 'string', 0, $field, $source, $origin ) + array( 'uncertain' => '' === $domain || 'other' === $group );
	}
	/** A source type describes the content being translated, not the page where it appeared. */
	private static function type_label( $candidate ) {
		$type = (string) ( $candidate['type'] ?? '' );
		if ( 'term' === $type ) {
			$term = get_term( (int) ( $candidate['id'] ?? 0 ) );
			$taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';
			$labels = array(
				'product_cat' => __( 'WooCommerce category', 'lightweight-translation' ),
				'product_tag' => __( 'WooCommerce product tag', 'lightweight-translation' ),
			);
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) return __( 'WooCommerce attribute value', 'lightweight-translation' );
			if ( isset( $labels[ $taxonomy ] ) ) return $labels[ $taxonomy ];
			$object = $taxonomy ? get_taxonomy( $taxonomy ) : false;
			return $object ? sprintf( __( 'Category or tag · %s', 'lightweight-translation' ), $object->labels->singular_name ) : __( 'Category or tag · origin not confirmed', 'lightweight-translation' );
		}
		if ( 'product' === $type || 'product_variation' === $type ) return __( 'WooCommerce product', 'lightweight-translation' );
		if ( in_array( $type, array( 'cms_block', 'woodmart_layout', 'woodmart_slide', 'wd_popup', 'wd_floating_block', 'wd_product_tabs' ), true ) ) return __( 'WoodMart content', 'lightweight-translation' );
		if ( 'string' === $type ) return sprintf( __( 'Interface text · %s', 'lightweight-translation' ), (string) ( $candidate['origin'] ?? __( 'origin not confirmed', 'lightweight-translation' ) ) );
		if ( 'page' === $type ) return __( 'WordPress page', 'lightweight-translation' );
		if ( 'post' === $type ) return __( 'WordPress post', 'lightweight-translation' );
		$post_type = get_post_type_object( $type );
		return $post_type ? $post_type->labels->singular_name : __( 'Content · origin not confirmed', 'lightweight-translation' );
	}

	/** The current page and its recorded embedded blocks provide content provenance. */
	public static function resolve( $text, $ref ) {
		self::prime( array( $text ) );
		$normal = self::normal( $text );
		$candidates = array();
		foreach ( self::$strings[ $normal ] ?? array() as $field => $source ) { $c = self::string_candidate( $field, $source ); $candidates[ $c['key'] ] = $c; }
		$items = array(); $label = ''; $url = '';
		list( $kind, $id ) = array_pad( explode( ':', $ref, 2 ), 2, '' );
		if ( 'post' === $kind && ( $post = get_post( absint( $id ) ) ) ) {
			$items[] = array( 'post:' . $post->post_type, $post->ID );
			$label = $post->post_title;
			$url = get_permalink( $post );
			$map_key = LT_Status_Panel::map_key( $post->ID );
		} elseif ( 'term' === $kind && ( $term = get_term( absint( $id ) ) ) && ! is_wp_error( $term ) ) {
			$items[] = array( 'tax:' . $term->taxonomy, $term->term_id );
			$label = $term->name; $url = get_term_link( $term ); $map_key = 'product-cat';
		} else { $map_key = $ref; $label = LT_Page_Texts::label( $ref ); }
		$map = (array) get_option( LT_Page_Texts::MAP, array() );
		$page = $map[ $map_key ] ?? array();
		// A category name can be printed by a Gutenberg block on the home page without
		// that term being part of the page's saved item list. Look up exact WooCommerce
		// term names across the active catalog so the visible location is not mistaken
		// for the content's origin.
		foreach ( self::$terms[ $normal ] ?? array() as $term ) {
			$c = self::candidate( 'term', $term['id'], 'term_name', $term['name'], self::type_label( array( 'type' => 'term', 'id' => $term['id'] ) ), null, 'tax:' . $term['taxonomy'] );
			$candidates[ $c['key'] ] = $c;
		}
		foreach ( (array) ( $page['keys'] ?? array() ) as $field => $info ) if ( self::normal( $info[0] ?? '' ) === $normal ) { $c = self::string_candidate( $field, $info[0], (string) ( $info[1] ?? '' ) ); $candidates[ $c['key'] ] = $c; }
		foreach ( (array) ( $page['items'] ?? array() ) as $item ) {
			list( $type, $item_id ) = array_pad( explode( ':', $item, 2 ), 2, 0 );
			if ( $type && (int) $item_id ) $items[] = array( 'term' === $type ? 'tax:' . ( get_term( $item_id )->taxonomy ?? '' ) : 'post:' . $type, (int) $item_id );
		}
		foreach ( array_slice( $items, 0, 51 ) as list( $group, $item_id ) ) {
			$item = LT_Content_Catalog::item( $group, $item_id );
			if ( ! $item ) continue;
			$origin = 'WordPress';
			if ( in_array( $item['type'], array( 'product', 'product_variation' ), true ) || 0 === strpos( $group, 'tax:product_' ) || 0 === strpos( $group, 'tax:pa_' ) ) $origin = 'WooCommerce';
			if ( in_array( $item['type'], array( 'cms_block', 'woodmart_layout', 'woodmart_slide', 'wd_popup', 'wd_floating_block', 'wd_product_tabs' ), true ) && LT_Modules::active( 'woodmart' ) ) $origin = 'WoodMart';
			foreach ( $item['fields'] as $field => $source ) {
				if ( 'slug' === $field ) continue;
				if ( self::normal( $source ) === $normal && ! preg_match( '/<|\[/', $source ) ) {
					$c = self::candidate( $item['type'], $item_id, $field, $source, $origin, null, $group ); $candidates[ $c['key'] ] = $c;
					continue;
				}
				foreach ( LT_Segments::texts( $source ) as $segment => $part ) {
					$matches = self::normal( $part ) === $normal;
					if ( ! $matches ) LT_Frontend_Filters::map_html_texts( $part, function( $node ) use ( $normal, &$matches ) { if ( self::normal( $node ) === $normal ) $matches = true; return $node; } );
					if ( $matches ) { $c = self::candidate( $item['type'], $item_id, $field, $source, $origin, $segment, $group ); $candidates[ $c['key'] ] = $c; }
				}
			}
		}
		// When a precise WooCommerce term match exists, do not make the user choose
		// between that real content field and a generic translation-memory string.
		$has_term = (bool) array_filter( $candidates, function( $candidate ) { return 'term' === $candidate['type']; } );
		if ( $has_term ) $candidates = array_filter( $candidates, function( $candidate ) { return 'string' !== $candidate['type']; } );
		// A wording of a Contact Form 7 form: that form is where it is translated (not a widget or page text around it).
		$cf7 = array_filter( $candidates, function( $candidate ) { return 'string' === $candidate['type'] && 'plugin:contact-form-7' === ( self::$index[ $candidate['field'] ]['s'] ?? '' ); } );
		if ( $cf7 ) $candidates = $cf7;
		$origins = array_values( array_unique( array_map( array( __CLASS__, 'type_label' ), array_values( $candidates ) ) ) );
		$uncertain = count( $candidates ) !== 1 || ! empty( reset( $candidates )['uncertain'] );
		$origin = count( $origins ) === 1 ? ( $uncertain && false === strpos( $origins[0], 'origin not confirmed' ) ? sprintf( __( 'Possible content type: %s', 'lightweight-translation' ), $origins[0] ) : $origins[0] ) : ( $origins ? sprintf( __( 'Several possible content types: %s', 'lightweight-translation' ), implode( ', ', $origins ) ) : __( 'Content type not identified', 'lightweight-translation' ) );
		return array( 'origin' => $origin, 'certain' => ! $uncertain && $origin !== __( 'Content type not identified', 'lightweight-translation' ), 'candidates' => array_values( $candidates ), 'page' => $label, 'url' => is_wp_error( $url ) ? '' : $url );
	}

	public function ajax() {
		check_ajax_referer( 'lt_detected_texts', 'nonce' );
		if ( ! current_user_can( lt_capability() ) ) wp_send_json_error( null, 403 );
		$texts = json_decode( wp_unslash( (string) ( $_POST['texts'] ?? '' ) ), true );
		if ( ! is_array( $texts ) ) wp_send_json_error( null, 400 );
		$texts = array_map( function( $text ) { return mb_substr( sanitize_text_field( (string) $text ), 0, 1000 ); }, array_slice( $texts, 0, 80 ) );
		$ref = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
		$language = sanitize_key( $_POST['lang'] ?? '' );
		self::prime( $texts ); $out = array();
		foreach ( $texts as $text ) { $resolved = self::resolve( $text, $ref ); $target = 1 === count( $resolved['candidates'] ) ? $resolved['candidates'][0]['key'] : ''; $out[] = array( 'text' => $text, 'origin' => $resolved['origin'], 'certain' => $resolved['certain'], 'url' => self::url( $text, $ref, $language, $target ) ); }
		wp_send_json_success( $out );
	}

	private static function input() { return array( mb_substr( sanitize_text_field( wp_unslash( $_REQUEST['text'] ?? '' ) ), 0, 1000 ), sanitize_text_field( wp_unslash( $_REQUEST['ref'] ?? '' ) ), sanitize_key( $_REQUEST['lang'] ?? '' ), sanitize_key( $_REQUEST['target'] ?? '' ) ); }
	private static function choose( $resolved, $key ) {
		foreach ( $resolved['candidates'] as $candidate ) if ( $key === $candidate['key'] ) return $candidate;
		return '' === $key && 1 === count( $resolved['candidates'] ) ? $resolved['candidates'][0] : null;
	}
	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		list( $text, $ref, $language, $target ) = self::input();
		$router = LT_Language_Router::instance();
		$languages = array_values( array_diff( $router->languages(), array( $router->default_language() ) ) );
		if ( ! in_array( $language, $languages, true ) ) $language = reset( $languages );
		$resolved = self::resolve( $text, $ref );
		$candidate = self::choose( $resolved, $target );
		echo '<div class="wrap"><h1>' . esc_html__( 'Translate this text', 'lightweight-translation' ) . '</h1><p><a href="' . esc_url( LT_Admin_Navigation::url( 'lt-scan' ) ) . '">← ' . esc_html__( 'Back to the site check', 'lightweight-translation' ) . '</a></p>';
		if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success"><p>' . esc_html__( 'Translation saved. Reload the page on your site to check it.', 'lightweight-translation' ) . '</p></div>';
		if ( ! $text || ! $languages ) { echo '<p>' . esc_html__( 'Choose a text and add a translation language first.', 'lightweight-translation' ) . '</p></div>'; return; }
		echo '<div class="lt-surface"><blockquote>' . esc_html( $text ) . '</blockquote><p><strong>' . esc_html__( 'Found on', 'lightweight-translation' ) . ':</strong> ' . esc_html( $resolved['page'] ?: $ref ) . '</p><p><strong>' . esc_html__( 'Content type', 'lightweight-translation' ) . ':</strong> ' . esc_html( $resolved['origin'] ) . '</p>';
		if ( $resolved['url'] ) echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( LT_Language_Router::instance()->with_language( $language, function() use ( $resolved, $ref ) { if ( 0 === strpos( $ref, 'post:' ) ) return get_permalink( absint( substr( $ref, 5 ) ) ); return $resolved['url']; } ) ) . '">' . esc_html__( 'View page', 'lightweight-translation' ) . '</a></p>';
		if ( ! $candidate && $resolved['candidates'] && 'manual' !== $target ) {
			echo '<h2>' . esc_html__( 'Where would you like to translate it?', 'lightweight-translation' ) . '</h2><p>' . esc_html__( 'This wording is used in several places. Choose the field to update; the origin cannot be determined from the visible text alone.', 'lightweight-translation' ) . '</p><ul>';
			foreach ( $resolved['candidates'] as $c ) echo '<li><a class="button" href="' . esc_url( self::url( $text, $ref, $language, $c['key'] ) ) . '">' . esc_html( self::type_label( $c ) . ' · ' . LT_Status_Panel::label( $c['field'] ) ) . '</a></li>';
			echo '</ul></div></div>'; return;
		}
		if ( ! $candidate ) {
			$candidate = self::candidate( 'string', 0, 'text_' . md5( $text ), $text, __( 'Origin not identified', 'lightweight-translation' ) );
			echo '<p>' . esc_html__( 'The origin is not identified. This fallback replaces this exact wording wherever it appears. For a product or page, prefer translating its own content.', 'lightweight-translation' ) . '</p>';
		}
		$row = LT_Translation_Store::get( $language, $candidate['type'], $candidate['id'], $candidate['field'] );
		$source = null === $candidate['segment'] ? $candidate['source'] : ( LT_Segments::texts( $candidate['source'] )[ $candidate['segment'] ] ?? '' );
		$value = $row && LT_Translation_Store::source_matches( $row, $candidate['source'] ) ? (string) $row->translated_text : '';
		if ( null !== $candidate['segment'] ) $value = '' !== $value ? ( LT_Segments::texts( $value )[ $candidate['segment'] ] ?? '' ) : '';
		if ( '' === $value && $row && null === $candidate['segment'] ) $value = (string) $row->translated_text;
		if ( $row && ! LT_Translation_Store::source_matches( $row, $candidate['source'] ) ) echo '<p class="notice notice-warning inline">' . esc_html__( 'The original has changed. Review the translation before saving.', 'lightweight-translation' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="lt-translate">';
		foreach ( array( 'text' => $text, 'ref' => $ref, 'target' => $target ) as $name => $value_hidden ) echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value_hidden ) . '">';
		echo '<label>' . esc_html__( 'Language', 'lightweight-translation' ) . ' <select name="lang">';
		foreach ( $languages as $lang ) echo '<option value="' . esc_attr( $lang ) . '"' . selected( $lang, $language, false ) . '>' . esc_html( LT_Language_Router::language_label( $lang ) ) . '</option>';
		echo '</select></label> <button class="button">' . esc_html__( 'Change language', 'lightweight-translation' ) . '</button></form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lt_detected_save' );
		foreach ( array( 'action' => 'lt_detected_save', 'text' => $text, 'ref' => $ref, 'lang' => $language, 'target' => $candidate['key'], 'manual' => ! $resolved['candidates'] || 'manual' === $target ? '1' : '' ) as $name => $hidden ) echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $hidden ) . '">';
		echo '<p><label for="lt-original">' . esc_html__( 'Original', 'lightweight-translation' ) . '</label><textarea id="lt-original" class="large-text" rows="4" readonly>' . esc_textarea( $source ) . '</textarea></p><p><label for="lt-translation">' . esc_html( LT_Language_Router::language_label( $language ) ) . '</label><textarea id="lt-translation" name="translation" class="large-text" rows="5" autofocus>' . esc_textarea( $value ) . '</textarea></p>';
		submit_button( __( 'Save translation', 'lightweight-translation' ), 'primary', 'submit', false );
		echo '</form></div></div>';
	}

	public function save() {
		if ( ! current_user_can( lt_capability() ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		check_admin_referer( 'lt_detected_save' );
		list( $text, $ref, $language, $target ) = self::input();
		$router = LT_Language_Router::instance();
		if ( ! $text || $language === $router->default_language() || ! in_array( $language, $router->languages(), true ) ) wp_die( esc_html__( 'Invalid translation language.', 'lightweight-translation' ) );
		$resolved = self::resolve( $text, $ref ); $candidate = self::choose( $resolved, $target );
		if ( ! $candidate && ! $resolved['candidates'] && ! empty( $_POST['manual'] ) ) $candidate = self::candidate( 'string', 0, 'text_' . md5( $text ), $text, '' );
		if ( ! $candidate ) wp_die( esc_html__( 'The original has changed. Reload the translation screen.', 'lightweight-translation' ) );
		if ( $candidate['id'] && ! current_user_can( 'term' === $candidate['type'] ? 'edit_term' : 'edit_post', $candidate['id'] ) ) wp_die( esc_html__( 'Unauthorized', 'lightweight-translation' ) );
		$value = (string) wp_unslash( $_POST['translation'] ?? '' ); $status = '' === trim( $value ) ? 'empty' : 'translated';
		if ( null !== $candidate['segment'] ) {
			$row = LT_Translation_Store::get( $language, $candidate['type'], $candidate['id'], $candidate['field'] );
			$memory = $row ? LT_Segments::memory( $row->source_text, $row->translated_text, true ) : array();
			$parts = LT_Segments::texts( $candidate['source'] ); $translations = array(); $missing = false;
			foreach ( $parts as $i => $part ) { $translations[ $i ] = $i === $candidate['segment'] ? $value : ( LT_Segments::recall( (array) $memory, $part ) ?? '' ); if ( '' === trim( $translations[ $i ] ) ) $missing = true; }
			$value = LT_Segments::rebuild( $candidate['source'], $translations ); $status = $missing ? 'partial' : 'translated';
		}
		if ( $candidate['id'] && preg_match( '/content|description|excerpt/', $candidate['field'] ) && '' !== trim( $value ) ) $value = LT_Content_Merger::merge( $candidate['source'], $value );
		$result = LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => $candidate['type'], 'object_id' => $candidate['id'], 'field_key' => $candidate['field'], 'source_text' => $candidate['source'], 'translated_text' => $value, 'status' => $status ) );
		if ( false === $result ) wp_die( esc_html__( 'The translation could not be saved. Please try again.', 'lightweight-translation' ) );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( $text, $ref, $language, $candidate['key'] ) ) );
		exit;
	}
}

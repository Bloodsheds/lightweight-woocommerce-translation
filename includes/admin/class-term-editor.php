<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translations on the WordPress screens of categories, tags, attribute values and product attributes.
 * A "Language" selector is added at the top of the form (create AND edit): choose a language and the
 * native Name / Slug / Description fields switch to that language. The original is always what
 * WordPress saves; translations are stored by the plugin.
 */
class LT_Term_Editor {
	const TERM_FIELDS = array( 'term_name', 'slug', 'term_description' );

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() {
		add_action( 'init', array( $this, 'register' ), 30 );
	}

	protected function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	public function register() {
		if ( ! $this->languages() ) return;
		$taxonomies = LT_Content_Catalog::public_taxonomies();
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) $taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) continue;
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_fields' ), 99 );
			add_action( $taxonomy . '_add_form_fields', array( $this, 'add_fields' ), 99 );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_term' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save_term' ) );
		}
	}

	/** Hidden storage + configuration read by assets/js/admin.js (initNativeTranslations). */
	protected function container( $prefix, $fields, $values, $nonce_action, $nonce_name ) {
		$router = LT_Language_Router::instance();
		$config = array(
			'default'   => array( 'code' => $router->default_language(), 'label' => LT_Language_Router::flag( $router->default_language() ) . ' ' . LT_Language_Router::language_label( $router->default_language() ) ),
			'languages' => array_map( function( $code ) { return array( 'code' => $code, 'label' => LT_Language_Router::flag( $code ) . ' ' . LT_Language_Router::language_label( $code ) ); }, $this->languages() ),
			'fields'    => $fields,
			'i18n'      => array( 'language' => __( 'Language', 'lightweight-translation' ), 'translating' => __( 'Translating: the original is kept. Empty fields = original text shown to visitors.', 'lightweight-translation' ) ),
		);
		$html = '<div class="lt-native-translations" data-lt-native="' . esc_attr( wp_json_encode( $config ) ) . '">' . wp_nonce_field( $nonce_action, $nonce_name, false, false );
		foreach ( $this->languages() as $language ) foreach ( array_keys( $fields ) as $field ) {
			$html .= '<input type="hidden" data-lt-store="' . esc_attr( $language . '|' . $field ) . '" name="' . esc_attr( $prefix . '[' . $language . '][' . $field . ']' ) . '" value="' . esc_attr( $values[ $language ][ $field ] ?? '' ) . '">';
		}
		return $html . '</div>';
	}

	protected function stored( $type, $id, $fields ) {
		$values = array();
		foreach ( $this->languages() as $language ) foreach ( $fields as $field ) { $row = $id ? LT_Translation_Store::get( $language, $type, $id, $field ) : null; $values[ $language ][ $field ] = $row ? (string) $row->translated_text : ''; }
		return $values;
	}

	public function edit_fields( $term ) {
		$fields = array( 'term_name' => '#name', 'slug' => '#slug', 'term_description' => '#description' );
		echo '<tr class="lt-hidden-row" style="display:none"><td colspan="2">' . $this->container( 'lt_term', $fields, $this->stored( 'term', $term->term_id, self::TERM_FIELDS ), 'lt_save_term', 'lt_term_nonce' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="form-field"><th>' . esc_html__( 'Translations', 'lightweight-translation' ) . '</th><td class="description">' . LT_Translation_Reset::buttons( 'term', $term->term_id ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function add_fields( $taxonomy ) {
		$fields = array( 'term_name' => '#tag-name', 'slug' => '#tag-slug', 'term_description' => '#tag-description' );
		echo '<div style="display:none">' . $this->container( 'lt_term', $fields, $this->stored( 'term', 0, self::TERM_FIELDS ), 'lt_save_term', 'lt_term_nonce' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function save_term( $term_id ) {
		if ( empty( $_POST['lt_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lt_term_nonce'] ) ), 'lt_save_term' ) || empty( $_POST['lt_term'] ) ) return;
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term ) return;
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) ) return;
		$sources = array( 'term_name' => $term->name, 'term_description' => $term->description, 'slug' => $term->slug );
		$this->store( 'term', $term->term_id, (array) wp_unslash( $_POST['lt_term'] ), $sources ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	protected function store( $type, $id, $posted, $sources ) {
		foreach ( $posted as $language => $fields ) {
			$language = sanitize_key( $language );
			if ( ! in_array( $language, $this->languages(), true ) || ! is_array( $fields ) ) continue;
			foreach ( $fields as $field => $value ) {
				$field = sanitize_key( $field );
				if ( ! array_key_exists( $field, $sources ) ) continue;
				$value    = 'slug' === $field ? sanitize_title( (string) $value ) : ( 'term_description' === $field ? (string) $value : sanitize_text_field( (string) $value ) );
				$existing = LT_Translation_Store::get( $language, $type, $id, $field );
				if ( ( ! $existing && '' === trim( $value ) ) || ( $existing && (string) $existing->translated_text === $value ) ) continue; // Nothing new.
				LT_Translation_Store::save( array( 'language_code' => $language, 'object_type' => $type, 'object_id' => $id, 'field_key' => $field, 'source_text' => (string) $sources[ $field ], 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
			}
		}
	}

	/* ---------- Product attributes (Products › Attributes) ---------- */






}

<?php
defined( 'ABSPATH' ) || exit;

class LT_Admin_Product_Metabox {
	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }
	private function __construct() { add_action( 'add_meta_boxes', array( $this, 'box' ) ); add_action( 'save_post', array( $this, 'save' ), 99, 2 ); add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ); add_filter( 'admin_body_class', array( $this, 'body_class' ) ); add_filter( 'wp_insert_post_data', array( $this, 'protect_default_fields' ), 20, 2 ); }
	public function body_class( $classes ) { if ( isset( $_GET['post'] ) || isset( $_GET['post_type'] ) ) $classes .= ' lt-layout-' . sanitize_key( get_option( 'lt_admin_translation_layout', 'both' ) ); return $classes; }
	public function box() { $types = get_post_types( array( 'public' => true ), 'names' ); $types = array_merge( $types, array( 'product_variation', 'portfolio', 'cms_block', 'woodmart_slide', 'woodmart_layout', 'woodmart_size_guide', 'woodmart_sidebar', 'woodmart_woo_lv', 'wd_woo_discounts', 'wd_woo_free_gifts', 'wd_woo_est_del', 'wd_abandoned_cart', 'wd_popup', 'wd_floating_block', 'wd_product_tabs' ) ); foreach ( array_unique( array_filter( $types, 'post_type_exists' ) ) as $screen ) { if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $screen ) ) continue; add_meta_box( 'lt-translations', __( 'Translations', 'lightweight-translation' ), array( $this, 'render' ), $screen, 'normal', 'default' ); } }
	private function fields( $post ) { return LT_Content_Catalog::post_fields( $post ); }
	private function text_only_field( $field ) { return (bool) preg_match( '/(?:content|description|excerpt)/', (string) $field ); }
	public function render( $post ) {
		wp_nonce_field( 'lt_save_translations', 'lt_nonce' );
		$type      = $post->post_type;
		$fields    = $this->fields( $post );
		$languages = array_values( array_diff( LT_Language_Router::instance()->languages(), array( LT_Language_Router::instance()->default_language() ) ) );
		$default_language = LT_Language_Router::instance()->default_language();
		$default_label = LT_Language_Router::flag( $default_language ) . ' ' . LT_Language_Router::language_label( $default_language );
		echo '<div class="lt-editor" data-lt-editor data-lt-layout="' . esc_attr( get_option( 'lt_admin_translation_layout', 'both' ) ) . '" data-lt-default-language="' . esc_attr( $default_language ) . '" data-lt-default-label="' . esc_attr( $default_label ) . '">';
		foreach ( $fields as $field => $source ) echo '<input type="hidden" data-lt-default-field="' . esc_attr( $field ) . '" name="lt_default[' . esc_attr( $field ) . ']" value="' . esc_attr( $source ) . '">';
		echo '<div class="lt-language-tabs" role="tablist">';
		foreach ( $languages as $index => $lang ) echo '<button type="button" class="lt-language-tab ' . ( 0 === $index ? 'is-active' : '' ) . '" data-lt-tab="' . esc_attr( $lang ) . '" role="tab" aria-selected="' . ( 0 === $index ? 'true' : 'false' ) . '">' . esc_html( LT_Language_Router::flag( $lang ) . ' ' . strtoupper( $lang ) ) . '</button>';
		echo '</div><p class="description">' . esc_html__( 'Select a language to edit its translation. The original text is shown beside each field.', 'lightweight-translation' ) . '</p>';
		if ( 'auto-draft' !== $post->post_status ) echo '<p class="description lt-reset-row">' . LT_Translation_Reset::buttons( $type, $post->ID ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $languages as $index => $lang ) {
			echo '<section class="lt-language-panel ' . ( 0 === $index ? 'is-active' : '' ) . '" data-lt-panel="' . esc_attr( $lang ) . '" role="tabpanel">';
			foreach ( $fields as $field => $source ) {
				$row   = LT_Translation_Store::get( $lang, $type, $post->ID, $field );
				$value = $row ? (string) $row->translated_text : '';
				if ( '' !== $value && $this->text_only_field( $field ) ) $value = LT_Content_Merger::from_row( (string) $source, $row );
				$input = 'slug' === $field ? '<input class="widefat" type="text" name="lt_trans[' . esc_attr( $lang ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '">' : '<textarea class="widefat" name="lt_trans[' . esc_attr( $lang ) . '][' . esc_attr( $field ) . ']">' . esc_textarea( $value ) . '</textarea>';
				echo '<div class="lt-field" data-lt-field="' . esc_attr( $field ) . '" data-lt-language="' . esc_attr( $lang ) . '"><div class="lt-source"><strong>' . esc_html( LT_Status_Panel::label( $field ) ) . '</strong><div>' . ( 'slug' === $field ? esc_html( $source ) : wp_kses_post( wpautop( $source ) ) ) . '</div></div><div class="lt-target">' . $input . '<input type="hidden" data-lt-changed name="lt_changed[' . esc_attr( $lang ) . '][' . esc_attr( $field ) . ']" value="0"></div></div>';
			}
			echo '</section>';
		}
		echo '</div>';
	}
	public function protect_default_fields( $data, $postarr ) {
		if ( empty( $_POST['lt_default'] ) || empty( $_POST['lt_native_secondary'] ) ) return $data;
		// Same checks as the translation box itself: its nonce and the right to edit this post.
		if ( empty( $_POST['lt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lt_nonce'] ) ), 'lt_save_translations' ) ) return $data;
		$post_id = absint( $postarr['ID'] ?? 0 );
		if ( $post_id ? ! current_user_can( 'edit_post', $post_id ) : ! current_user_can( 'edit_posts' ) ) return $data;
		$defaults  = (array) wp_unslash( $_POST['lt_default'] );
		$secondary = (array) wp_unslash( $_POST['lt_native_secondary'] );
		// This filter runs after WordPress cleaned the post: clean the restored originals the same way.
		$unfiltered = current_user_can( 'unfiltered_html' );
		$clean      = function( $value, $filter ) use ( $unfiltered ) { $value = wp_slash( is_string( $value ) ? $value : '' ); return $unfiltered ? $value : $filter( $value ); };
		if ( ! empty( $secondary['title'] ) && isset( $defaults['title'] ) ) $data['post_title'] = $clean( $defaults['title'], 'wp_filter_kses' );
		if ( ! empty( $secondary['content'] ) && isset( $defaults['content'] ) ) $data['post_content'] = $clean( $defaults['content'], 'wp_filter_post_kses' );
		if ( ( ! empty( $secondary['short_description'] ) || ! empty( $secondary['excerpt'] ) ) && isset( $defaults[ ! empty( $secondary['short_description'] ) ? 'short_description' : 'excerpt' ] ) ) $data['post_excerpt'] = $clean( $defaults[ ! empty( $secondary['short_description'] ) ? 'short_description' : 'excerpt' ], 'wp_filter_post_kses' );
		return $data;
	}
	private function current_post_source( $post, $field ) {
		if ( 'title' === $field ) return $post->post_title;
		if ( 'content' === $field ) return $post->post_content;
		if ( in_array( $field, array( 'excerpt', 'short_description' ), true ) ) return $post->post_excerpt;
		if ( 'slug' === $field ) return $post->post_name;
		$fields = $this->fields( $post );
		return isset( $fields[ $field ] ) ? $fields[ $field ] : '';
	}
	public function save( $post_id, $post ) {
		if ( empty( $_POST['lt_nonce'] ) || ! wp_verify_nonce( $_POST['lt_nonce'], 'lt_save_translations' ) || defined( 'DOING_AUTOSAVE' ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || empty( $_POST['lt_trans'] ) ) return;
		$languages = LT_Language_Router::instance()->languages();
		foreach ( (array) $_POST['lt_trans'] as $lang => $fields ) {
			$lang = sanitize_key( $lang );
			if ( ! in_array( $lang, $languages, true ) || $lang === LT_Language_Router::instance()->default_language() || ! is_array( $fields ) ) continue;
			foreach ( $fields as $field => $value ) {
			$value    = 'slug' === $field ? sanitize_title( wp_unslash( $value ) ) : wp_unslash( $value );
				$source   = (string) $this->current_post_source( $post, $field );
				if ( $this->text_only_field( $field ) ) {
					$value = LT_Content_Merger::merge( $source, (string) $value );
					if ( $value === $source ) $value = '';
				}
			$existing = LT_Translation_Store::get( $lang, $post->post_type, $post_id, $field );
			$changed  = ! empty( $_POST['lt_changed'][ $lang ][ $field ] ) || ! $existing || (string) $existing->translated_text !== (string) $value;
			if ( ! $changed ) continue;
				LT_Translation_Store::save( array( 'language_code' => $lang, 'object_type' => $post->post_type, 'object_id' => $post_id, 'field_key' => $field, 'source_text' => $source, 'translated_text' => $value, 'status' => '' === trim( $value ) ? 'empty' : 'translated' ) );
			}
		}
	}
	public function assets( $hook ) { if ( in_array( $hook, array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php', 'toplevel_page_lt-settings', 'nav-menus.php', 'product_page_product_attributes' ), true ) ) { wp_enqueue_style( 'lt-admin', plugins_url( 'assets/css/admin.css', LT_FILE ), array(), LT_VERSION ); wp_enqueue_script( 'lt-admin', plugins_url( 'assets/js/admin.js', LT_FILE ), array(), LT_VERSION, true ); wp_localize_script( 'lt-admin', 'ltAdminI18n', array( 'language' => __( 'Language', 'lightweight-translation' ), 'fallback' => __( 'Using the original language', 'lightweight-translation' ) ) ); } }
}
